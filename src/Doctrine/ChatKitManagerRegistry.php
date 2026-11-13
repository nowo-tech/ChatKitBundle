<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;

use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;

/**
 * 1.27 (REQ-ARCH-001 rule 6, private entity manager only): the Doctrine registry the ChatKit services see. The source
 * manager's name (`doctrine.entity_manager`) and the ChatKit entity classes resolve to {@see ChatKitEntityManager},
 * everything else to the application's registry unchanged. Resetting the source manager through it drops the private
 * one; the application's managers are never reset by the bundle through this registry. Adapted from
 * TaskBoardBundle 2.15.0 / AuthKitBundle 1.46.0.
 *
 * @internal
 */
final readonly class ChatKitManagerRegistry implements ManagerRegistry
{
    /** The namespace of the bundle's entities. */
    public const string ENTITY_NAMESPACE = 'Nowo\\ChatKitBundle\\Entity\\';

    /** Namespace marker of Doctrine's generated proxy classes. */
    private const string PROXY_MARKER = '\\__CG__\\';

    /**
     * @param ManagerRegistry $registry The application's Doctrine registry
     * @param ChatKitEntityManager $entityManager The bundle's private manager
     * @param string $managerName the source manager of the private one (`doctrine.entity_manager`)
     */
    public function __construct(
        private ManagerRegistry $registry,
        private ChatKitEntityManager $entityManager,
        private string $managerName = 'default',
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function getDefaultConnectionName(): string
    {
        return $this->registry->getDefaultConnectionName();
    }

    /**
     * {@inheritDoc}
     */
    public function getConnection(?string $name = null): object
    {
        return $this->registry->getConnection($name);
    }

    /**
     * {@inheritDoc}
     */
    public function getConnections(): array
    {
        return $this->registry->getConnections();
    }

    /**
     * {@inheritDoc}
     */
    public function getConnectionNames(): array
    {
        return $this->registry->getConnectionNames();
    }

    /**
     * {@inheritDoc}
     */
    public function getDefaultManagerName(): string
    {
        return $this->registry->getDefaultManagerName();
    }

    /**
     * The private manager for the source manager's name; any other manager unchanged.
     *
     * @param string|null $name Manager name; null for the default one
     *
     * @return ObjectManager The manager
     */
    public function getManager(?string $name = null): ObjectManager
    {
        if ($this->isBundleManager($name)) {
            return $this->entityManager;
        }

        return $this->registry->getManager($name);
    }

    /**
     * {@inheritDoc}
     */
    public function getManagers(): array
    {
        return $this->registry->getManagers();
    }

    /**
     * The source manager's name: drops the private manager (a fresh one is created on the next call). Any other name:
     * resets that application manager on the application's behalf only (the bundle itself never asks for it).
     *
     * @param string|null $name Manager name; null for the default one
     *
     * @return ObjectManager The manager
     */
    public function resetManager(?string $name = null): ObjectManager
    {
        if ($this->isBundleManager($name)) {
            $this->entityManager->reset();

            return $this->entityManager;
        }

        return $this->registry->resetManager($name);
    }

    /**
     * {@inheritDoc}
     */
    public function getManagerNames(): array
    {
        return $this->registry->getManagerNames();
    }

    /**
     * The private manager's repository for a ChatKit entity; any other unchanged.
     *
     * @template T of object
     *
     * @param class-string<T> $persistentObject The class
     * @param string|null $persistentManagerName Manager name
     *
     * @return ObjectRepository<T> The repository
     */
    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        if (($persistentManagerName === null || $this->isBundleManager($persistentManagerName)) && self::isBundleEntity($persistentObject)) {
            return $this->entityManager->getRepository($persistentObject);
        }

        return $this->registry->getRepository($persistentObject, $persistentManagerName);
    }

    /**
     * The private manager for a ChatKit entity class; any other unchanged.
     *
     * @param class-string $class The class
     *
     * @return ObjectManager|null The manager
     */
    public function getManagerForClass(string $class): ?ObjectManager
    {
        if (self::isBundleEntity($class)) {
            return $this->entityManager;
        }

        return $this->registry->getManagerForClass($class);
    }

    private function isBundleManager(?string $name): bool
    {
        return ($name ?? $this->registry->getDefaultManagerName()) === $this->managerName;
    }

    /**
     * Whether a class (or a Doctrine proxy class of it) is a ChatKit entity.
     *
     * @param string $class The class
     *
     * @return bool True for `Nowo\ChatKitBundle\Entity\*`
     */
    public static function isBundleEntity(string $class): bool
    {
        return str_starts_with(self::realClass($class), self::ENTITY_NAMESPACE);
    }

    /**
     * The entity class of a Doctrine proxy class name, or the class itself.
     *
     * @param string $class The class
     *
     * @return string The real class
     */
    public static function realClass(string $class): string
    {
        $marker = strrpos($class, self::PROXY_MARKER);

        return $marker !== false ? substr($class, $marker + strlen(self::PROXY_MARKER)) : $class;
    }
}
