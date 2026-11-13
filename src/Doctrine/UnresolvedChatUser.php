<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Model\ChatUserReferenceInterface;
use SensitiveParameter;

/**
 * 1.24, `doctrine.user_reference: id` only: stands in for a user the directory no longer knows, on rows that need one
 * (participants, reactions, mentions, settings, quick replies) — rows the relation mode would have removed with the
 * account (foreign key `ON DELETE CASCADE`). Never reachable (ChatAccess), so it receives and sees nothing, and it is
 * left out of reaction summaries. Its public id is an HMAC of the reference keyed with `kernel.secret` (audit KC-L3), so
 * the former user id cannot be recovered by hashing candidate ids. Call ChatPersonalData::erase() when you delete an
 * account so these rows do not stay behind.
 */
final readonly class UnresolvedChatUser implements ChatUserInterface, ChatUserReferenceInterface
{
    /**
     * @param string $reference The stored reference (the former user's id)
     * @param string $secret Key of the public id HMAC (`kernel.secret`)
     */
    public function __construct(
        public string $reference,
        #[SensitiveParameter]
        private string $secret,
    ) {
    }

    /**
     * The id the rows point at.
     *
     * @return int|null The id; null when the reference is not numeric
     */
    public function getId(): ?int
    {
        return ctype_digit($this->reference) ? (int) $this->reference : null;
    }

    /**
     * 1.27: the stored reference, so the rows of a gone user still compare by exactly what they hold.
     *
     * @return string The reference
     */
    public function getChatUserReference(): string
    {
        return $this->reference;
    }

    /**
     * A stable public id that does not reveal the reference.
     *
     * @return string The public id
     */
    public function getChatPublicId(): string
    {
        return 'former-' . substr(hash_hmac('sha256', 'nowo_chat_former_user|' . $this->reference, $this->secret), 0, 24);
    }

    /**
     * No name: the dock shows the "Deleted user" label where it has one.
     *
     * @return string An empty string
     */
    public function getChatDisplayName(): string
    {
        return '';
    }

    /**
     * Initials of the bubble.
     *
     * @return string `?`
     */
    public function getChatInitials(): string
    {
        return '?';
    }

    /**
     * No e-mail.
     *
     * @return string|null Always null
     */
    public function getChatEmail(): ?string
    {
        return null;
    }

    /**
     * No locale.
     *
     * @return string|null Always null
     */
    public function getChatLocale(): ?string
    {
        return null;
    }
}
