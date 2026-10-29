<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatReference;
use Nowo\ChatKitBundle\Model\ChatReferenceScope;

/**
 * One kind of item a message may refer to (`/task`, `#`): issues, tasks, projects, a wiki page … Tag the service
 * `nowo_chat_kit.reference_provider` (autoconfigured). Permissions are the provider's job and deny by default:
 *
 * - filter inside the query by the permissions of EVERY person of `$scope->audience` (never fetch then filter after a
 *   LIMIT, which leaks counts and empty pages);
 * - with `$scope->channelKey()` set, keep the items that belong to that channel only;
 * - return nothing for a person or a type without an explicit permission mapping.
 *
 * Cards are rendered per viewer at read time through resolve() with ChatReferenceScope::reader(): an item the viewer
 * cannot open shows the neutral "Content not visible" chip, never its title.
 */
interface ChatReferenceProviderInterface
{
    /** Service tag of reference providers. */
    public const string TAG = 'nowo_chat_kit.reference_provider';

    /**
     * Type key, used in tokens (`#[Title](key:id)`) and as the command (`/key`): lowercase letters, digits, `_` or
     * `-`, starting with a letter, at most 32 characters.
     *
     * @return string The key
     */
    public function key(): string;

    /**
     * Label of the command and of the card's type, translated for the current request.
     *
     * @return string The label
     */
    public function label(): string;

    /**
     * Icon of the command and the card: a built-in dock icon (issue, task, project, monitor, report, ticket, page,
     * link) or one the application overrides with `<template data-nowo-chat-icon>`.
     *
     * @return string The icon name
     */
    public function icon(): string;

    /**
     * Whether the command is offered: the audience may open at least one item of this type in the scope (no `/monitor`
     * for someone without monitor access anywhere).
     *
     * @param ChatReferenceScope $scope Viewer, audience and conversation
     *
     * @return bool True to list the command
     */
    public function isAvailable(ChatReferenceScope $scope): bool;

    /**
     * Items matching the query that every person of the audience may open, best matches first.
     *
     * @param ChatReferenceScope $scope Viewer, audience and conversation
     * @param string $query Text typed after the command (may be empty: recent items)
     * @param int $limit Maximum number of items
     *
     * @return list<ChatReference> The items
     */
    public function search(ChatReferenceScope $scope, string $query, int $limit): array;

    /**
     * The given items that every person of the audience may open (within the channel when the scope has one).
     *
     * @param ChatReferenceScope $scope Viewer, audience and conversation (reader scope at render time)
     * @param list<string> $ids Item identifiers (at most a few dozen)
     *
     * @return array<array-key, ChatReference> id => item (numeric ids may come back as int keys); unknown and unreadable ids are left out
     */
    public function resolve(ChatReferenceScope $scope, array $ids): array;
}
