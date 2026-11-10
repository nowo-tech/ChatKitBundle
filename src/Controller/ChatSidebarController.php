<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Controller;

use Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface;
use Nowo\ChatKitBundle\Http\ChatApiException;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Sidebar\ChatSidebar;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * 1.25: the host sections of the chat sidebar (ChatSidebarSectionProviderInterface) for the signed-in user. Same rules
 * as the rest of the chat API: signed in (401), the read budget (429), never cached. A conversation row the viewer may
 * not read is left out (ChatSidebar).
 */
#[Route('/api', name: 'nowo_chat_api_')]
final class ChatSidebarController extends AbstractController
{
    /**
     * Builds the controller.
     *
     * @param ChatSidebar $sidebar The host sections
     * @param ChatAccess $access Reachability of the user
     * @param RateLimiterFactoryInterface $readLimiter Budget of the reads
     * @param CurrentChatUserResolverInterface|null $currentUser The signed-in user's chat participant; null: the security user when it implements ChatUserInterface
     */
    public function __construct(
        private readonly ChatSidebar $sidebar,
        private readonly ChatAccess $access,
        #[Autowire(service: 'limiter.nowo_chat_read')]
        private readonly RateLimiterFactoryInterface $readLimiter,
        private readonly ?CurrentChatUserResolverInterface $currentUser = null,
    ) {
    }

    /**
     * The viewer's host sections, ordered by position (`{sections: []}` without a provider).
     *
     * @param Request $request The request (its origin bounds the dialog URLs)
     *
     * @return JsonResponse `{sections: list<{key, label, position, collapsible, collapsedByDefault, items, actions}>}`
     */
    #[Route('/sidebar', name: 'sidebar', methods: ['GET'])]
    public function sections(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $user = $this->currentUser instanceof CurrentChatUserResolverInterface ? $this->currentUser->resolve($user) : $user;
        if (!$user instanceof ChatUserInterface || !$this->access->isReachable($user)) {
            throw new ChatApiException('unauthorized', Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->readLimiter->create('nowo_chat_read_' . $user->getChatPublicId())->consume()->isAccepted()) {
            throw new ChatApiException('rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        }
        $response = new JsonResponse(['sections' => $this->sidebar->view($user, $request->getSchemeAndHttpHost())]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
