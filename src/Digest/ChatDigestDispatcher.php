<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Digest;

use Nowo\ChatKitBundle\Contract\ChatDigestSenderInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatDigestItem;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The delayed digest check: still unread conversations the user takes part in, the user reachable, opted in,
 * the app not open in any tab, no digest in the last `interval_minutes` — then one email through the sender.
 */
final readonly class ChatDigestDispatcher
{
    /**
     * Builds the dispatcher from the directory, repositories, presence, access checks, presenter, renderer,
     * the application's digest sender and the cache that throttles emails.
     *
     * @param string $urlTemplate Link of each conversation; `{uuid}` is replaced by the conversation id
     * @param int $intervalMinutes Minimum minutes between two digests to the same user
     */
    public function __construct(
        private ChatDirectoryInterface $directory,
        private ChatConversationRepository $conversations,
        private ChatMessageRepository $messages,
        private ChatUserSettingsRepository $settings,
        private ChatPresence $presence,
        private ChatAccess $access,
        private ChatPresenter $presenter,
        private ChatMarkdownRenderer $markdown,
        private ChatDigestSenderInterface $sender,
        private CacheItemPoolInterface $cache,
        private string $urlTemplate = '/?chat={uuid}',
        private int $intervalMinutes = 60,
    ) {
    }

    /**
     * Emails the user a digest of their still unread conversations, unless they are unreachable, opted out,
     * have the app open or already got one within the interval.
     *
     * @param SendChatDigestMessage $command The user to check
     */
    public function __invoke(SendChatDigestMessage $command): void
    {
        $user = $this->directory->findByIds([$command->userId])[0] ?? null;
        if ($user === null || !$this->access->isReachable($user) || !$this->settings->forUser($user)->isEmailDigest()) {
            return;
        }
        if ($this->presence->statusOf([$user])[$user->getChatPublicId()]['appOpen']) {
            return;
        }
        $sent = $this->cache->getItem('nowo_chat_digest_sent_' . hash('xxh128', $user->getChatPublicId()));
        if ($sent->isHit()) {
            return;
        }
        $conversations = $this->conversations->findParticipating($user, 50);
        $ids           = array_map(static fn (ChatConversation $conversation): int => (int) $conversation->getId(), $conversations);
        $unread        = $this->messages->countUnreadByConversation((int) $user->getId(), $ids);
        $latest        = $this->messages->findLatestByConversationIds($ids);
        $items         = [];
        foreach ($conversations as $conversation) {
            $count = $unread[(int) $conversation->getId()] ?? 0;
            if ($count === 0) {
                continue;
            }
            $summary = $this->presenter->conversation(
                $conversation,
                $user,
                $this->access->directPeer($conversation, $user),
                $conversation->isChannel() ? $this->access->channel((string) $conversation->getChannelKey()) : null,
                true,
                $count,
                null,
                $this->access->isSupportAgent($user),
            );
            $last    = $latest[(int) $conversation->getId()] ?? null;
            $items[] = new ChatDigestItem(
                (string) $summary['title'],
                $count,
                $last === null || $last->isDeleted() || $last->isBuzz() ? '' : $this->markdown->plainText($last->getBody(), 140),
                str_replace('{uuid}', $conversation->getUuid(), $this->urlTemplate),
            );
        }
        if ($items === []) {
            return;
        }
        $this->sender->send($user, $items);
        $this->cache->save($sent->set(true)->expiresAfter($this->intervalMinutes * 60));
    }
}
