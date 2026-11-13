<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Port;

use Nowo\ChatKitBundle\Model\ChatRequestContext;
use Nowo\ChatKitBundle\Model\ChatSecurityUser;
use Nowo\ChatKitBundle\Port\ChatRequestContexts;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.27: the values the port twins get instead of the HttpFoundation request and the Symfony user.
 */
final class ChatRequestContextsTest extends TestCase
{
    public function testTheRequestIsCopiedWithoutHeadersCookiesOrBody(): void
    {
        $request = Request::create('https://app.example.test:8443/projects/acme?tab=chat&list[]=1', 'POST', [], ['PHPSESSID' => 'secret'], [], ['HTTP_AUTHORIZATION' => 'Bearer secret'], 'body');
        $request->attributes->set('_route', 'project_show');
        $request->attributes->set('_route_params', ['slug' => 'acme', 'page' => 2, 'object' => new stdClass(), 3 => 'x']);
        $request->setLocale('es');

        $context = ChatRequestContexts::fromRequest($request);

        self::assertSame('POST', $context->method);
        self::assertSame('https', $context->scheme);
        self::assertSame('app.example.test', $context->host);
        self::assertSame(8443, $context->port);
        self::assertSame('', $context->basePath);
        self::assertSame('/projects/acme', $context->path);
        self::assertSame('project_show', $context->route);
        self::assertSame(['slug' => 'acme', 'page' => 2], $context->routeParams);
        self::assertSame('acme', $context->routeParam('slug'));
        self::assertNull($context->routeParam('missing'));
        self::assertSame(['tab' => 'chat'], $context->query, 'array query values are left out');
        self::assertSame('es', $context->locale);
        self::assertSame('https://app.example.test:8443', $context->origin());
        self::assertStringNotContainsString('secret', serialize($context));
    }

    public function testDefaultPortsAreLeftOutOfTheOrigin(): void
    {
        self::assertSame('https://a.test', (new ChatRequestContext('GET', 'https', 'a.test', 443, '', '/'))->origin());
        self::assertSame('http://a.test', (new ChatRequestContext('GET', 'http', 'a.test', 80, '', '/'))->origin());
        self::assertSame('http://a.test:443', (new ChatRequestContext('GET', 'http', 'a.test', 443, '', '/'))->origin());
        $bare = ChatRequestContexts::fromRequest(Request::create('/x'));
        self::assertNull($bare->route);
        self::assertSame([], $bare->routeParams);
    }

    public function testTheSecurityUserIsCopiedAsAValue(): void
    {
        self::assertNull(ChatRequestContexts::fromUser(null));

        $plain = ChatRequestContexts::fromUser(new InMemoryUser('ana', null, ['ROLE_ADMIN']));
        self::assertInstanceOf(ChatSecurityUser::class, $plain);
        self::assertSame(InMemoryUser::class, $plain->userClass);
        self::assertNull($plain->id);
        self::assertSame('ana', $plain->identifier);
        self::assertTrue($plain->hasRole('ROLE_ADMIN'));
        self::assertFalse($plain->hasRole('ROLE_admin'));

        self::assertSame('42', ChatRequestContexts::fromUser(new IdUser(42))?->id);
        self::assertSame('0198-abc', ChatRequestContexts::fromUser(new IdUser('0198-abc'))?->id);
        self::assertNull(ChatRequestContexts::fromUser(new IdUser(' '))?->id, 'a blank id counts as none');
        self::assertNull(ChatRequestContexts::fromUser(new IdUser(null))?->id);
    }

    public function testNonStringRolesAreDropped(): void
    {
        self::assertSame(['ROLE_A'], (new ChatSecurityUser('X', null, 'x', ['ROLE_A', 3, null]))->roles);
    }
}

/**
 * A security user with a getId().
 */
final readonly class IdUser implements UserInterface
{
    public function __construct(private int|string|null $id)
    {
    }

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'id-user';
    }
}
