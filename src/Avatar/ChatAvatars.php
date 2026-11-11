<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Avatar;

use Nowo\ChatKitBundle\Contract\ChatAvatarUrlResolverInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_key_exists;
use function array_values;
use function is_string;

/**
 * Profile picture URLs for one request (1.16): the callers prime the people of a response after the access filters,
 * so the host resolver gets exactly one urlsFor() call per response; the presenter then reads the memo. Every value is
 * checked by AvatarUrlPolicy before it is kept. A resolver that throws gives null for the whole batch: a broken picture
 * service never breaks the chat. The memo is emptied at every request / command / message boundary (ChatRequestBoundaryListener, 1.26.3) and by
 * `kernel.reset`.
 *
 * @internal
 */
final class ChatAvatars implements ResetInterface
{
    /** Largest CSS size of a ChatKit avatar (the empty-thread bubble); one size keeps one call per response. */
    public const int SIZE = 56;

    /** @var array<string, string|null> public id => sanitised URL (null: initials) */
    private array $urls = [];

    /**
     * Builds the memo on the host resolver.
     *
     * @param ChatAvatarUrlResolverInterface $resolver The application's resolver (default: none)
     * @param LoggerInterface $logger Warning when the resolver fails, debug line for a rejected URL (never the URL)
     */
    public function __construct(
        private readonly ChatAvatarUrlResolverInterface $resolver,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Resolves every not-yet-known user of the list in one urlsFor() call (none when every user is known).
     *
     * @param iterable<ChatUserInterface|null> $users The people of the response; null entries are skipped
     */
    public function prime(iterable $users): void
    {
        $missing = [];
        foreach ($users as $user) {
            if ($user instanceof ChatUserInterface && !array_key_exists($user->getChatPublicId(), $this->urls)) {
                $missing[$user->getChatPublicId()] = $user;
            }
        }
        if ($missing === []) {
            return;
        }
        try {
            $resolved = $this->resolver->urlsFor(array_values($missing), self::SIZE);
        } catch (Throwable $exception) {
            $this->logger->warning('Chat avatar resolver failed: {error}', ['error' => $exception::class]);
            $resolved = [];
        }
        foreach ($missing as $id => $user) {
            $given = $resolved[$id] ?? null;
            $url   = AvatarUrlPolicy::sanitize(is_string($given) ? $given : null);
            if ($url === null && is_string($given) && $given !== '') {
                $this->logger->debug('Chat avatar URL rejected for {user}', ['user' => $id]);
            }
            $this->urls[$id] = $url;
        }
    }

    /**
     * The memoised URL of a person's picture; a person that was not primed is resolved now (one more call).
     *
     * @param ChatUserInterface|null $user The person; null (deleted or erased author) gives null
     *
     * @return string|null The URL, or null for the initials
     */
    public function urlOf(?ChatUserInterface $user): ?string
    {
        if (!$user instanceof ChatUserInterface) {
            return null;
        }
        $this->prime([$user]);

        return $this->urls[$user->getChatPublicId()] ?? null;
    }

    /**
     * Forgets the URLs of the finished request (worker mode).
     */
    public function reset(): void
    {
        $this->urls = [];
    }
}
