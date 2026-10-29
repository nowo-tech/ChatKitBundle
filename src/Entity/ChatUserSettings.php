<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;

use function in_array;
use function is_bool;
use function is_int;
use function is_string;
use function lcfirst;
use function mb_strlen;
use function preg_match;
use function sprintf;
use function substr;
use function trim;

use const DATE_ATOM;

/**
 * Per-user chat preferences and chat status, owned by the bundle so the host user table needs no extra columns. A
 * missing row means the defaults below.
 *
 * Preferences (1.10): a sound per event (new message, @mention, buzz, support reply) with a volume, sounds only while
 * the tab is in the background, buzz options, opening the dock on an incoming message, Enter or Ctrl/Cmd+Enter to send
 * and the message text in Web Push. Status (1.10): available, busy, away, do not disturb or invisible, with an optional
 * custom text and emoji that clear themselves at `statusExpiresAt`.
 */
#[ORM\Entity(repositoryClass: ChatUserSettingsRepository::class)]
#[ORM\Table(name: 'nowo_chat_user_settings')]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_user_settings_user', columns: ['user_id'])]
class ChatUserSettings
{
    /** Synthesised sounds (Web Audio), `none` for silence. */
    public const array SOUNDS = ['none', 'pop', 'chime', 'ding', 'bell', 'blip', 'knock', 'buzz'];

    public const string DEFAULT_SOUND = 'pop';

    /** Events with their own sound, and the sound a new user gets for each. */
    public const array SOUND_EVENTS = ['message' => 'pop', 'mention' => 'chime', 'buzz' => 'buzz', 'support' => 'ding'];

    public const int DEFAULT_VOLUME = 80;

    /** `enter`: Enter sends, Shift+Enter breaks the line; `ctrl_enter`: Ctrl/Cmd+Enter sends, Enter breaks the line. */
    public const array SEND_KEYS = ['enter', 'ctrl_enter'];

    /** Chat statuses a user picks; `invisible` shows the user offline to everyone. */
    public const array STATUSES = ['available', 'busy', 'away', 'dnd', 'invisible'];

    public const string DEFAULT_STATUS = 'available';

    public const int STATUS_TEXT_MAX = 80;

    public const int STATUS_EMOJI_MAX = 16;

    /** "Clear after" choices of a status: never, 30 minutes, 1 hour, 4 hours, end of today, end of this week. */
    public const array CLEAR_AFTER = ['never', '30m', '1h', '4h', 'today', 'week'];

    /**
     * Keys `applyPreference()` accepts (the JSON settings endpoint): preference key => kind (`sound`, `bool`,
     * `volume`, `send_key`). Push itself (it needs a browser subscription) is the application's own setting.
     */
    public const array PREFERENCE_KEYS = [
        'soundMessage'        => 'sound',
        'soundMention'        => 'sound',
        'soundBuzz'           => 'sound',
        'soundSupport'        => 'sound',
        'volume'              => 'volume',
        'soundBackgroundOnly' => 'bool',
        'buzzEnabled'         => 'bool',
        'buzzOpenWindow'      => 'bool',
        'openOnMessage'       => 'bool',
        'sendKey'             => 'send_key',
        'pushPreview'         => 'bool',
        'presenceVisible'     => 'bool',
        'emailDigest'         => 'bool',
    ];

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

    /** Sound of a new message (the 1.x single sound). */
    #[ORM\Column(length: 16, options: ['default' => self::DEFAULT_SOUND])]
    private string $sound = self::DEFAULT_SOUND;

    #[ORM\Column(name: 'sound_mention', length: 16, options: ['default' => 'chime'])]
    private string $soundMention = 'chime';

    #[ORM\Column(name: 'sound_buzz', length: 16, options: ['default' => 'buzz'])]
    private string $soundBuzz = 'buzz';

    #[ORM\Column(name: 'sound_support', length: 16, options: ['default' => 'ding'])]
    private string $soundSupport = 'ding';

    /** 0 – 100. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => self::DEFAULT_VOLUME])]
    private int $volume = self::DEFAULT_VOLUME;

    /** Sounds only while the tab is hidden or unfocused. */
    #[ORM\Column(name: 'sound_background_only', options: ['default' => false])]
    private bool $soundBackgroundOnly = false;

    /** A buzz opens its window (otherwise it rings and shakes a window already open). */
    #[ORM\Column(name: 'buzz_open_window', options: ['default' => true])]
    private bool $buzzOpenWindow = true;

    /** An incoming message opens its window in the dock (otherwise only the unread badge). */
    #[ORM\Column(name: 'open_on_message', options: ['default' => false])]
    private bool $openOnMessage = false;

    #[ORM\Column(name: 'send_key', length: 16, options: ['default' => 'enter'])]
    private string $sendKey = 'enter';

    /** The message text in Web Push (when the application enables `push_preview`). */
    #[ORM\Column(name: 'push_preview', options: ['default' => true])]
    private bool $pushPreview = true;

    #[ORM\Column(length: 16, options: ['default' => self::DEFAULT_STATUS])]
    private string $status = self::DEFAULT_STATUS;

    #[ORM\Column(name: 'status_text', length: self::STATUS_TEXT_MAX, nullable: true)]
    private ?string $statusText = null;

    #[ORM\Column(name: 'status_emoji', length: 32, nullable: true)]
    private ?string $statusEmoji = null;

    /** When the status (and its text) go back to available; null = never. */
    #[ORM\Column(name: 'status_expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $statusExpiresAt = null;

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

    /**
     * Sound of one event.
     *
     * @param string $event One of the SOUND_EVENTS keys
     *
     * @throws InvalidArgumentException When the event is unknown
     *
     * @return string One of SOUNDS
     */
    public function getSoundFor(string $event): string
    {
        return match ($event) {
            'message' => $this->sound,
            'mention' => $this->soundMention,
            'buzz'    => $this->soundBuzz,
            'support' => $this->soundSupport,
            default   => throw new InvalidArgumentException(sprintf('Unknown chat sound event "%s".', $event)),
        };
    }

    /**
     * Sets the sound of one event.
     *
     * @param string $event One of the SOUND_EVENTS keys
     * @param string $sound One of SOUNDS
     *
     * @throws InvalidArgumentException When the event or the sound is unknown
     */
    public function setSoundFor(string $event, string $sound): void
    {
        if (!in_array($sound, self::SOUNDS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown chat sound "%s".', $sound));
        }
        if (!isset(self::SOUND_EVENTS[$event])) {
            throw new InvalidArgumentException(sprintf('Unknown chat sound event "%s".', $event));
        }
        if ($event === 'message') {
            // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
            $this->sound = $sound;
        } elseif ($event === 'mention') {
            // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
            $this->soundMention = $sound;
        } elseif ($event === 'buzz') {
            // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
            $this->soundBuzz = $sound;
        } else {
            // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
            $this->soundSupport = $sound;
        }
    }

    /**
     * Volume of the chat sounds.
     *
     * @return int 0 – 100
     */
    public function getVolume(): int
    {
        return $this->volume;
    }

    /**
     * Sets the volume of the chat sounds.
     *
     * @param int $volume 0 – 100
     *
     * @throws InvalidArgumentException When out of range
     */
    public function setVolume(int $volume): void
    {
        if ($volume < 0 || $volume > 100) {
            throw new InvalidArgumentException('The chat volume goes from 0 to 100.');
        }
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->volume = $volume;
    }

    /**
     * Whether sounds play only while the tab is hidden or unfocused.
     *
     * @return bool True for background-only sounds
     */
    public function isSoundBackgroundOnly(): bool
    {
        return $this->soundBackgroundOnly;
    }

    /**
     * Plays sounds only in the background, or always.
     *
     * @param bool $backgroundOnly True for background-only sounds
     */
    public function setSoundBackgroundOnly(bool $backgroundOnly): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->soundBackgroundOnly = $backgroundOnly;
    }

    /**
     * Whether a buzz opens its conversation window.
     *
     * @return bool True when a buzz opens the window
     */
    public function isBuzzOpenWindow(): bool
    {
        return $this->buzzOpenWindow;
    }

    /**
     * Lets a buzz open its window, or only ring (and shake a window already open).
     *
     * @param bool $open True to open the window
     */
    public function setBuzzOpenWindow(bool $open): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->buzzOpenWindow = $open;
    }

    /**
     * Whether an incoming message opens its window in the dock.
     *
     * @return bool True to open the window; false shows only the unread badge
     */
    public function isOpenOnMessage(): bool
    {
        return $this->openOnMessage;
    }

    /**
     * Opens the window on an incoming message, or shows only the badge.
     *
     * @param bool $open True to open the window
     */
    public function setOpenOnMessage(bool $open): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->openOnMessage = $open;
    }

    /**
     * Key that sends a message.
     *
     * @return string One of SEND_KEYS
     */
    public function getSendKey(): string
    {
        return $this->sendKey;
    }

    /**
     * Sets the key that sends a message.
     *
     * @param string $sendKey One of SEND_KEYS
     *
     * @throws InvalidArgumentException When unknown
     */
    public function setSendKey(string $sendKey): void
    {
        if (!in_array($sendKey, self::SEND_KEYS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown chat send key "%s".', $sendKey));
        }
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->sendKey = $sendKey;
    }

    /**
     * Whether Web Push carries the message text (when the application enables `push_preview`).
     *
     * @return bool True to show the text
     */
    public function isPushPreview(): bool
    {
        return $this->pushPreview;
    }

    /**
     * Shows or hides the message text in Web Push.
     *
     * @param bool $preview True to show the text
     */
    public function setPushPreview(bool $preview): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->pushPreview = $preview;
    }

    /**
     * Changes one preference by its key (the JSON settings endpoint): only PREFERENCE_KEYS, with values of their kind.
     *
     * @param string $key One of the PREFERENCE_KEYS
     * @param mixed $value Its new value
     *
     * @throws InvalidArgumentException When the key is unknown or the value invalid
     */
    public function applyPreference(string $key, mixed $value): void
    {
        $kind    = self::PREFERENCE_KEYS[$key] ?? throw new InvalidArgumentException(sprintf('Unknown chat preference "%s".', $key));
        $invalid = new InvalidArgumentException(sprintf('Invalid value for the chat preference "%s".', $key));
        if ($kind === 'bool') {
            if (!is_bool($value)) {
                throw $invalid;
            }
            match ($key) {
                'soundBackgroundOnly' => $this->setSoundBackgroundOnly($value),
                'buzzEnabled'         => $this->setBuzzEnabled($value),
                'buzzOpenWindow'      => $this->setBuzzOpenWindow($value),
                'openOnMessage'       => $this->setOpenOnMessage($value),
                'pushPreview'         => $this->setPushPreview($value),
                'presenceVisible'     => $this->setPresenceVisible($value),
                default               => $this->setEmailDigest($value),
            };

            return;
        }
        if ($kind === 'volume') {
            if (!is_int($value)) {
                throw $invalid;
            }
            $this->setVolume($value);

            return;
        }
        if (!is_string($value)) {
            throw $invalid;
        }
        if ($kind === 'send_key') {
            $this->setSendKey($value);

            return;
        }
        // soundMessage → message, soundMention → mention …
        $this->setSoundFor(lcfirst(substr($key, 5)), $value);
    }

    /**
     * The preferences the browser needs (dock configuration, settings endpoint answer).
     *
     * @return array{sound: string, sounds: array<string, string>, volume: int, backgroundOnly: bool, buzz: bool, buzzOpenWindow: bool, openOnMessage: bool, sendKey: string, pushPreview: bool, presence: bool, push: bool, emailDigest: bool}
     */
    public function preferences(): array
    {
        return [
            'sound'          => $this->sound,
            'sounds'         => ['message' => $this->sound, 'mention' => $this->soundMention, 'buzz' => $this->soundBuzz, 'support' => $this->soundSupport],
            'volume'         => $this->volume,
            'backgroundOnly' => $this->soundBackgroundOnly,
            'buzz'           => $this->buzzEnabled,
            'buzzOpenWindow' => $this->buzzOpenWindow,
            'openOnMessage'  => $this->openOnMessage,
            'sendKey'        => $this->sendKey,
            'pushPreview'    => $this->pushPreview,
            'presence'       => $this->presenceVisible,
            'push'           => $this->pushEnabled,
            'emailDigest'    => $this->emailDigest,
        ];
    }

    /**
     * The status the user picked, back to available once it expired.
     *
     * @param DateTimeImmutable $now Current time
     *
     * @return string One of STATUSES
     */
    public function getStatus(DateTimeImmutable $now): string
    {
        return $this->isStatusExpired($now) ? self::DEFAULT_STATUS : $this->status;
    }

    /**
     * Custom status text, null when none or expired.
     *
     * @param DateTimeImmutable $now Current time
     *
     * @return string|null The text
     */
    public function getStatusText(DateTimeImmutable $now): ?string
    {
        return $this->isStatusExpired($now) ? null : $this->statusText;
    }

    /**
     * Custom status emoji, null when none or expired.
     *
     * @param DateTimeImmutable $now Current time
     *
     * @return string|null The emoji
     */
    public function getStatusEmoji(DateTimeImmutable $now): ?string
    {
        return $this->isStatusExpired($now) ? null : $this->statusEmoji;
    }

    /**
     * When the status goes back to available (null = never, or already expired).
     *
     * @param DateTimeImmutable $now Current time
     *
     * @return DateTimeImmutable|null The expiry
     */
    public function getStatusExpiresAt(DateTimeImmutable $now): ?DateTimeImmutable
    {
        return $this->isStatusExpired($now) ? null : $this->statusExpiresAt;
    }

    /**
     * Sets the status with its optional custom text and emoji (trimmed; empty = none).
     *
     * @param string $status One of STATUSES
     * @param string|null $text Custom text, at most STATUS_TEXT_MAX characters
     * @param string|null $emoji Custom emoji, at most STATUS_EMOJI_MAX characters, no letters or digits
     * @param DateTimeImmutable|null $expiresAt When everything goes back to available; null = never
     *
     * @throws InvalidArgumentException When the status is unknown or the text / emoji invalid
     */
    public function setStatus(string $status, ?string $text = null, ?string $emoji = null, ?DateTimeImmutable $expiresAt = null): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown chat status "%s".', $status));
        }
        $text  = $this->clean($text);
        $emoji = $this->clean($emoji);
        if ($text !== null && mb_strlen($text) > self::STATUS_TEXT_MAX) {
            throw new InvalidArgumentException('The chat status text is too long.');
        }
        // A pictograph, not a second line of text: short and without letters or digits.
        if ($emoji !== null && (mb_strlen($emoji) > self::STATUS_EMOJI_MAX || preg_match('/[\p{L}\p{N}\s]/u', $emoji) === 1)) {
            throw new InvalidArgumentException('The chat status emoji is invalid.');
        }
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->status = $status;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->statusText = $text;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->statusEmoji = $emoji;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->statusExpiresAt = $expiresAt;
    }

    /**
     * The user's own status, as their dock shows it.
     *
     * @param DateTimeImmutable $now Current time
     *
     * @return array{status: string, text: string|null, emoji: string|null, expiresAt: string|null}
     */
    public function statusView(DateTimeImmutable $now): array
    {
        return [
            'status'    => $this->getStatus($now),
            'text'      => $this->getStatusText($now),
            'emoji'     => $this->getStatusEmoji($now),
            'expiresAt' => $this->getStatusExpiresAt($now)?->format(DATE_ATOM),
        ];
    }

    /**
     * When a status set now with that "clear after" choice expires.
     *
     * @param string $clearAfter One of CLEAR_AFTER
     * @param DateTimeImmutable $now Current time (its time zone decides "today" and "this week")
     *
     * @throws InvalidArgumentException When the choice is unknown
     *
     * @return DateTimeImmutable|null The expiry; null for `never`
     */
    public static function statusExpiry(string $clearAfter, DateTimeImmutable $now): ?DateTimeImmutable
    {
        return match ($clearAfter) {
            'never' => null,
            '30m'   => $now->modify('+30 minutes'),
            '1h'    => $now->modify('+1 hour'),
            '4h'    => $now->modify('+4 hours'),
            'today' => $now->setTime(23, 59, 59),
            'week'  => $now->modify('sunday this week')->setTime(23, 59, 59),
            default => throw new InvalidArgumentException(sprintf('Unknown "clear after" choice "%s".', $clearAfter)),
        };
    }

    private function isStatusExpired(DateTimeImmutable $now): bool
    {
        return $this->statusExpiresAt instanceof DateTimeImmutable && $this->statusExpiresAt <= $now;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || preg_match('/\p{Cc}/u', $value) === 1 ? null : $value;
    }
}
