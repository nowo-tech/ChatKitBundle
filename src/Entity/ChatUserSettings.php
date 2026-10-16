<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;

use function in_array;
use function sprintf;

/**
 * Per-user chat preferences, owned by the bundle so the host user table needs no extra columns. A missing
 * row means the defaults below.
 */
#[ORM\Entity(repositoryClass: ChatUserSettingsRepository::class)]
#[ORM\Table(name: 'nowo_chat_user_settings')]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_user_settings_user', columns: ['user_id'])]
class ChatUserSettings
{
    public const array SOUNDS = ['none', 'pop', 'chime', 'ding'];

    public const string DEFAULT_SOUND = 'pop';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Others see my online dot (and, symmetrically, I see theirs). */
    #[ORM\Column(name: 'presence_visible', options: ['default' => true])]
    private bool $presenceVisible = true;

    /** Web Push for new messages while the app is not open in any tab. */
    #[ORM\Column(name: 'push_enabled', options: ['default' => false])]
    private bool $pushEnabled = false;

    #[ORM\Column(length: 16, options: ['default' => self::DEFAULT_SOUND])]
    private string $sound = self::DEFAULT_SOUND;

    /** Email listing the conversations still unread a while after a message (when the application sends it). */
    #[ORM\Column(name: 'email_digest', options: ['default' => true])]
    private bool $emailDigest = true;

    /** When off, a buzz is only a line in the thread (no opening, shake or sound). */
    #[ORM\Column(name: 'buzz_enabled', options: ['default' => true])]
    private bool $buzzEnabled = true;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private ChatUserInterface $user,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ChatUserInterface
    {
        return $this->user;
    }

    public function isPresenceVisible(): bool
    {
        return $this->presenceVisible;
    }

    public function setPresenceVisible(bool $visible): void
    {
        $this->presenceVisible = $visible;
    }

    public function isPushEnabled(): bool
    {
        return $this->pushEnabled;
    }

    public function setPushEnabled(bool $enabled): void
    {
        $this->pushEnabled = $enabled;
    }

    public function getSound(): string
    {
        return $this->sound;
    }

    public function setSound(string $sound): void
    {
        if (!in_array($sound, self::SOUNDS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown chat sound "%s".', $sound));
        }
        $this->sound = $sound;
    }

    public function isEmailDigest(): bool
    {
        return $this->emailDigest;
    }

    public function setEmailDigest(bool $enabled): void
    {
        $this->emailDigest = $enabled;
    }

    public function isBuzzEnabled(): bool
    {
        return $this->buzzEnabled;
    }

    public function setBuzzEnabled(bool $enabled): void
    {
        $this->buzzEnabled = $enabled;
    }
}
