<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Realtime;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

use function class_exists;

use const JSON_THROW_ON_ERROR;

/**
 * Default transport when symfony/mercure-bundle is installed: private updates on the default hub.
 */
final readonly class MercureChatRealtime implements ChatRealtimeInterface
{
    /**
     * Builds the transport on top of the default Mercure hub.
     *
     * @param HubInterface $hub The Mercure hub
     * @param bool|null $grantTokens Whether the token factory takes Grant objects (symfony/mercure >= 0.9);
     *                               null detects it from the installed library
     */
    public function __construct(
        private HubInterface $hub,
        private ?bool $grantTokens = null,
    ) {
    }

    /**
     * Always enabled when Mercure is installed.
     *
     * @return bool Always true
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Publishes the JSON-encoded payload as a private Mercure update.
     *
     * @param list<string> $topics Per-user private topics
     * @param array<string, mixed> $payload Event data
     */
    public function publish(array $topics, array $payload): void
    {
        $this->hub->publish(new Update($topics, json_encode($payload, JSON_THROW_ON_ERROR), true));
    }

    /**
     * Returns the hub's public URL.
     *
     * @param Request $request Unused; the hub configuration defines the URL
     *
     * @return string The public hub URL
     */
    public function browserHubUrl(Request $request): string
    {
        return $this->hub->getPublicUrl();
    }

    /**
     * Creates a subscriber JWT with the hub's token factory.
     *
     * @param list<string> $topics Topics the token may subscribe to
     * @param DateTimeImmutable $expiresAt Token expiry (`exp` claim)
     *
     * @return string|null The JWT, or null when the hub has no token factory
     */
    public function subscriberToken(array $topics, DateTimeImmutable $expiresAt): ?string
    {
        $factory = $this->hub->getFactory();
        if (!$factory instanceof TokenFactoryInterface) {
            return null;
        }

        if ($this->grantTokens ?? class_exists(Grant::class)) {
            // symfony/mercure >= 0.9: create(Grant[] $grants, array $additionalClaims).
            return $factory->create([new Grant([Grant::ACTION_SUBSCRIBE], $topics)], ['exp' => $expiresAt]);
        }

        // symfony/mercure 0.6: create(?array $subscribe, ?array $publish, array $additionalClaims).
        // @phpstan-ignore arguments.count, argument.type
        return $factory->create($topics, [], ['exp' => $expiresAt]);
    }
}
