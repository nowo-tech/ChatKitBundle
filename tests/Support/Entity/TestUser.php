<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Host user entity used by the integration tests.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_user')]
class TestUser implements ChatUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private string $publicId;

    public function __construct(
        #[ORM\Column(length: 120)]
        private string $name,
        #[ORM\Column(length: 180, nullable: true)]
        private ?string $email = null,
    ) {
        $this->publicId = Uuid::v7()->toRfc4122();
    }

    public function getId(): ?int
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
        return mb_strtoupper(mb_substr($this->name, 0, 1));
    }

    public function getChatEmail(): ?string
    {
        return $this->email;
    }
}
