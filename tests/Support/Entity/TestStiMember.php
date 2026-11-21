<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\Uid\Uuid;

use function mb_strtoupper;
use function mb_substr;

/**
 * 2.0 (audit lesson W1-2, XS-I1): a host user class that is a single-table-inheritance root, for a `user_class` with
 * subclasses (the private manager's reference to such a root is a database read and may find nothing).
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_sti_member')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string', length: 16)]
#[ORM\DiscriminatorMap(['staff' => TestStiStaff::class, 'guest' => TestStiGuest::class])]
abstract class TestStiMember implements ChatUserInterface
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
        return null;
    }

    public function getChatLocale(): ?string
    {
        return null;
    }
}
