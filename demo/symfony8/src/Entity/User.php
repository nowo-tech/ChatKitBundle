<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Demo account: signs in with the form login and talks through the chat (ChatUserInterface).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'demo_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, ChatUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36, unique: true)]
    private string $publicId;

    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[ORM\Column(length: 60, unique: true)]
        private string $username,
        #[ORM\Column(length: 120)]
        private string $displayName,
        #[ORM\Column(type: 'json')]
        private array $roles = ['ROLE_USER'],
        #[ORM\Column(length: 255)]
        private string $password = 'password',
    ) {
        $this->publicId = Uuid::v7()->toRfc4122();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->username);

        return $this->username;
    }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function eraseCredentials(): void
    {
    }

    public function getChatPublicId(): string
    {
        return $this->publicId;
    }

    public function getChatDisplayName(): string
    {
        return $this->displayName;
    }

    public function getChatInitials(): string
    {
        $initials = '';
        foreach (\array_slice(preg_split('/\s+/', trim($this->displayName)) ?: [], 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $initials;
    }

    public function getChatEmail(): ?string
    {
        return $this->username.'@demo.invalid';
    }

    public function getChatLocale(): ?string
    {
        return null;
    }

    public function isSupportAgent(): bool
    {
        return \in_array('ROLE_SUPPORT', $this->roles, true);
    }
}
