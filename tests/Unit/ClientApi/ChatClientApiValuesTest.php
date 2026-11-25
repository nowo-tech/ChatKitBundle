<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\ClientApi;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\ChatKitBundle\ClientApi\ChatClientApiGuard;
use Nowo\ChatKitBundle\ClientApi\ChatClientApiRouteLoader;
use Nowo\ChatKitBundle\ClientApi\ChatClientCursor;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Event\ChatClientApiDeniedEvent;
use Nowo\ChatKitBundle\Model\ChatClientContext;
use Nowo\ChatKitBundle\Model\ChatClientConversationRef;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;

use function base64_encode;
use function rtrim;
use function str_repeat;
use function strtr;

/**
 * 2.4 (client API v1): the values and pure helpers — the principal, the context, the conversation cursor, the Bearer
 * and IP-prefix readers and the route loader of an API that is off.
 */
final class ChatClientApiValuesTest extends TestCase
{
    public function testThePrincipalKeepsStringScopesOnceAndChecksItsKeys(): void
    {
        $user      = $this->createStub(ChatUserInterface::class);
        $principal = new ChatClientPrincipal($user, ['chat:read', 'chat:read', 7, 'time:read'], 'cred-1', 'org:acme');

        self::assertSame(['chat:read', 'time:read'], $principal->scopes);
        self::assertTrue($principal->hasScope(ChatClientPrincipal::SCOPE_READ));
        self::assertFalse($principal->hasScope('CHAT:READ'));
        self::assertSame('org:acme', $principal->contextKey);

        foreach ([['', null], ['bad id', null], [str_repeat('a', 129), null], ['ok', 'bad key'], ['ok', '']] as [$id, $context]) {
            try {
                new ChatClientPrincipal($user, [], $id, $context);
                self::fail('accepted ' . $id . ' / ' . $context);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheContextCleansItsLabel(): void
    {
        $context = new ChatClientContext('org:acme', "  Acme\u{202E}\nInc " . str_repeat('x', 200));

        self::assertSame('org:acme', $context->key);
        self::assertSame(120, mb_strlen($context->label));
        self::assertStringStartsWith('Acme Inc ', $context->label);
        self::assertSame(['key' => 'org:acme', 'label' => $context->label], $context->toArray());

        $this->expectException(InvalidArgumentException::class);
        new ChatClientContext('-bad', 'Bad');
    }

    public function testTheConversationRefAndTheDeniedEventAreValues(): void
    {
        $ref = new ChatClientConversationRef('u', 'channel', 'project:acme');
        self::assertSame([], $ref->memberPublicIds);
        $event = new ChatClientApiDeniedEvent('rate_limited');
        self::assertNull($event->tokenId);
        self::assertNull($event->userPublicId);
        self::assertNull($event->ipPrefix);
    }

    public function testTheCursorRoundTrips(): void
    {
        $conversation = ChatConversation::channel('project:acme');
        $conversation->touch(new DateTimeImmutable('2026-11-25 10:11:12'));
        (new ReflectionProperty(ChatConversation::class, 'id'))->setValue($conversation, 42);

        $cursor = ChatClientCursor::after($conversation);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $cursor);
        [$time, $id] = ChatClientCursor::read($cursor) ?? self::fail('unreadable');
        self::assertSame('2026-11-25 10:11:12', $time->format('Y-m-d H:i:s'));
        self::assertSame(42, $id);
    }

    #[DataProvider('badCursors')]
    public function testABadCursorIsRefused(string $cursor): void
    {
        self::assertNull(ChatClientCursor::read($cursor));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badCursors(): iterable
    {
        $encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'not base64url' => ['a+b/'];
        yield 'not base64' => ['a'];
        yield 'other version' => [$encode('v2.2026-11-25T10:11:12.4')];
        yield 'id zero' => [$encode('v1.2026-11-25T10:11:12.0')];
        yield 'impossible date' => [$encode('v1.2026-02-30T10:11:12.4')];
    }

    public function testTheBearerReader(): void
    {
        $request = static fn (?string $header): Request => Request::create('/', 'GET', [], [], [], $header === null ? [] : ['HTTP_AUTHORIZATION' => $header]);

        self::assertSame('abc', ChatClientApiGuard::bearer($request('Bearer abc')));
        self::assertSame('abc', ChatClientApiGuard::bearer($request('bearer   abc  ')));
        self::assertNull(ChatClientApiGuard::bearer($request(null)));
        self::assertNull(ChatClientApiGuard::bearer($request('Bearer ')));
        self::assertNull(ChatClientApiGuard::bearer($request('Basic abc')));
        self::assertNull(ChatClientApiGuard::bearer($request('Bearer ' . str_repeat('a', 4097))));
        self::assertSame(str_repeat('a', 4096), ChatClientApiGuard::bearer($request('Bearer ' . str_repeat('a', 4096))));
    }

    public function testTheIpPrefix(): void
    {
        self::assertSame('203.0.113.0/24', ChatClientApiGuard::ipPrefix('203.0.113.77'));
        self::assertSame('2001:db8:1:2::/64', ChatClientApiGuard::ipPrefix('2001:db8:1:2:3:4:5:6'));
        self::assertNull(ChatClientApiGuard::ipPrefix(null));
        self::assertNull(ChatClientApiGuard::ipPrefix('not-an-ip'));
    }

    public function testAnApiThatIsOffHasNoRoute(): void
    {
        $loader = new ChatClientApiRouteLoader(false, '/api');

        self::assertTrue($loader->supports('.', ChatClientApiRouteLoader::TYPE));
        self::assertFalse($loader->supports('.', 'attribute'));
        self::assertCount(0, $loader->load('.', ChatClientApiRouteLoader::TYPE));
    }
}
