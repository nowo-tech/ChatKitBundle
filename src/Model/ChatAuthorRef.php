<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatAuthorBadgeProviderInterface;

/**
 * 1.29 (Beacon spec 234): a message author as {@see ChatAuthorBadgeProviderInterface} sees it — scalars only, no user
 * object, no Doctrine entity, no Symfony type.
 *
 * `reference` is the key the chat stores for the user and compares byte for byte: the integer id as a string in the
 * `relation` and `id` modes, the {@see ChatUserReferenceInterface} reference in the `string` mode. `Bob`, `bob` and
 * `bob ` are three different authors; the provider answers by this exact value.
 */
final readonly class ChatAuthorRef
{
    /**
     * Builds the reference of an author.
     *
     * @param string $reference The user key (1 to 64 characters, ChatUserReference format rule)
     * @param string $publicId The user's public id (ChatUserInterface::getChatPublicId())
     *
     * @throws InvalidArgumentException When the reference breaks the format rule or the public id is empty
     */
    public function __construct(
        public string $reference,
        public string $publicId,
    ) {
        ChatUserReference::assertValid($reference);
        if ($publicId === '') {
            throw new InvalidArgumentException('A chat author needs a public id.');
        }
    }
}
