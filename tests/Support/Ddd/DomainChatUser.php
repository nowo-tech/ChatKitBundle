<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Ddd;

use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.24: a chat participant of a hexagonal host — a plain object, not a Doctrine entity and not the security user.
 */
final readonly class DomainChatUser implements ChatUserInterface
{
    public function __construct(
        private int $id,
        private string $publicId,
        private string $name,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getChatPublicId(): string
    {
        return $this->publicId;
    }

    public function getChatDisplayName(): string
    {
        return $this->name;
    }

    public function getChatInitials(): string
    {
        return mb_substr($this->name, 0, 1);
    }

    public function getChatEmail(): ?string
    {
        return null;
    }

    public function getChatLocale(): ?string
    {
        return null;
    }
}
