<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * The application's user entity implements this; the bundle maps it to `nowo_chat_kit.user_class` through
 * `doctrine.orm.resolve_target_entities`, so chat tables reference the host user table directly.
 */
interface ChatUserInterface
{
    /** Doctrine identifier (integer primary key). */
    public function getId(): ?int;

    /** Stable public identifier used in URLs, the API and realtime topics (e.g. a UUID). Never the numeric id. */
    public function getChatPublicId(): string;

    public function getChatDisplayName(): string;

    /** One or two letters for the avatar bubble. */
    public function getChatInitials(): string;

    /** Shown as a subtitle to people allowed to message this user; null hides it. */
    public function getChatEmail(): ?string;

    /** Locale for texts sent outside a request (Web Push); null uses the default locale. */
    public function getChatLocale(): ?string;
}
