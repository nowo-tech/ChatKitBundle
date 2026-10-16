<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Push;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatPushSenderInterface;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Picks who gets a Web Push for a message, checked at delivery time: participants only (never everyone who
 * could read it), reachable, push enabled, not muted, not yet read, the app not open in any tab, buzzes only
 * to people who accept them. The application's ChatPushSenderInterface delivers the payloads.
 *
 * Messenger handler (when symfony/messenger is installed); signed (JSON + HMAC) when the application signs its messages.
 */
#[AsMessageHandler(sign: true)]
final readonly class ChatPushDispatcher
{
    /**
     * Builds the dispatcher from the repositories, presence, access checks and the application's push sender.
     *
     * @param string $urlTemplate Notification click URL; `{uuid}` is replaced by the conversation id
     * @param string|null $icon Icon and badge URL of the notification; null omits them
     * @param ClockInterface $clock Clock used for the mute check
     * @param bool $preview Whether the body carries the message text (`push_preview`); otherwise a generic "New message"
     */
    public function __construct(
        private ChatMessageRepository $messages,
        private ChatParticipantRepository $participants,
        private ChatUserSettingsRepository $settings,
        private ChatPresence $presence,
        private ChatAccess $access,
        private ChatMarkdownRenderer $markdown,
        private TranslatorInterface $translator,
        private ChatPushSenderInterface $sender,
        private string $urlTemplate = '/?chat={uuid}',
        private ?string $icon = null,
        private ClockInterface $clock = new Clock(),
        private bool $preview = false,
    ) {
    }

    /**
     * Filters the candidate recipients of the message (reachable, push enabled, app not open, not muted, not
     * read yet, buzzes accepted) and hands the remaining ones to the push sender.
     *
     * @param DeliverChatPushMessage $command The message id and the candidate recipient ids
     */
    public function __invoke(DeliverChatPushMessage $command): void
    {
        $message = $this->messages->find($command->messageId);
        if (!$message instanceof ChatMessage || $message->isDeleted() || $command->recipientIds === []) {
            return;
        }
        $now = $this->clock->now();
        /** @var list<ChatParticipant> $participants */
        $participants = $this->participants->findBy(['conversation' => $message->getConversation(), 'user' => $command->recipientIds]);
        $users        = array_map(static fn (ChatParticipant $participant): ChatUserInterface => $participant->getUser(), $participants);
        $presence     = $this->presence->statusOf($users);
        $settings     = $this->settings->forUsers($users);

        $recipients = [];
        foreach ($participants as $participant) {
            $user        = $participant->getUser();
            $preferences = $settings[(int) $user->getId()];
            if (!$this->access->isReachable($user) || !$preferences->isPushEnabled() || ($presence[$user->getChatPublicId()]['appOpen'] ?? false)) {
                continue;
            }
            if ($this->isSilenced($participant, $message, $now) || ($message->isBuzz() && !$preferences->isBuzzEnabled())) {
                continue;
            }
            $recipients[] = $user;
        }
        if ($recipients !== []) {
            $this->sender->send($recipients, fn (ChatUserInterface $user): array => $this->payload($message, $user));
        }
    }

    private function isSilenced(ChatParticipant $participant, ChatMessage $message, DateTimeImmutable $now): bool
    {
        return $participant->isMuted($now) || (int) $participant->getLastReadMessageId() >= (int) $message->getId();
    }

    /**
     * @return array<string, string>
     */
    private function payload(ChatMessage $message, ChatUserInterface $recipient): array
    {
        $conversation = $message->getConversation();
        $locale       = $recipient->getChatLocale();
        $author       = $message->getAuthor();
        $authorName   = $author?->getChatDisplayName() ?? $this->translator->trans('chat.deleted_user', [], 'NowoChatKitBundle', $locale);
        $title        = match (true) {
            $conversation->isChannel()                                                                     => '#' . ($this->access->channel((string) $conversation->getChannelKey())->label ?? '') . ' · ' . $authorName,
            $conversation->isSupport() && $author?->getId() !== $conversation->getSupportOwner()?->getId() => $this->translator->trans('chat.support_title', [], 'NowoChatKitBundle', $locale),
            default                                                                                        => $authorName,
        };
        $body = match (true) {
            $message->isBuzz() => $this->translator->trans('chat.buzz_received', ['%name%' => $authorName], 'NowoChatKitBundle', $locale),
            // `push_preview`: message text on lock screens only when the application opts in.
            $this->preview => $this->markdown->plainText($message->getBody(), 140),
            default        => $this->translator->trans('chat.new_message', [], 'NowoChatKitBundle', $locale),
        };

        return array_filter([
            'event' => 'chat.message',
            'title' => $title,
            'body'  => $body,
            // One notification per conversation, replaced by the next one (no stack of toasts).
            'tag'   => 'chat-' . $conversation->getUuid(),
            'url'   => str_replace('{uuid}', $conversation->getUuid(), $this->urlTemplate),
            'icon'  => $this->icon,
            'badge' => $this->icon,
        ], static fn (?string $value): bool => $value !== null);
    }
}
