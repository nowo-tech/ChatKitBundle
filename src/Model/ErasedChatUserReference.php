<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * 1.27: an account that is gone, known by its string reference (ChatUserReferenceInterface,
 * `doctrine.user_reference: id`); what ChatPersonalData::eraseFormerAccount() erases for a non-integer id (the integer
 * form is ErasedChatUser).
 */
final readonly class ErasedChatUserReference implements ChatUserInterface, ChatUserReferenceInterface
{
    /**
     * @param string $reference The account's stored reference
     * @param string $publicId Its former chat public id
     * @param string $displayName Its former display name ('' when unknown)
     */
    public function __construct(
        private string $reference,
        private string $publicId,
        private string $displayName = '',
    ) {
        ChatUserReference::assertValid($reference);
    }

    /**
     * No integer id.
     *
     * @return int|null Always null
     */
    public function getId(): ?int
    {
        return null;
    }

    /**
     * The account's stored reference.
     *
     * @return string The reference
     */
    public function getChatUserReference(): string
    {
        return $this->reference;
    }

    /**
     * The account's former chat public id.
     *
     * @return string The public id
     */
    public function getChatPublicId(): string
    {
        return $this->publicId;
    }

    /**
     * The account's former display name.
     *
     * @return string The name ('' when unknown)
     */
    public function getChatDisplayName(): string
    {
        return $this->displayName;
    }

    /**
     * No initials.
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
