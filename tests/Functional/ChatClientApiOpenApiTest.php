<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

use function array_keys;
use function dirname;
use function is_array;
use function sort;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * 2.4 (D-20261110-04 rule 4): docs/openapi/chat-client-v1.yaml documents exactly the client API v1 routes (paths and
 * methods), under the default prefix.
 */
final class ChatClientApiOpenApiTest extends DatabaseTestCase
{
    use ClientApiCalls;

    protected function chatKitConfig(): array
    {
        return self::clientApiConfig();
    }

    public function testTheOpenApiDocumentMatchesTheRoutes(): void
    {
        $spec = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi/chat-client-v1.yaml');
        self::assertIsArray($spec);
        self::assertSame('/api/chat/v1', $spec['servers'][0]['url']);

        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            self::assertIsArray($operations);
            foreach (array_keys($operations) as $method) {
                $documented[] = strtolower((string) $method) . ' ' . $path;
            }
        }

        $router = $this->kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $routed = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (!str_starts_with($name, 'nowo_chat_api_v1_')) {
                continue;
            }
            self::assertStringStartsWith('/api/chat/v1/', $route->getPath());
            foreach ($route->getMethods() as $method) {
                $routed[] = strtolower($method) . ' ' . substr($route->getPath(), strlen('/api/chat/v1'));
            }
        }
        sort($documented);
        sort($routed);
        self::assertSame($documented, $routed);
        self::assertTrue(is_array($spec['components']['schemas']['Token']));
    }
}
