<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Controller;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\DependencyInjection\Configuration;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatSavedMessage;
use Nowo\ChatKitBundle\Form\ChatPageMessageType;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Port\ChatChannelPort;
use Nowo\ChatKitBundle\Port\ChatCurrentUserPort;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\ChatKitBundle\Service\ChatMessageActions;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatReferences;
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

    private const int LIST_LIMIT = 50;
    private const int PAGE_SIZE  = 50;

    /**
     * Builds the page controller from the chat services, repositories, the send rate limiter, the CSRF token
     * manager (header token of the mark-read call) and the layout.
     *
     * @param string|null $layout @deprecated since 2.0, ignored (the 1.x `page_layout`, removed in 2.0); removed in 3.0
     * @param string|null $webUiLayout Layout of the page (`web_ui.layout_template`); null = nowo_ui_kit.layouts.admin
     * @param string $pageTemplate Template of the page (`web_ui.templates.page`)
     * @param ChatUserSettingsRepository|null $settings The viewer's own status (the page's status button)
     * @param ChatPresence|null $presence Status of the person of the open direct conversation
     * @param ClockInterface $clock Clock for status expiry
     * @param ChatParticipantRepository|null $participants Conversations the viewer closed (1.10)
     * @param bool $assets Whether the page prints the nowo-chat.css and nowo-chat.js tags (`web_ui.assets`, 1.21)
     * @param bool $fullHeight Whether the page fills the host content area (`web_ui.page.full_height`, 1.21)
     * @param ChatCurrentUserPort|null $currentUser 1.24: the signed-in user's chat participant; null: the security user when it implements ChatUserInterface
     * @param ChatMessageActions|null $menu 1.35: the Saved list (`message_menu.save`); null: no Saved page
     */
    public function __construct(
        private readonly ChatServiceInterface $chat,
        private readonly ChatPresenter $presenter,
        private readonly ChatAccess $access,
        private readonly ChatChannelPort $channels,
        private readonly ChatConversationRepository $conversations,
        private readonly ChatMessageRepository $messages,
        #[Autowire(service: 'limiter.nowo_chat_send')]
        private readonly RateLimiterFactoryInterface $sendLimiter,
        private readonly CsrfTokenManagerInterface $csrf,
        ?string $layout = null,
        #[Autowire(param: 'nowo_chat_kit.web_ui.layout_template')]
        private readonly ?string $webUiLayout = null,
        #[Autowire(param: 'nowo_chat_kit.web_ui.templates.page')]
        private readonly string $pageTemplate = Configuration::PAGE_TEMPLATE,
        private readonly ?ChatReferences $references = null,
        private readonly ?ChatUserSettingsRepository $settings = null,
        private readonly ?ChatPresence $presence = null,
        private readonly ClockInterface $clock = new Clock(),
        private readonly ?ChatParticipantRepository $participants = null,
        #[Autowire(param: 'nowo_chat_kit.web_ui.assets')]
        private readonly bool $assets = true,
        #[Autowire(param: 'nowo_chat_kit.web_ui.page.full_height')]
        private readonly bool $fullHeight = false,
        private readonly ?ChatCurrentUserPort $currentUser = null,
        private readonly ?ChatMessageActions $menu = null,
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

        return $this->render($this->pageTemplate, $this->context($user, null, $request->query->getBoolean('archived')));
    }

    /**
     * 1.35: the viewer's Saved list (`message_menu.save`), newest save first, `?before=` for the next page: only messages
     * the viewer may still read (hidden, not removed, while access is lost), never deleted ones. Works without
     * JavaScript (each entry links to its message); with it, "Remove" unsaves in place. 404 when the item is off.
     *
     * @param Request $request The request (query `before`)
     *
     * @return Response The rendered page
     */
    #[Route('/saved', name: 'nowo_chat_page_saved', methods: ['GET'])]
    public function saved(Request $request): Response
    {
        $user = $this->chatUser();
        if (!$this->menu instanceof ChatMessageActions || !$this->menu->saveEnabled()) {
            throw $this->createNotFoundException();
        }
        $page     = $this->menu->savedPage($user, $request->query->getInt('before') ?: null);
        $messages = array_map(static fn (ChatSavedMessage $row): ChatMessage => $row->getMessage(), $page['rows']);
        $this->references?->prime($user, $messages);
        $this->presenter->primeAvatars(array_map(static fn (ChatMessage $message): ?ChatUserInterface => $message->getAuthor(), $messages));
        $this->presenter->primeAuthorBadges($messages);
        $titles = [];
        $items  = [];
        foreach ($page['rows'] as $row) {
            $message      = $row->getMessage();
            $conversation = $message->getConversation();
            $uuid         = $conversation->getUuid();
            $titles[$uuid] ??= $this->summary($conversation, $user)['title'];
            $items[] = [
                'saved_at'     => $row->getSavedAt(),
                'conversation' => ['uuid' => $uuid, 'title' => $titles[$uuid], 'kind' => $conversation->getKind()->value],
                'message'      => $this->presenter->message($message, $user),
                'link'         => $this->generateUrl('nowo_chat_page_conversation', ['uuid' => $uuid, 'message' => $message->getUuid()]),
                'unsave'       => $this->generateUrl('nowo_chat_api_save', ['uuid' => $uuid, 'messageUuid' => $message->getUuid()]),
            ];
        }

        return $this->render('@NowoChatKitBundle/saved.html.twig', [
            'layout'                => $this->webUiLayout,
            'header'                => ChatPage::savedHeader(),
            'empty_state'           => ChatPage::savedEmpty(),
            'bundle'                => ChatPage::BUNDLE,
            'items'                 => $items,
            'next'                  => $page['next'] === null ? null : $this->generateUrl('nowo_chat_page_saved', ['before' => $page['next']]),
            'csrf'                  => $this->csrf->getToken(ChatApiController::CSRF_ID)->getValue(),
            'nowo_chat_assets'      => $this->assets,
            'nowo_chat_full_height' => $this->fullHeight,
        ]);
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
        // 1.16: the list's and the page's pictures in one resolver call (context() primes them before presenting).
        $context = $this->context($user, $conversation, false, $page);

        return $this->render($this->pageTemplate, [
            'messages' => array_map(fn (ChatMessage $message): array => $this->presenter->message($message, $user), $page),
            'read'     => $last instanceof ChatMessage ? [
                'url'       => $this->generateUrl('nowo_chat_api_read', ['uuid' => $uuid]),
                'messageId' => (int) $last->getId(),
                'csrf'      => $this->csrf->getToken(ChatApiController::CSRF_ID)->getValue(),
            ] : null,
            'can_send' => $this->isGranted(ChatVoter::SEND, $conversation),
            'composer' => $this->composer($uuid)->createView(),
        ] + $context);
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
     * @param list<ChatMessage> $page Messages of the open conversation (their authors' pictures are primed with the list's)
     *
     * @return array<string, mixed>
     */
    private function context(ChatUserInterface $user, ?ChatConversation $current, bool $archived = false, array $page = []): array
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
        $list    = array_values(array_filter($list, static fn (ChatConversation $conversation): bool => $conversation === $current || isset($closed[(int) $conversation->getId()]) === $archived));
        $ids     = array_map(static fn (ChatConversation $conversation): int => (int) $conversation->getId(), $list);
        $unread  = $this->messages->countUnreadByConversation((string) ChatUserReferences::key($user), $ids);
        $latest  = $this->messages->findLatestByConversationIds($ids);
        $list    = array_values(array_filter($list, static fn (ChatConversation $conversation): bool => !$conversation->isDirect() || isset($latest[(int) $conversation->getId()]) || $conversation === $current));
        $isAgent = $this->access->isSupportAgent($user);
        $this->presenter->primeAvatars([
            ...array_map(fn (ChatConversation $conversation): ?ChatUserInterface => $this->presenter->avatarPerson($conversation, $user, $this->access->directPeer($conversation, $user), $isAgent), $current instanceof ChatConversation ? [...$list, $current] : $list),
            ...array_map(static fn (ChatMessage $message): ?ChatUserInterface => $message->getAuthor(), $page),
        ]);
        // 1.29: the page's author badges in one provider call.
        $this->presenter->primeAuthorBadges($page);
        $rows = [];
        foreach ($list as $conversation) {
            $id = (int) $conversation->getId();
            // The open conversation is being read: the page script moves the cursor once it loads.
            $rows[] = $this->summary($conversation, $user, $conversation === $current ? 0 : $unread[$id] ?? 0, $latest[$id] ?? null);
        }

        $summary = $current instanceof ChatConversation ? $this->summary($current, $user, 0, null, true) : null;

        return [
            // `layout`: the page layout (web_ui.layout_template); null = UiKit layout.
            'layout'        => $this->webUiLayout,
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
            // 1.21: `nowo_chat_assets` also reaches a dock the layout includes (the include shares this context).
            'nowo_chat_assets'      => $this->assets,
            'nowo_chat_full_height' => $this->fullHeight,
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
        $user = $this->currentUser instanceof ChatCurrentUserPort ? $this->currentUser->resolve($user) : $user;
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
