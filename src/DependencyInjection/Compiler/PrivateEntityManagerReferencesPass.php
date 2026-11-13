<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection\Compiler;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Doctrine\ChatKitManagerRegistry;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function in_array;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * 1.27 (`doctrine.private_entity_manager: true`, REQ-ARCH-001 rule 6): every service of the bundle (a class of the
 * `Nowo\ChatKitBundle\` namespace, its inline definitions included) that was given the source entity manager
 * (`doctrine.entity_manager`, whether explicitly, through `doctrine.orm.entity_manager` or by autowiring
 * `EntityManagerInterface`) gets {@see ChatKitEntityManager} instead, and the Doctrine registry (`doctrine`,
 * `ManagerRegistry`) becomes {@see ChatKitManagerRegistry}. Host services are never touched: the application's manager
 * is still the one they get. Adapted from TaskBoardBundle 2.15.0.
 *
 * Runs after autowiring (before the removing passes), so autowired arguments are rewritten too. Without the option the
 * parameter is false and nothing changes.
 *
 * @internal
 */
final class PrivateEntityManagerReferencesPass implements CompilerPassInterface
{
    /** Whether `doctrine.private_entity_manager` is true. */
    public const string PARAMETER = 'nowo_chat_kit.doctrine.private_entity_manager';

    /** `doctrine.entity_manager`: the source of the private manager. */
    public const string SOURCE_PARAMETER = 'nowo_chat_kit.doctrine.entity_manager';

    /** The services the bundle registers for the private manager: they keep the application's registry and manager. */
    private const array OWN = [ChatKitEntityManager::class, ChatKitManagerRegistry::class];

    /**
     * @param ContainerBuilder $container The container being compiled
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PARAMETER) || $container->getParameter(self::PARAMETER) !== true) {
            return;
        }

        $source   = $container->getParameter(self::SOURCE_PARAMETER);
        $managers = [sprintf('doctrine.orm.%s_entity_manager', is_string($source) ? $source : 'default') => true];
        if ($container->hasAlias(EntityManagerInterface::class)) {
            $managers[$this->resolve($container, EntityManagerInterface::class)] = true;
        }
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;
            if (!is_string($class) || !str_starts_with($class, 'Nowo\\ChatKitBundle\\') || str_starts_with($class, 'Nowo\\ChatKitBundle\\Tests\\') || in_array($class, self::OWN, true)) {
                continue;
            }
            $this->rewrite($container, $definition, $managers);
        }
    }

    /**
     * @param array<string, true> $managers the ids of the managers the bundle's services are moved off
     */
    private function rewrite(ContainerBuilder $container, Definition $definition, array $managers): void
    {
        /** @var array<int|string, mixed> $arguments */
        $arguments = $this->replace($container, $definition->getArguments(), $managers);
        $definition->setArguments($arguments);
        /** @var array<string, mixed> $properties */
        $properties = $this->replace($container, $definition->getProperties(), $managers);
        $definition->setProperties($properties);
        $calls = [];
        foreach ($definition->getMethodCalls() as $call) {
            $call[1] = $this->replace($container, $call[1], $managers);
            $calls[] = $call;
        }
        /* @var list<array{0: string, 1: array<mixed>, 2?: bool}> $calls */
        $definition->setMethodCalls($calls);
    }

    /**
     * @param array<string, true> $managers
     */
    private function replace(ContainerBuilder $container, mixed $value, array $managers): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->replace($container, $item, $managers);
            }

            return $value;
        }
        if ($value instanceof ArgumentInterface) {
            /** @var array<int|string, mixed> $values */
            $values = $this->replace($container, $value->getValues(), $managers);
            $value->setValues($values);

            return $value;
        }
        if ($value instanceof Definition) {
            $this->rewrite($container, $value, $managers);

            return $value;
        }
        if (!$value instanceof Reference) {
            return $value;
        }

        $target = $this->resolve($container, (string) $value);

        return match (true) {
            isset($managers[$target]) => new Reference(ChatKitEntityManager::class, $value->getInvalidBehavior()),
            $target === 'doctrine'    => new Reference(ChatKitManagerRegistry::class, $value->getInvalidBehavior()),
            default                   => $value,
        };
    }

    private function resolve(ContainerBuilder $container, string $id): string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id        = (string) $container->getAlias($id);
        }

        return $id;
    }
}
