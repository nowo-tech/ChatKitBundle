<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * 1.24: what a host kept of an account that no longer exists, for ChatPersonalData::erase() /
 * eraseFormerAccount() (`doctrine.user_reference: id`): the user id (rows by reference), the chat public id (mention
 * tokens `@[Name](publicId)` in other people's messages) and the display name (bot and system line parameters).
 */
final readonly class ErasedChatUser implements ChatUserInterface
{
    /**
     * @param int $id The account's user id
     * @param string $publicId Its former chat public id
     * @param string $displayName Its former display name ('' when unknown)
     */
    public function __construct(
        private int $id,
        private string $publicId,
        private string $displayName = '',
    ) {
    }

    /**
     * The account's user id.
     *
     * @return int The id
     */
    public function getId(): int
    {
        return $this->id;
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
