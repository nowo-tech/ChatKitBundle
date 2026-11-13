<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use function in_array;
use function is_string;

/**
 * 1.27 (REQ-ARCH-001 rule 3): the signed-in user as the framework-free twin of the current-user port
 * (ChatParticipantResolverInterface) sees it, instead of Symfony's `UserInterface`: the user class (without a Doctrine
 * proxy wrapper), the id the object reports (`getId()` as a string; null when it has none), the user identifier and the
 * role names (no role hierarchy). The user object itself is not part of it: a host maps the identifier (or the id) to
 * its chat participant, e.g. through its directory. Built per call by the bundle and never stored.
 */
final readonly class ChatSecurityUser
{
    /** @var list<string> */
    public array $roles;

    /**
     * @param string $userClass Class of the security user (no Doctrine proxy wrapper)
     * @param string|null $id The id the user object reports, as a string; null when it has none
     * @param string $identifier The user identifier (`UserInterface::getUserIdentifier()`)
     * @param array<array-key, mixed> $roles Role names the user reports (non-string values are dropped)
     */
    public function __construct(
        public string $userClass,
        public ?string $id,
        public string $identifier,
        array $roles = [],
    ) {
        $kept = [];
        foreach ($roles as $role) {
            if (is_string($role)) {
                $kept[] = $role;
            }
        }
        $this->roles = $kept;
    }

    /**
     * Whether the user reports this role name (exact match, no hierarchy).
     *
     * @param string $role Role name
     *
     * @return bool True when reported
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
