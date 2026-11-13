<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelMentionPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Realtime\ChatRecipients;
use Nowo\ChatKitBundle\Port\Ports;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

use function array_slice;
use function count;
use function in_array;

use const PREG_SET_ORDER;

/**
 * @mentions in message text. The composer writes a mention as the token `@[Display name](publicId)`; on send and on
 * edit this service keeps the token only for a member of the conversation (someone who may read it now, never the
 * author) and rewrites its name to the member's current display name, turns every other token into plain
 * `@name` text, and stores one ChatMessageMention row per mentioned member.
 *
 * 1.24: in a channel, `@channel` mentions every member and `@here` every member online now — only when the channel
 * provider implements ChatChannelMentionPolicyInterface and allows the writer, and within the writer's
 * `nowo_chat_mention_all` budget; otherwise the words stay plain text and nobody is notified.
 */
final readonly class ChatMentions
{
    /** A mention token: `@[name](publicId)`. Group 1 is the name, group 2 the public id. */
    public const string TOKEN = '/@\[([^\[\]\n]{1,80})\]\(([A-Za-z0-9_-]{1,64})\)/u';

    /** At most this many people are mentioned in one message; later tokens stay plain text. */
    public const int MAX_MENTIONS = 20;

    /** 1.24: a group mention (`@channel`, `@here`) outside a word, an e-mail address or a mention token. */
    public const string GROUP_TOKEN = '/(?<![\pL\pN_@.\[])@(channel|here)(?![\pL\pN_-])/u';

    /** 1.24: at most this many people are notified by one group mention (the first ones in ChatRecipients order). */
    public const int MAX_GROUP_MENTIONS = 500;

    /**
     * Builds the service from the recipients (conversation members), the access checks and the mention rows.
     */
    public function __construct(
        private ChatRecipients $recipients,
        private ChatAccess $access,
        private ChatMessageMentionRepository $mentions,
        private EntityManagerInterface $entityManager,
        private ?ChatChannelProviderInterface $channels = null,
        private ?ChatPresence $presence = null,
        private ?RateLimiterFactoryInterface $mentionAllLimiter = null,
        private ?ChatParticipantRepository $participants = null,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * 1.24: the group mention a text asks for (`@channel` wins over `@here`), outside code spans and blocks.
     *
     * @param string $body Message Markdown
     *
     * @return string|null ChatMessage::MENTION_CHANNEL, ChatMessage::MENTION_HERE, or null
     */
    public static function parseGroup(string $body): ?string
    {
        $text = (string) preg_replace(['/```.*?```/s', '/`[^`\n]+`/', self::TOKEN], ' ', $body);
        if (preg_match_all(self::GROUP_TOKEN, $text, $matches) < 1) {
            return null;
        }

        return in_array(ChatMessage::MENTION_CHANNEL, $matches[1], true) ? ChatMessage::MENTION_CHANNEL : ChatMessage::MENTION_HERE;
    }

    /**
     * Mention tokens of a text, outside code spans and blocks (where they are shown as written).
     *
     * @param string $body Message Markdown
     *
     * @return array<string, string> public id => name, in order of first appearance
     */
    public static function parse(string $body): array
    {
        $text = (string) preg_replace(['/```.*?```/s', '/`[^`\n]+`/'], ' ', $body);
        preg_match_all(self::TOKEN, $text, $matches, PREG_SET_ORDER);
        $found = [];
        foreach ($matches as $match) {
            $found[$match[2]] ??= $match[1];
        }

        return $found;
    }

    /**
     * Validates the tokens of a message about to be stored.
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $author The writer (a self-mention stays plain text)
     * @param string $body Normalized message Markdown
     * @param string|null $previousGroup 1.24: the group mention the message already had (an edit keeping it is not rate limited again)
     *
     * @return array{string, list<ChatUserInterface>, string|null} The body to store, the mentioned members and (1.24) the group mention granted (`channel`, `here` or null)
     */
    public function resolve(ChatConversation $conversation, ChatUserInterface $author, string $body, ?string $previousGroup = null): array
    {
        $tokens = self::parse($body);
        $group  = $conversation->isChannel() ? self::parseGroup($body) : null;
        if ($tokens === [] && $group === null) {
            return [$body, [], null];
        }
        $members = [];
        foreach ($this->recipients->of($conversation) as $member) {
            $members[$member->getChatPublicId()] = $member;
        }
        $group     = $group === null ? null : $this->grantGroup($conversation, $author, $group, count($members), $previousGroup);
        $mentioned = [];
        foreach (array_keys($tokens) as $publicId) {
            $member = $members[$publicId] ?? null;
            if (count($mentioned) < self::MAX_MENTIONS && $member instanceof ChatUserInterface && $member->getId() !== $author->getId()
                && $this->access->canView($conversation, $member)) {
                $mentioned[$publicId] = $member;
            }
        }

        $body = (string) preg_replace_callback(self::TOKEN, static function (array $match) use ($mentioned): string {
            $member = $mentioned[$match[2]] ?? null;

            return $member instanceof ChatUserInterface ? self::token($member) : '@' . $match[1];
        }, $body);
        if ($group !== null) {
            $mentioned += $this->groupMembers($conversation, $author, $group, $members);
        }

        return [$body, array_values($mentioned), $group];
    }

    /**
     * The group mention the writer gets: allowed by the provider's policy for this channel and member count, and within
     * the writer's budget (an edit that keeps the message's group mention spends nothing).
     */
    private function grantGroup(ChatConversation $conversation, ChatUserInterface $author, string $group, int $memberCount, ?string $previousGroup): ?string
    {
        $channel = $this->access->channel((string) $conversation->getChannelKey());
        if (!$channel instanceof ChatChannel || !($policy = $this->channels === null ? null : Ports::companion($this->channels)) instanceof ChatChannelMentionPolicyInterface
            || !$this->access->canWriteChannel($author, $channel->key) || !$policy->canMentionAll($author, $channel, $memberCount)) {
            return null;
        }
        if ($previousGroup === $group) {
            return $group;
        }
        $limiter = $this->mentionAllLimiter?->create('nowo_chat_mention_all_' . $author->getChatPublicId());

        return !$limiter instanceof LimiterInterface || $limiter->consume()->isAccepted() ? $group : null;
    }

    /**
     * Members a group mention notifies: everyone who may read the channel now (`here`: only those online), never the
     * writer nor (audit KC-I3) members who muted the conversation, at most MAX_GROUP_MENTIONS in recipients order.
     *
     * @param array<string, ChatUserInterface> $members public id => member
     *
     * @return array<string, ChatUserInterface>
     */
    private function groupMembers(ChatConversation $conversation, ChatUserInterface $author, string $group, array $members): array
    {
        $muted   = array_flip($this->participants?->mutedUserIds($conversation, $this->clock->now()) ?? []);
        $members = array_filter($members, fn (ChatUserInterface $member): bool => $member->getId() !== $author->getId() && !isset($muted[(int) $member->getId()])
            && $this->access->canView($conversation, $member));
        if ($group === ChatMessage::MENTION_HERE) {
            $online  = $this->presence?->statusOf(array_values($members)) ?? [];
            $members = array_filter($members, static fn (ChatUserInterface $member): bool => ($online[$member->getChatPublicId()]['online'] ?? false) === true);
        }

        return array_slice($members, 0, self::MAX_GROUP_MENTIONS, true);
    }

    /**
     * Makes the message's mention rows match the mentioned members (persisted, not flushed).
     *
     * @param ChatMessage $message The stored or new message
     * @param list<ChatUserInterface> $users The members the text mentions now
     *
     * @return list<ChatUserInterface> The members mentioned for the first time (to notify)
     */
    public function sync(ChatMessage $message, array $users): array
    {
        $existing = $this->mentions->userIdsOf($message);
        $keep     = array_map(static fn (ChatUserInterface $user): int => (int) $user->getId(), $users);
        if (array_diff($existing, $keep) !== []) {
            // @igor-ignore - a repository DELETE query (database rows), not state held by this service.
            $this->mentions->removeFor($message, $keep);
        }
        $added = [];
        foreach ($users as $user) {
            if (!in_array((int) $user->getId(), $existing, true)) {
                $this->entityManager->persist(new ChatMessageMention($message, $user, $message->getCreatedAt()));
                $added[] = $user;
            }
        }

        return $added;
    }

    /**
     * The token for a member: `@[Display name](publicId)` (brackets and line breaks in the name become spaces).
     *
     * @param ChatUserInterface $user The member
     *
     * @return string The token
     */
    public static function token(ChatUserInterface $user): string
    {
        $name = trim((string) preg_replace('/[\[\]\s]+/u', ' ', $user->getChatDisplayName()));

        return '@[' . mb_substr($name === '' ? '?' : $name, 0, 80) . '](' . $user->getChatPublicId() . ')';
    }
}
