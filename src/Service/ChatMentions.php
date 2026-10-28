<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatRecipients;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;

use function count;
use function in_array;

use const PREG_SET_ORDER;

/**
 * @mentions in message text. The composer writes a mention as the token `@[Display name](publicId)`; on send and on
 * edit this service keeps the token only for a member of the conversation (someone who may read it now, never the
 * author) and rewrites its name to the member's current display name, turns every other token into plain
 * `@name` text, and stores one ChatMessageMention row per mentioned member.
 */
final readonly class ChatMentions
{
    /** A mention token: `@[name](publicId)`. Group 1 is the name, group 2 the public id. */
    public const string TOKEN = '/@\[([^\[\]\n]{1,80})\]\(([A-Za-z0-9_-]{1,64})\)/u';

    /** At most this many people are mentioned in one message; later tokens stay plain text. */
    public const int MAX_MENTIONS = 20;

    /**
     * Builds the service from the recipients (conversation members), the access checks and the mention rows.
     */
    public function __construct(
        private ChatRecipients $recipients,
        private ChatAccess $access,
        private ChatMessageMentionRepository $mentions,
        private EntityManagerInterface $entityManager,
    ) {
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
     *
     * @return array{string, list<ChatUserInterface>} The body to store and the mentioned members
     */
    public function resolve(ChatConversation $conversation, ChatUserInterface $author, string $body): array
    {
        $tokens = self::parse($body);
        if ($tokens === []) {
            return [$body, []];
        }
        $members = [];
        foreach ($this->recipients->of($conversation) as $member) {
            $members[$member->getChatPublicId()] = $member;
        }
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

        return [$body, array_values($mentioned)];
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
