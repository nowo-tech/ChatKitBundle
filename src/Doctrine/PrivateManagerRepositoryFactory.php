<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Repository\RepositoryFactory;

use function is_a;

/**
 * 1.27: the repository factory of ONE private manager ({@see ChatKitEntityManager}; audit NZ's corrected pattern).
 *
 * DoctrineBundle's shared ContainerRepositoryFactory (and ORM's DefaultRepositoryFactory) keep one repository per manager
 * instance for the life of the process, so every replaced private manager would stay in memory in a kept-alive worker.
 * This factory belongs to one manager, so what it keeps is collected with that manager:
 *
 * - a service repository (`ServiceEntityRepositoryInterface`: the bundle's own repositories, or a host entity mapped on
 *   the same configuration) is resolved by the source configuration's factory: the container service, not keyed by
 *   manager, so it does not leak;
 * - any other repository is built here, with the mapped `repositoryClass` or the configuration's default one, and kept.
 *
 * @internal
 */
final class PrivateManagerRepositoryFactory implements RepositoryFactory
{
    /** @var array<class-string, EntityRepository<object>> */
    private array $repositories = [];

    /**
     * @param RepositoryFactory $source the repository factory of the source configuration (the application's)
     */
    public function __construct(
        private readonly RepositoryFactory $source,
    ) {
    }

    /**
     * The repository of an entity class for this manager.
     *
     * @template T of object
     *
     * @param EntityManagerInterface $entityManager The manager
     * @param class-string<T> $entityName The entity class
     *
     * @return EntityRepository<T> The repository
     */
    public function getRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
    {
        $metadata = $entityManager->getClassMetadata($entityName);
        $class    = $metadata->customRepositoryClassName;
        if ($class !== null && is_a($class, ServiceEntityRepositoryInterface::class, true)) {
            /** @var EntityRepository<T> $service */
            $service = $this->source->getRepository($entityManager, $entityName);

            return $service;
        }

        /** @var EntityRepository<T> $repository */
        // @igor-ignore - Per-manager cache: this factory belongs to one private manager and is collected with it.
        $repository = $this->repositories[$metadata->getName()] ??= self::create($entityManager, $metadata, $class ?? $entityManager->getConfiguration()->getDefaultRepositoryClassName());

        return $repository;
    }

    /**
     * Builds a plain repository.
     *
     * @param EntityManagerInterface $entityManager The manager
     * @param ClassMetadata<object> $metadata The entity metadata
     * @param class-string<EntityRepository<object>> $class The repository class
     *
     * @return EntityRepository<object> The repository
     */
    public static function create(EntityManagerInterface $entityManager, ClassMetadata $metadata, string $class): EntityRepository
    {
        return new $class($entityManager, $metadata);
    }
}
