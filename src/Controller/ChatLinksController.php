<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Controller;

use InvalidArgumentException;
use JsonException;
use Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Entity\ChatMessageLink;
use Nowo\ChatKitBundle\Http\ChatApiException;
use Nowo\ChatKitBundle\Link\ChatLinks;
use Nowo\ChatKitBundle\Model\ChatLinkProvider;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationLinkRepository;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function assert;
use function count;
use function is_array;
use function is_string;
use function json_decode;
use function mb_substr;
use function strlen;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * 1.19: JSON API of the links of a conversation — its pinned links (list, add, change, remove) and the "Links" panel
 * (pinned links and the links shared in its messages, paged, with search and a provider filter). Same rules as the rest
 * of the chat API: members only (unknown, foreign or disabled → 404, never 403, so uuids cannot be probed), the
 * `nowo_chat` CSRF header on every change, the read / action rate limits, never cached. Changing the pinned links needs
 * write access to the conversation and ChatAccess::canManageLinks() (403 `links_forbidden`).
 */
#[Route('/api', name: 'nowo_chat_api_')]
final class ChatLinksController extends AbstractController
{
    /** Shared links per page of the panel. */
    public const int PAGE_SIZE            = 30;
    private const int MAX_JSON_BODY_BYTES = 8_192;

    /**
     * Builds the controller.
     *
     * @param ChatLinks $links Pinned links and the link index
     * @param ChatAccess $access Who manages the pinned links
     * @param ChatConversationRepository $conversations Finds the conversation by uuid
     * @param ChatConversationLinkRepository $pinned Finds a pinned link of the conversation
     * @param RateLimiterFactoryInterface $actionLimiter Budget of the changes
     * @param RateLimiterFactoryInterface $readLimiter Budget of the reads
     * @param CurrentChatUserResolverInterface|null $currentUser 1.24: the signed-in user's chat participant; null: the security user when it implements ChatUserInterface
     */
    public function __construct(
        private readonly ChatLinks $links,
        private readonly ChatAccess $access,
        private readonly ChatConversationRepository $conversations,
        private readonly ChatConversationLinkRepository $pinned,
        #[Autowire(service: 'limiter.nowo_chat_action')]
        private readonly RateLimiterFactoryInterface $actionLimiter,
        #[Autowire(service: 'limiter.nowo_chat_read')]
        private readonly RateLimiterFactoryInterface $readLimiter,
        private readonly ?CurrentChatUserResolverInterface $currentUser = null,
    ) {
    }

    /**
     * The pinned links of the conversation (oldest first), whether the caller may manage them and the limit.
     *
     * @param string $uuid Conversation uuid
     *
     * @return JsonResponse `{links, canManage, max}`
     */
    #[Route('/conversations/{uuid}/pinned-links', name: 'pinned_links', requirements: ['uuid' => ChatApiController::UUID], methods: ['GET'])]
    public function list(string $uuid): JsonResponse
    {
        $user = $this->reader();

        return $this->json($this->state($this->find($uuid), $user));
    }

    /**
     * Pins a link (`{url, title?}`); 403 `links_forbidden`, 422 `invalid_link_url`, `invalid_link_title` or
     * `too_many_links`.
     *
     * @param string $uuid Conversation uuid
     * @param Request $request JSON body `{url, title?}`
     *
     * @return JsonResponse `{link, links, canManage, max}` with status 201
     */
    #[Route('/conversations/{uuid}/pinned-links', name: 'pinned_link_create', requirements: ['uuid' => ChatApiController::UUID], methods: ['POST'])]
    public function create(string $uuid, Request $request): JsonResponse
    {
        $user         = $this->writer($request);
        $conversation = $this->manageable($uuid, $user);
        $payload      = $this->payload($request);
        try {
            // @igor-ignore - a database write through a stateless service, not worker state.
            $link = $this->links->add($user, $conversation, $this->text($payload, 'url'), $this->text($payload, 'title'));
        } catch (InvalidArgumentException $exception) {
            throw new ChatApiException($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $exception);
        }

        return $this->json(['link' => $this->links->presentPinned($link)] + $this->state($conversation, $user), Response::HTTP_CREATED);
    }

    /**
     * Changes (`PATCH {url, title?}`) or removes (`DELETE`) a pinned link of the conversation (another's → 404).
     *
     * @param string $uuid Conversation uuid
     * @param string $linkUuid Pinned link uuid
     * @param Request $request The request (CSRF header; JSON body for PATCH)
     *
     * @return JsonResponse `{link, links, canManage, max}` (PATCH) or `{links, canManage, max}` (DELETE)
     */
    #[Route('/conversations/{uuid}/pinned-links/{linkUuid}', name: 'pinned_link', requirements: ['uuid' => ChatApiController::UUID, 'linkUuid' => ChatApiController::UUID], methods: ['PATCH', 'DELETE'])]
    public function change(string $uuid, string $linkUuid, Request $request): JsonResponse
    {
        $user         = $this->writer($request);
        $conversation = $this->manageable($uuid, $user);
        $link         = $this->pinned->findOneInConversation($conversation, $linkUuid);
        $this->deny(!$link instanceof ChatConversationLink, 'not_found', Response::HTTP_NOT_FOUND);
        assert($link instanceof ChatConversationLink);
        if ($request->isMethod('DELETE')) {
            // @igor-ignore - a database write through a stateless service, not worker state.
            $this->links->remove($user, $link);

            return $this->json($this->state($conversation, $user));
        }
        $payload = $this->payload($request);
        try {
            $this->links->update($user, $link, $this->text($payload, 'url'), $this->text($payload, 'title'));
        } catch (InvalidArgumentException $exception) {
            throw new ChatApiException($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $exception);
        }

        return $this->json(['link' => $this->links->presentPinned($link)] + $this->state($conversation, $user));
    }

    /**
     * The "Links" panel: the pinned links (first page only) and one page of the links shared in the messages, newest
     * first, from the link index; `q` searches the title and the URL (at most 80 characters), `provider` filters by a
     * provider key or `other`, `before` is the previous page's `next`.
     *
     * @param string $uuid Conversation uuid
     * @param Request $request The request (query `q`, `provider`, `before`)
     *
     * @return JsonResponse `{pinned, links, hasMore, next, providers, canManage}`
     */
    #[Route('/conversations/{uuid}/links', name: 'links', requirements: ['uuid' => ChatApiController::UUID], methods: ['GET'])]
    public function panel(string $uuid, Request $request): JsonResponse
    {
        $user         = $this->reader();
        $conversation = $this->find($uuid);
        $query        = mb_substr(trim((string) $request->query->get('q', '')), 0, 80);
        $provider     = mb_substr(trim((string) $request->query->get('provider', '')), 0, 32);
        $before       = $request->query->getInt('before') ?: null;
        $query        = $query === '' ? null : $query;
        $provider     = $provider === '' ? null : $provider;
        $rows         = $this->links->shared($conversation, $query, $provider, $before, self::PAGE_SIZE + 1);
        $hasMore      = count($rows) > self::PAGE_SIZE;
        $rows         = array_slice($rows, 0, self::PAGE_SIZE);
        $last         = $rows === [] ? null : $rows[count($rows) - 1];

        return $this->json([
            'pinned' => $before !== null ? [] : array_map(
                $this->links->presentPinned(...),
                array_values(array_filter($this->links->pinned($conversation), fn (ChatConversationLink $link): bool => $this->links->matches($link, $query, $provider))),
            ),
            'links'     => array_map(fn (ChatMessageLink $link): array => $this->links->presentShared($link), $rows),
            'hasMore'   => $hasMore,
            'next'      => $hasMore && $last instanceof ChatMessageLink ? $last->getId() : null,
            'providers' => array_map(static fn (ChatLinkProvider $known): array => $known->toArray(), $this->links->urls()->providers()),
            'canManage' => $this->canManage($conversation, $user),
        ]);
    }

    /**
     * Chat data is private and changes constantly: never cached.
     *
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $context
     */
    protected function json(mixed $data, int $status = 200, array $headers = [], array $context = []): JsonResponse
    {
        $response = new JsonResponse($data, $status, $headers);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * @return array{links: list<array<string, mixed>>, canManage: bool, max: int}
     */
    private function state(ChatConversation $conversation, ChatUserInterface $user): array
    {
        return [
            'links'     => array_map($this->links->presentPinned(...), $this->links->pinned($conversation)),
            'canManage' => $this->canManage($conversation, $user),
            'max'       => $this->links->maxPinned(),
        ];
    }

    /** Write access to the conversation and the right to manage its pinned links. */
    private function canManage(ChatConversation $conversation, ChatUserInterface $user): bool
    {
        return $this->isGranted(ChatVoter::SEND, $conversation) && $this->access->canManageLinks($conversation, $user, $this->links->channelMembersManage());
    }

    /** A conversation the caller may read and whose pinned links they manage (403 `links_forbidden` otherwise). */
    private function manageable(string $uuid, ChatUserInterface $user): ChatConversation
    {
        $conversation = $this->find($uuid);
        $this->deny(!$this->canManage($conversation, $user), 'links_forbidden', Response::HTTP_FORBIDDEN);

        return $conversation;
    }

    /** Unknown, foreign or the feature off → 404. */
    private function find(string $uuid): ChatConversation
    {
        $conversation = $this->links->isEnabled() ? $this->conversations->findOneByUuid($uuid) : null;
        if (!$conversation instanceof ChatConversation || !$this->isGranted(ChatVoter::VIEW, $conversation)) {
            throw new ChatApiException('not_found', Response::HTTP_NOT_FOUND);
        }

        return $conversation;
    }

    private function chatUser(): ChatUserInterface
    {
        $user = $this->getUser();
        $user = $this->currentUser instanceof CurrentChatUserResolverInterface ? $this->currentUser->resolve($user) : $user;
        $this->deny(!$user instanceof ChatUserInterface || !$this->access->isReachable($user), 'unauthorized', Response::HTTP_UNAUTHORIZED);
        assert($user instanceof ChatUserInterface);

        return $user;
    }

    /** Signed-in user within the read budget. */
    private function reader(): ChatUserInterface
    {
        $user = $this->chatUser();
        $this->deny(!$this->readLimiter->create('nowo_chat_read_' . $user->getChatPublicId())->consume()->isAccepted(), 'rate_limited', Response::HTTP_TOO_MANY_REQUESTS);

        return $user;
    }

    /** Signed-in user with a valid CSRF header, within the action budget. */
    private function writer(Request $request): ChatUserInterface
    {
        $user = $this->chatUser();
        $this->deny(!$this->isCsrfTokenValid(ChatApiController::CSRF_ID, (string) $request->headers->get('X-CSRF-TOKEN', '')), 'invalid_csrf', Response::HTTP_FORBIDDEN);
        $this->deny(!$this->actionLimiter->create('nowo_chat_action_' . $user->getChatPublicId())->consume()->isAccepted(), 'rate_limited', Response::HTTP_TOO_MANY_REQUESTS);

        return $user;
    }

    private function deny(bool $condition, string $error, int $status): void
    {
        if ($condition) {
            throw new ChatApiException($error, $status);
        }
    }

    /**
     * A string field of the JSON body ('' when missing or not a string).
     *
     * @param array<string, mixed> $payload
     */
    private function text(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * JSON body, bounded (oversized or malformed bodies count as empty).
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $content = $request->getContent();
        if (strlen($content) > self::MAX_JSON_BODY_BYTES) {
            return [];
        }
        try {
            $decoded = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
