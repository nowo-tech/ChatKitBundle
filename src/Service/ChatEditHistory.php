<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatHistoryPolicyInterface;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageRevision;
use Nowo\ChatKitBundle\Model\ChatHistoryRequest;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatMessageRevisionRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;

use function array_map;

use const DATE_ATOM;

/**
 * 1.36 (Beacon, D-20261118-02): edit history (`message_menu.edit_history`). ChatService keeps the previous text of each
 * edit (up to `max_revisions`); every reader of the message info sees when it was edited, and the author, moderators
 * and whom ChatHistoryPolicyInterface allows read the previous texts — rendered for that reader at read time, so a
 * #reference shows only what they may open. Access to the conversation is the caller's job. Stateless.
 */
final readonly class ChatEditHistory
{
    /**
     * @param ChatHistoryPolicyInterface $policy Who else reads previous texts (the default: nobody)
     */
    public function __construct(
        private ChatMenuOptions $options,
        private ChatMessageRevisionRepository $revisions,
        private ChatMarkdownRenderer $markdown,
        private ChatAccess $access,
        private ChatHistoryPolicyInterface $policy,
        private ?ChatReferences $references = null,
    ) {
    }

    /**
     * Whether edit history is kept.
     */
    public function enabled(): bool
    {
        return $this->options->historyEnabled();
    }

    /**
     * Whether the viewer may read the previous texts of the message: its author, a moderator (a support agent outside
     * direct conversations), or whom the application's policy allows.
     *
     * @param ChatUserInterface $viewer The viewer (who may read the conversation)
     * @param ChatMessage $message The message
     */
    public function mayRead(ChatUserInterface $viewer, ChatMessage $message): bool
    {
        if ($message->isAuthoredBy($viewer) || (!$message->getConversation()->isDirect() && $this->access->isSupportAgent($viewer))) {
            return true;
        }
        $conversation = $message->getConversation();

        return $this->policy->mayReadHistory(new ChatHistoryRequest(
            $viewer->getChatPublicId(),
            $message->getUuid(),
            $message->getAuthor()?->getChatPublicId(),
            $conversation->getUuid(),
            $conversation->getKind()->value,
            $conversation->getChannelKey(),
        ));
    }

    /**
     * When the message was edited (the kept revisions), newest first. The caller checks that edit history is on.
     *
     * @param ChatMessage $message The message
     *
     * @return list<array{editedAt: string}>
     */
    public function edits(ChatMessage $message): array
    {
        return array_map(static fn (DateTimeImmutable $at): array => ['editedAt' => $at->format(DATE_ATOM)], $this->revisions->editTimes($message));
    }

    /**
     * The previous texts, newest first, rendered for the viewer (edit history on, the message not deleted: ChatVoter::HISTORY).
     * Never the stored Markdown (audit UB-1): a #reference keeps its title only for a viewer who may open the item; the
     * HTML shows the neutral chip and the plain text the neutral placeholder otherwise.
     *
     * @param ChatUserInterface $viewer The viewer ({@see self::mayRead()} said yes)
     * @param ChatMessage $message The message
     *
     * @return list<array{editedAt: string, text: string, html: string}>
     */
    public function revisions(ChatUserInterface $viewer, ChatMessage $message): array
    {
        return array_map(function (ChatMessageRevision $revision) use ($viewer): array {
            $body = $revision->getBody();
            $html = $this->markdown->render($body);
            $html = $this->references?->fill($html, $body, $viewer)['html'] ?? $html;
            $text = $this->references?->plainText($this->markdown, $body, $viewer, ChatMessage::MAX_LENGTH) ?? $this->markdown->plainText($body, ChatMessage::MAX_LENGTH);

            return ['editedAt' => $revision->getEditedAt()->format(DATE_ATOM), 'text' => $text, 'html' => ChatMarkdownRenderer::stripAttributes($html)];
        }, $this->revisions->forMessage($message));
    }
}
