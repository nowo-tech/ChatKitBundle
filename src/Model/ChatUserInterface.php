<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * The application's user entity implements this; the bundle maps it to `nowo_chat_kit.user_class` through
 * `doctrine.orm.resolve_target_entities`, so chat tables reference the host user table directly.
 */
interface ChatUserInterface
{
    /**
     * Doctrine identifier (integer primary key).
     *
     * @return int|null The id; null before the entity is persisted
     */
    public function getId(): ?int;

    /**
     * Stable public identifier used in URLs, the API and realtime topics (e.g. a UUID). Never the numeric id.
     *
     * @return string The public identifier
     */
    public function getChatPublicId(): string;

    /**
     * Name shown for this user in conversations, the people picker and notifications.
     *
     * @return string The display name
     */
    public function getChatDisplayName(): string;

    /**
     * One or two letters for the avatar bubble.
     *
     * @return string The initials
     */
    public function getChatInitials(): string;

    /**
     * Shown as a subtitle to people allowed to message this user; null hides it.
     *
     * @return string|null The email address, or null
     */
    public function getChatEmail(): ?string;

    /**
     * Locale for texts sent outside a request (Web Push); null uses the default locale.
     *
     * @return string|null The locale code, or null
     */
    public function getChatLocale(): ?string;
}
