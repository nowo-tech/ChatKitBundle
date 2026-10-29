<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Controller;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\DependencyInjection\Configuration;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Form\ChatPageMessageType;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\View\ChatPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Full-page chat (`/chat`, `/chat/{uuid}`): the dock's "open in a page", the small-screen view and the no-JS
 * fallback — server-rendered list and thread, plain form POST + redirect (PRG). Same permissions as the API.
 */
final class ChatPageController extends AbstractController
{
    public const string CSRF_ID = 'nowo_chat_page';

    /** Deprecated 1.x template of the page, used while only the deprecated `page_layout` is configured. */
    public const string LEGACY_TEMPLATE = '@NowoChatKitBundle/page_legacy.html.twig';
    private const int LIST_LIMIT        = 50;
    private const int PAGE_SIZE         = 50;

    /**
     * Builds the page controller from the chat services, repositories, the send rate limiter, the CSRF token
     * manager (header token of the mark-read call) and the layout.
     *
     * @param string|null $layout Deprecated 1.x layout (`page_layout`): when set and `web_ui.layout_template` is not,
     *                            the page keeps its 1.x template (`page_legacy.html.twig`)
     * @param string|null $webUiLayout Layout of the page (`web_ui.layout_template`); null = nowo_ui_kit.layouts.admin
     * @param string $pageTemplate Template of the page (`web_ui.templates.page`)
     * @param ChatUserSettingsRepository|null $settings The viewer's own status (the page's status button)
     * @param ChatPresence|null $presence Status of the person of the open direct conversation
     * @param ClockInterface $clock Clock for status expiry
     * @param ChatParticipantRepository|null $participants Conversations the viewer closed (1.10)
     */
    public function __construct(
        private readonly ChatService $chat,
        private readonly ChatPresenter $presenter,
        private readonly ChatAccess $access,
        private readonly ChatChannelProviderInterface $channels,
        private readonly ChatConversationRepository $conversations,
        private readonly ChatMessageRepository $messages,
        #[Autowire(service: 'limiter.nowo_chat_send')]
        private readonly RateLimiterFactoryInterface $sendLimiter,
        private readonly CsrfTokenManagerInterface $csrf,
        #[Autowire(param: 'nowo_chat_kit.page_layout')]
        private readonly ?string $layout = null,
        #[Autowire(param: 'nowo_chat_kit.web_ui.layout_template')]
        private readonly ?string $webUiLayout = null,
        #[Autowire(param: 'nowo_chat_kit.web_ui.templates.page')]
        private readonly string $pageTemplate = Configuration::PAGE_TEMPLATE,
        private readonly ?ChatReferences $references = null,
        private readonly ?ChatUserSettingsRepository $settings = null,
        private readonly ?ChatPresence $presence = null,
        private readonly ClockInterface $clock = new Clock(),
        private readonly ?ChatParticipantRepository $participants = null,
    ) {
    }

    /**
     * Renders the full-page conversation list without an open conversation.
     *
     * @return Response The rendered page
     */
    #[Route('', name: 'nowo_chat_page', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->chatUser();

        return $this->render($this->template(), $this->context($user, null, $request->query->getBoolean('archived')));
    }

    /**
     * Renders one conversation (latest page of messages, composer); 404 when not visible. A GET never changes
     * state (REQ-SEC-005): the read cursor moves through the CSRF-protected `POST …/read` API call that the page
     * script makes on load (`read` context), or when the user sends a message from the page.
     *
     * @param string $uuid Conversation uuid
     *
     * @return Response The rendered page
     */
    #[Route('/{uuid}', name: 'nowo_chat_page_conversation', requirements: ['uuid' => ChatApiController::UUID], methods: ['GET'])]
    public function conversation(string $uuid): Response
    {
        $user         = $this->chatUser();
        $conversation = $this->find($uuid);
        $page         = $this->messages->findPage($conversation, self::PAGE_SIZE);
        $last         = end($page);
        // The page's #references for this reader in one resolve() per type.
        $this->references?->prime($user, $page);

        return $this->render($this->template(), [
            'messages' => array_map(fn (ChatMessage $message): array => $this->presenter->message($message, $user), $page),
            'read'     => $last instanceof ChatMessage ? [
                'url'       => $this->generateUrl('nowo_chat_api_read', ['uuid' => $uuid]),
                'messageId' => (int) $last->getId(),
                'csrf'      => $this->csrf->getToken(ChatApiController::CSRF_ID)->getValue(),
            ] : null,
            'can_send' => $this->isGranted(ChatVoter::SEND, $conversation),
            'composer' => $this->composer($uuid)->createView(),
        ] + $this->context($user, $conversation));
    }

    /**
     * Handles the composer form POST and redirects back to the conversation (PRG) with an `error` code on failure
     * (`error`, `read_only`, `rate_limited`, `too_long`).
     *
     * @param string $uuid Conversation uuid
     * @param Request $request The form submission
     *
     * @return RedirectResponse 303 redirect to the conversation page
     */
    #[Route('/{uuid}', name: 'nowo_chat_page_send', requirements: ['uuid' => ChatApiController::UUID], methods: ['POST'])]
    public function send(string $uuid, Request $request): RedirectResponse
    {
        $user         = $this->chatUser();
        $conversation = $this->find($uuid);
        $error        = null;
        $form         = $this->composer($uuid);
        $form->handleRequest($request);
        /** @var array{_token?: string} $posted */
        $posted = $request->request->all($form->getName());
        // Fail-closed even when the application disables form CSRF: isCsrfTokenValid() throws without CSRF support.
        if (!$form->isSubmitted() || !$form->isValid() || !$this->isCsrfTokenValid(self::CSRF_ID, (string) ($posted['_token'] ?? ''))) {
            $error = 'error';
        } elseif (!$this->isGranted(ChatVoter::SEND, $conversation)) {
            $error = 'read_only';
        } elseif (!$this->sendLimiter->create('nowo_chat_send_' . $user->getChatPublicId())->consume()->isAccepted()) {
            $error = 'rate_limited';
        } else {
            try {
                $this->chat->send($user, $conversation, (string) $form->get('body')->getData());
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage() === 'too_long' ? 'too_long' : 'error';
            }
        }

        return $this->redirectToRoute('nowo_chat_page_conversation', ['uuid' => $uuid, 'error' => $error, '_fragment' => 'nowo-chat-latest'], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function composer(string $uuid): FormInterface
    {
        return $this->createForm(ChatPageMessageType::class, null, [
            'action' => $this->generateUrl('nowo_chat_page_send', ['uuid' => $uuid]),
        ]);
    }

    /**
     * The 1.x template while a deprecated `page_layout` is the only layout configured (its layout renders the 1.x
     * `title` / `stylesheets` / `javascripts` / `body` blocks, not the UiKit canonical ones).
     */
    private function template(): string
    {
        return $this->layout !== null && $this->webUiLayout === null ? self::LEGACY_TEMPLATE : $this->pageTemplate;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(ChatUserInterface $user, ?ChatConversation $current, bool $archived = false): array
    {
        $channelKeys = array_map(static fn (ChatChannel $channel): string => $channel->key, $this->channels->channelsFor($user));
        $list        = array_values(array_filter(
            $this->conversations->findListForUser($user, $channelKeys, $this->access->isSupportAgent($user), self::LIST_LIMIT),
            fn (ChatConversation $conversation): bool => $this->access->canView($conversation, $user),
        ));
        $ids = array_map(static fn (ChatConversation $conversation): int => (int) $conversation->getId(), $list);
        // 1.10: conversations the viewer closed are listed apart (`?archived=1`); the open one always shows.
        $closed = [];
        $now    = $this->clock->now();
        foreach ($this->participants?->findForUser($user, $ids) ?? [] as $id => $participant) {
            if ($participant->isArchived($now)) {
                $closed[$id] = true;
            }
        }
        $list   = array_values(array_filter($list, static fn (ChatConversation $conversation): bool => $conversation === $current || isset($closed[(int) $conversation->getId()]) === $archived));
        $ids    = array_map(static fn (ChatConversation $conversation): int => (int) $conversation->getId(), $list);
        $unread = $this->messages->countUnreadByConversation((int) $user->getId(), $ids);
        $latest = $this->messages->findLatestByConversationIds($ids);
        $rows   = [];
        foreach ($list as $conversation) {
            $id = (int) $conversation->getId();
            if ($conversation->isDirect() && !isset($latest[$id]) && $conversation !== $current) {
                continue;
            }
            // The open conversation is being read: the page script moves the cursor once it loads.
            $rows[] = $this->summary($conversation, $user, $conversation === $current ? 0 : $unread[$id] ?? 0, $latest[$id] ?? null);
        }

        $summary = $current instanceof ChatConversation ? $this->summary($current, $user, 0, null, true) : null;

        return [
            // `layout`: the page layout (web_ui.layout_template, else the deprecated page_layout); null = UiKit layout.
            'layout'        => $this->webUiLayout ?? $this->layout,
            'header'        => ChatPage::header(),
            'bundle'        => ChatPage::BUNDLE,
            'conversations' => $rows,
            'current'       => $summary,
            'messages'      => [],
            'read'          => null,
            'can_send'      => false,
            // 1.10: the viewer's own status (the status button opens the dock's status menu).
            'my_status' => $this->settings?->forUser($user)->statusView($this->clock->now()),
            'archived'  => $archived,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ChatConversation $conversation, ChatUserInterface $user, int $unread = 0, ?ChatMessage $last = null, bool $withStatus = false): array
    {
        $peer   = $this->access->directPeer($conversation, $user);
        $status = null;
        if ($withStatus && $peer instanceof ChatUserInterface && $this->presence instanceof ChatPresence) {
            $status = ChatPresence::forViewer(
                $this->presence->statusOf([$peer])[$peer->getChatPublicId()] ?? null,
                $this->settings?->forUser($user)->isPresenceVisible() ?? true,
            );
        }

        return $this->presenter->conversation(
            $conversation,
            $user,
            $peer,
            $conversation->isChannel() ? $this->access->channel((string) $conversation->getChannelKey()) : null,
            $this->isGranted(ChatVoter::SEND, $conversation),
            $unread,
            $last,
            $this->access->isSupportAgent($user),
            $status !== null && $status['status'] !== 'offline',
            $status,
        );
    }

    private function chatUser(): ChatUserInterface
    {
        $user = $this->getUser();
        if (!$user instanceof ChatUserInterface || !$this->access->isReachable($user)) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function find(string $uuid): ChatConversation
    {
        $conversation = $this->conversations->findOneByUuid($uuid);
        if (!$conversation instanceof ChatConversation || !$this->isGranted(ChatVoter::VIEW, $conversation)) {
            throw $this->createNotFoundException();
        }

        return $conversation;
    }
}
