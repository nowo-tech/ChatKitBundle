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

    /**
     * Creates default settings for the user (not persisted until changed).
     *
     * @param ChatUserInterface $user The owner of the settings
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private ChatUserInterface $user,
    ) {
    }

    /**
     * Doctrine identifier.
     *
     * @return int|null The id; null for unsaved defaults
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * The owner of the settings.
     *
     * @return ChatUserInterface The user
     */
    public function getUser(): ChatUserInterface
    {
        return $this->user;
    }

    /**
     * Whether others see the user's online dot (and the user sees theirs).
     *
     * @return bool True when presence is shown
     */
    public function isPresenceVisible(): bool
    {
        return $this->presenceVisible;
    }

    /**
     * Shows or hides the user's presence (reciprocal).
     *
     * @param bool $visible True to show presence
     */
    public function setPresenceVisible(bool $visible): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->presenceVisible = $visible;
    }

    /**
     * Whether Web Push is enabled for new messages.
     *
     * @return bool True when enabled
     */
    public function isPushEnabled(): bool
    {
        return $this->pushEnabled;
    }

    /**
     * Enables or disables Web Push for new messages.
     *
     * @param bool $enabled True to enable
     */
    public function setPushEnabled(bool $enabled): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->pushEnabled = $enabled;
    }

    /**
     * Notification sound.
     *
     * @return string One of SOUNDS
     */
    public function getSound(): string
    {
        return $this->sound;
    }

    /**
     * Sets the notification sound.
     *
     * @param string $sound One of SOUNDS
     *
     * @throws InvalidArgumentException When the sound is unknown
     */
    public function setSound(string $sound): void
    {
        if (!in_array($sound, self::SOUNDS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown chat sound "%s".', $sound));
        }
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->sound = $sound;
    }

    /**
     * Whether the unread-messages email is enabled.
     *
     * @return bool True when enabled
     */
    public function isEmailDigest(): bool
    {
        return $this->emailDigest;
    }

    /**
     * Enables or disables the unread-messages email.
     *
     * @param bool $enabled True to enable
     */
    public function setEmailDigest(bool $enabled): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->emailDigest = $enabled;
    }

    /**
     * Whether a buzz opens, shakes and rings the dock (otherwise it is only a line in the thread).
     *
     * @return bool True when buzzes are accepted
     */
    public function isBuzzEnabled(): bool
    {
        return $this->buzzEnabled;
    }

    /**
     * Accepts or mutes buzzes.
     *
     * @param bool $enabled True to accept buzzes
     */
    public function setBuzzEnabled(bool $enabled): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->buzzEnabled = $enabled;
    }
}
