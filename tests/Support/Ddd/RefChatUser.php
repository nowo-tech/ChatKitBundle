<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Ddd;

use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Model\ChatUserReferenceInterface;

/**
 * 1.27: a domain user known by a string reference only (a UUID, an IdP subject): no integer id, not a Doctrine entity.
 */
final readonly class RefChatUser implements ChatUserInterface, ChatUserReferenceInterface
{
    public function __construct(
        private string $reference,
        private string $publicId,
        private string $name,
    ) {
    }

    public function getId(): ?int
    {
        return null;
    }

    public function getChatUserReference(): string
    {
        return $this->reference;
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
