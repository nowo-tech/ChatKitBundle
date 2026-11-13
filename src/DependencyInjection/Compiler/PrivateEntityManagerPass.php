<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection\Compiler;

use Doctrine\DBAL\Driver\Connection;
use Nowo\ChatKitBundle\Doctrine\PrivateEntityManagerMiddleware;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function interface_exists;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * 1.27 (`doctrine.private_entity_manager: true`): attaches {@see PrivateEntityManagerMiddleware} to the connection of
 * the source manager (`doctrine.entity_manager`: the first argument of its definition; the default connection when it
 * cannot be read) and to no other connection. Runs before DoctrineBundle's MiddlewaresPass. The services are registered
 * only with the option, so without it nothing happens.
 *
 * @internal
 */
final class PrivateEntityManagerPass implements CompilerPassInterface
{
    /**
     * The rollback middleware is written against the doctrine/dbal 4 driver API; with DBAL 3 the private manager runs
     * without it (replaced at the next boundary after a rollback, see docs/CONFIGURATION.md).
     *
     * @return bool True with doctrine/dbal 4
     */
    public static function supportsRollbackMiddleware(): bool
    {
        if (!interface_exists(Connection::class)) {
            return false; // @codeCoverageIgnore
        }

        return (string) (new ReflectionMethod(Connection::class, 'rollBack'))->getReturnType() === 'void';
    }

    /**
     * @param ContainerBuilder $container The container being compiled
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(PrivateEntityManagerMiddleware::class)) {
            return;
        }

        $manager    = $container->getParameter(PrivateEntityManagerReferencesPass::SOURCE_PARAMETER);
        $id         = sprintf('doctrine.orm.%s_entity_manager', is_string($manager) ? $manager : 'default');
        $argument   = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        $connection = $argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1 ? $match[1] : null;
        if ($connection === null) {
            $default    = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;
            $connection = is_string($default) && $default !== '' ? $default : 'default';
        }

        $container->getDefinition(PrivateEntityManagerMiddleware::class)
            ->clearTag('doctrine.middleware')
            ->addTag('doctrine.middleware', ['connection' => $connection]);
    }
}
