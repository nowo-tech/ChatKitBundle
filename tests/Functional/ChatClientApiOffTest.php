<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\ClientApi\ChatClientApiGuard;
use Nowo\ChatKitBundle\Http\ChatApiException;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * 2.4 (client API v1): deny by default. Without a principal resolver the API is off: the host's route import yields no
 * route, the guard answers 404, and the path parameter still exists for security.yaml.
 */
final class ChatClientApiOffTest extends DatabaseTestCase
{
    public function testTheApiIsOffWithoutAResolver(): void
    {
        $container = $this->kernel->getContainer();
        self::assertFalse($container->getParameter('nowo_chat_kit.client_api.enabled'));
        self::assertSame('/api/chat/v1', $container->getParameter('nowo_chat_kit.client_api.v1_path_prefix'));
        self::assertSame('X-Chat-Context', $container->getParameter('nowo_chat_kit.client_api.context_header'));

        $router = $container->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            self::assertStringStartsNotWith('nowo_chat_api_v1_', $name);
        }

        $guard = $this->get(ChatClientApiGuard::class);
        self::assertFalse($guard->enabled());
        self::assertNull($guard->contexts());
        try {
            $guard->authenticate(Request::create('/api/chat/v1/me', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer any']));
            self::fail('no refusal');
        } catch (ChatApiException $exception) {
            self::assertSame('not_found', $exception->getMessage());
            self::assertSame(404, $exception->getCode());
        }
    }
}
