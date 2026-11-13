<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Closure;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepositoryInterface;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Query\Filter\Parameter;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Worker\ChatRequestBoundaryListener;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_keys;
use function array_sum;
use function in_array;
use function is_a;
use function is_array;
use function is_bool;
use function is_object;
use function is_string;
use function ksort;
use function sprintf;

/**
 * ChatKit's private entity manager (1.27, REQ-ARCH-001 rule 6; opt-in with
 * `nowo_chat_kit.profiles.<profile>.doctrine.private_entity_manager: true` until 2.0). Adapted from TaskBoardBundle 2.15.0
 * and AuthKitBundle 1.46.0 (audits NV, NJ-2, NJV-1, NF-2, NF2-2, NF3-2, NF-7) with audit NZ's repository factory.
 *
 * Up to 1.26 the bundle wrote its rows through the application's default manager: every chat write flushed whatever the
 * application had left pending in that unit of work, and a failed chat write closed the application's manager. With the
 * option on, every ChatKit service writes and reads through this **private** manager, created on the connection,
 * mapping, caches, proxies and event manager of the source manager (`doctrine.entity_manager`): the tables do not move,
 * the bundle's Doctrine listeners (table prefix, channel-key collation, user references) and the host's listeners still
 * run, and a chat flush never commits an application change. The connection is shared, so a transaction the application
 * opened includes the chat rows (the bundle's own transactions become savepoints).
 *
 * The object is a stable decorator (the repositories and services keep it); the wrapped manager is resolved on every
 * call and created again when:
 *
 * - it was closed (its own failed flush) or the source connection changed;
 * - a rollback (transaction or savepoint) undid rows it wrote inside a transaction ({@see PrivateEntityManagerMiddleware};
 *   DBAL 4 only, with DBAL 3 the manager is only replaced at the next boundary). A stale manager that still has scheduled
 *   work is kept until that work is flushed or the next boundary;
 * - {@see reset()} ran: at every request / message / command boundary ({@see ChatRequestBoundaryListener}, after the
 *   memos) and at `kernel.reset`. Changes still pending at that moment are dropped and logged as a warning (entity
 *   classes and counts, never a value).
 *
 * Host objects reached from chat rows (the `user_class` author, participant, ... in `relation` mode) are adopted before
 * each flush: an instance the private manager does not hold (e.g. the security user, managed by the application's
 * manager) is replaced on the chat row by the private manager's reference to the same id, so Doctrine never mistakes it
 * for a new entity. The application's instance is never written.
 *
 * The SQL filters enabled on the source manager, with their parameters, are mirrored on every call.
 *
 * @internal
 */
final class ChatKitEntityManager extends EntityManagerDecorator implements ResetInterface
{
    private ?EntityManagerInterface $current = null;

    /** A rollback undid rows the private manager wrote since it was created or last replaced. */
    private bool $stale = false;

    /**
     * Transaction nesting levels of the connection in which the private manager wrote or loaded rows that nobody
     * committed yet (audits NJ-2, NJV-1).
     *
     * @var array<int, true>
     */
    private array $uncommitted = [];

    /**
     * Repositories that are not container services, bound to this decorator (stable across the replacements of the
     * private manager). Bounded by the number of mapped classes asked for; emptied by {@see reset()}.
     *
     * @var array<class-string, EntityRepository<object>>
     */
    private array $repositories = [];

    /**
     * @param ManagerRegistry $registry The application's Doctrine registry (the source manager is resolved per call)
     * @param string $sourceManagerName `doctrine.entity_manager` (the manager 1.26 wrote the chat rows through)
     * @param LoggerInterface|null $logger warns when a boundary drops pending changes
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly string $sourceManagerName = 'default',
        private readonly ?LoggerInterface $logger = null,
    ) {
        // No wrapped manager yet: every read of $this->wrapped goes through __get() (resolved per call).
        unset($this->{'wrapped'});
    }

    /**
     * The wrapped manager of {@see EntityManagerDecorator} (the only magic property).
     *
     * @param string $name Property name (`wrapped`)
     *
     * @return EntityManagerInterface The live private manager
     */
    public function __get(string $name): EntityManagerInterface
    {
        if ($name !== 'wrapped') {
            throw new LogicException(sprintf('Undefined property %s::$%s.', self::class, $name));
        }

        return $this->live();
    }

    /**
     * The open private manager, created (again) when needed, with the source's SQL filters.
     *
     * @return EntityManagerInterface The manager
     */
    public function live(): EntityManagerInterface
    {
        $source  = $this->source();
        $current = $this->current;
        if (!$current instanceof EntityManagerInterface || !$current->isOpen() || $current->getConnection() !== $source->getConnection()
            || ($this->stale && !$this->hasScheduledWork($current))) {
            if ($current instanceof EntityManagerInterface && $current->isOpen()) {
                $current->clear();
            }
            // DoctrineBundle's ContainerAwareEventManager extends EventManager; any other implementation gets a fresh one.
            $eventManager = $source->getEventManager();
            // Same mapping, caches and proxies, but a repository factory owned by this manager (audit NZ).
            $configuration = clone $source->getConfiguration();
            $configuration->setRepositoryFactory(new PrivateManagerRepositoryFactory($configuration->getRepositoryFactory()));
            // @igor-ignore - Bundle-owned unit of work: dropped at every boundary, replaced when closed, stale or on another connection.
            $current = $this->current = new EntityManager($source->getConnection(), $configuration, $eventManager instanceof EventManager ? $eventManager : null);
            // @igor-ignore - Bundle-owned flag of the private manager, reset when the manager is replaced.
            $this->stale = false;
            // @igor-ignore - Bundle-owned bookkeeping, reset when the manager is replaced.
            $this->uncommitted = [];
        }
        $this->syncFilters($source, $current);

        return $current;
    }

    /**
     * Whether a private manager exists right now (no side effect; tests and the boundary listener).
     *
     * @return bool True once created and not dropped
     */
    public function isCreated(): bool
    {
        return $this->current instanceof EntityManagerInterface;
    }

    /**
     * Whether a rollback made the private manager's identity map stale (no side effect; tests).
     *
     * @return bool True when stale
     */
    public function isStale(): bool
    {
        return $this->stale;
    }

    /**
     * A transaction (level 1) or savepoint (its level; null when unknown) was rolled back on the connection. The manager
     * becomes stale only when the rollback undid rows it wrote; it is never cleared here.
     *
     * @param int|null $level The rolled-back level; null when unknown
     */
    public function connectionRolledBack(?int $level = 1): void
    {
        $undone = false;
        foreach (array_keys($this->uncommitted) as $written) {
            if ($level === null || $written >= $level) {
                $undone = true;
                // @igor-ignore - Bundle-owned bookkeeping of the private manager's open transaction levels.
                unset($this->uncommitted[$written]);
            }
        }
        if ($undone && $this->current instanceof EntityManagerInterface) {
            // @igor-ignore - Bundle-owned flag of the private manager, cleared when the manager is replaced.
            $this->stale = true;
        }
    }

    /**
     * The outermost transaction was committed: every row the private manager wrote is durable.
     */
    public function connectionCommitted(): void
    {
        // @igor-ignore - Bundle-owned bookkeeping of the private manager's open transaction levels.
        $this->uncommitted = [];
    }

    /**
     * A savepoint of this level was released: its rows now belong to the level below.
     *
     * @param int $level The released level
     */
    public function savepointReleased(int $level): void
    {
        if (isset($this->uncommitted[$level])) {
            // @igor-ignore - Bundle-owned bookkeeping of the private manager's open transaction levels.
            unset($this->uncommitted[$level]);
            if ($level > 1) {
                // @igor-ignore - Bundle-owned bookkeeping of the private manager's open transaction levels.
                $this->uncommitted[$level - 1] = true;
            }
        }
    }

    /**
     * Service repositories come from the source configuration's factory (audit NZ); every other repository is built on
     * this decorator and kept until {@see reset()}.
     *
     * @template T of object
     *
     * @param class-string<T> $className The entity class
     *
     * @return EntityRepository<T> The repository
     */
    public function getRepository(string $className): EntityRepository
    {
        $live     = $this->live();
        $metadata = $live->getClassMetadata($className);
        $class    = $metadata->customRepositoryClassName;
        if ($class !== null && is_a($class, ServiceEntityRepositoryInterface::class, true)) {
            return $live->getRepository($className);
        }

        /** @var EntityRepository<T> $repository */
        $repository = $this->repositories[$metadata->getName()] ??= PrivateManagerRepositoryFactory::create($this, $metadata, $class ?? $live->getConfiguration()->getDefaultRepositoryClassName());

        return $repository;
    }

    /**
     * Flushes the private manager (host objects on chat rows adopted first).
     */
    public function flush(): void
    {
        $live = $this->live();
        $this->adoptForeignReferences($live);
        $live->flush();
        $this->wrote();
    }

    /**
     * Refreshes an entity from the database.
     *
     * @param object $object The entity
     * @param int|LockMode|null $lockMode The lock mode
     */
    public function refresh(object $object, LockMode|int|null $lockMode = LockMode::NONE): void
    {
        $this->live()->refresh($object, $lockMode);
        $this->wrote();
    }

    /**
     * A ChatKit statement that bypassed the unit of work (bulk DQL, DBAL) wrote at the current level (audit NJV-1).
     */
    public function noteWrite(): void
    {
        $this->wrote();
    }

    /**
     * Rows loaded by the private manager inside a transaction may be the application's uncommitted writes: a rollback
     * of that level makes the identity map stale too (audit NJV-1). Doctrine `postLoad` listener; other managers'
     * loads are ignored.
     *
     * @param PostLoadEventArgs $args The load event
     */
    public function postLoad(PostLoadEventArgs $args): void
    {
        if ($this->current instanceof EntityManagerInterface && $args->getObjectManager() === $this->current) {
            $this->wrote();
        }
    }

    /**
     * Runs the callable in a transaction of the private manager.
     *
     * @param callable $func The work
     *
     * @return mixed Its result
     */
    public function wrapInTransaction(callable $func): mixed
    {
        try {
            return $this->live()->wrapInTransaction($func);
        } finally {
            $this->wrote();
        }
    }

    /**
     * Host objects on chat rows that the private manager does not hold become the private manager's reference to the
     * same id. Only to-one associations of ChatKit entities (new or managed) to classes outside the bundle are looked
     * at (the chat entities declare no cascade-persist association); an object without an identifier (not saved yet) is
     * left alone, so Doctrine reports it exactly as before.
     */
    private function adoptForeignReferences(EntityManagerInterface $manager): void
    {
        $unitOfWork = $manager->getUnitOfWork();
        $entities   = $unitOfWork->getScheduledEntityInsertions();
        foreach ($unitOfWork->getIdentityMap() as $managed) {
            foreach ($managed as $entity) {
                $entities[] = $entity;
            }
        }
        foreach ($entities as $entity) {
            if (!ChatKitManagerRegistry::isBundleEntity($entity::class)) {
                continue;
            }
            $metadata = $manager->getClassMetadata($entity::class);
            foreach ($metadata->associationMappings as $field => $mapping) {
                if (!$mapping->isToOne() || ChatKitManagerRegistry::isBundleEntity($mapping->targetEntity)) {
                    continue;
                }
                $value = $metadata->getFieldValue($entity, $field);
                if (!is_object($value) || $manager->contains($value)) {
                    continue;
                }
                $target = $manager->getClassMetadata($value::class);
                $id     = $target->getIdentifierValues($value);
                if ($id !== [] && !in_array(null, $id, true)) {
                    $metadata->setFieldValue($entity, $field, $manager->getReference($target->getName(), $id));
                }
            }
        }
    }

    /**
     * Records the nesting level the private manager's last write is waiting in (none outside a transaction).
     */
    private function wrote(): void
    {
        $level = $this->current?->getConnection()->getTransactionNestingLevel() ?? 0;
        if ($level > 0) {
            // @igor-ignore - Bundle-owned bookkeeping of the private manager's open transaction levels.
            $this->uncommitted[$level] = true;
        }
    }

    /**
     * Detaches every row from the private manager. Never touches an application manager and never creates the private
     * one.
     */
    public function clear(): void
    {
        if ($this->current instanceof EntityManagerInterface && $this->current->isOpen()) {
            $this->current->clear();
        }
    }

    /**
     * Drops the private manager (a unit-of-work boundary or `kernel.reset`); the next call creates a fresh one. Changes
     * still pending are lost: they are logged as a warning with their entity classes and counts. The manager is dropped
     * FIRST, so a failing inspection or clear never keeps it across the boundary.
     */
    public function reset(): void
    {
        $current = $this->current;
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->current = null;
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->stale = false;
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->uncommitted  = [];
        $this->repositories = [];
        if (!$current instanceof EntityManagerInterface || !$current->isOpen()) {
            return;
        }

        try {
            $this->warnAboutDroppedChanges($current);
        } catch (Throwable $exception) {
            $this->logGenericWarning($exception);
        }

        try {
            $current->clear();
        } catch (Throwable $exception) {
            // A host onClear listener that throws must not abort the boundary (audit NF3-2): the manager is dropped.
            $this->logGenericWarning($exception);
        }
    }

    /**
     * The application's manager whose connection, configuration, events and filters the private one shares. Never
     * flushed, cleared, closed or reset by the bundle.
     *
     * @throws LogicException when the source is not an ORM manager or does not map the ChatKit entities
     *
     * @return EntityManagerInterface The source manager
     */
    public function source(): EntityManagerInterface
    {
        $source = $this->registry->getManager($this->sourceManagerName);
        if (!$source instanceof EntityManagerInterface || $source->getMetadataFactory()->isTransient(ChatMessage::class)) {
            throw new LogicException(sprintf('The Doctrine entity manager "%s" (nowo_chat_kit doctrine.entity_manager, the source of doctrine.private_entity_manager) does not map %s.', $this->sourceManagerName, ChatMessage::class));
        }

        return $source;
    }

    private function logGenericWarning(Throwable $exception): void
    {
        try {
            $this->logger?->warning('ChatKit: the bundle\'s private entity manager was dropped at a unit-of-work boundary; its pending changes could not be inspected or cleared ({exception}).', [
                'bundle'    => 'nowo_chat_kit',
                'exception' => $exception::class,
            ]);
        } catch (Throwable) {
            // Nothing else to report to: the manager is dropped anyway.
        }
    }

    private function warnAboutDroppedChanges(EntityManagerInterface $manager): void
    {
        if (!$this->logger instanceof LoggerInterface) {
            return;
        }

        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $changes = [];
        foreach ([$unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates(), $unitOfWork->getScheduledEntityDeletions()] as $entities) {
            foreach ($entities as $entity) {
                $class           = ChatKitManagerRegistry::realClass($entity::class);
                $changes[$class] = ($changes[$class] ?? 0) + 1;
            }
        }
        if ($changes === []) {
            return;
        }

        ksort($changes);
        $this->logger->warning('ChatKit: {count} pending change(s) of the bundle\'s private entity manager were dropped at a unit-of-work boundary. Write chat rows through the ChatKit services; the application\'s flush() does not write them.', [
            'bundle'  => 'nowo_chat_kit',
            'count'   => array_sum($changes),
            'changes' => $changes,
        ]);
    }

    private function hasScheduledWork(EntityManagerInterface $manager): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        return $unitOfWork->getScheduledEntityInsertions() !== []
            || $unitOfWork->getScheduledEntityUpdates() !== []
            || $unitOfWork->getScheduledEntityDeletions() !== []
            || $unitOfWork->getScheduledCollectionUpdates() !== []
            || $unitOfWork->getScheduledCollectionDeletions() !== [];
    }

    /**
     * Mirrors the SQL filters enabled on the source manager, with their parameters, on the private one, and disables
     * the others. A filter whose parameters differ is disabled and enabled again (a fresh instance) before its
     * parameters are copied, so a parameter the source no longer has never survives (audit NF-7).
     */
    private function syncFilters(EntityManagerInterface $source, EntityManagerInterface $target): void
    {
        $wanted              = $source->getFilters()->getEnabledFilters();
        $filters             = $target->getFilters();
        $sourceConfiguration = $source->getConfiguration();
        $targetConfiguration = $target->getConfiguration();
        foreach (array_keys($filters->getEnabledFilters()) as $name) {
            if (!isset($wanted[$name])) {
                $filters->disable($name);
            }
        }
        foreach ($wanted as $name => $filter) {
            $state = (string) $filter;
            if ($filters->isEnabled($name)) {
                if ((string) $filters->getFilter($name) === $state) {
                    continue;
                }
                $filters->disable($name);
            }
            $class = $sourceConfiguration->getFilterClassName($name);
            if ($class !== null && $targetConfiguration->getFilterClassName($name) === null) {
                $targetConfiguration->addFilter($name, $class);
            }
            $copy = $filters->enable($name);
            foreach ($this->filterParameters($filter) as $parameter => [$value, $type, $isList]) {
                if ($isList) {
                    /* @var array<mixed> $value */
                    $copy->setParameterList($parameter, $value, is_string($type) ? $type : Types::STRING);
                } else {
                    $copy->setParameter($parameter, $value, is_string($type) ? $type : null);
                }
            }
        }
    }

    /**
     * The raw parameters of a filter (SQLFilter keeps them private): value, type and list flag, as ORM 3.4+ stores them
     * (Parameter objects) or as older 3.x releases did (arrays).
     *
     * @return array<string, array{0: mixed, 1: mixed, 2: bool}>
     */
    private function filterParameters(SQLFilter $filter): array
    {
        $read = static fn (SQLFilter $filter): array => $filter->parameters; // @phpstan-ignore property.private (bound to SQLFilter below)
        /** @var array<string, mixed> $raw */
        $raw        = Closure::bind($read, null, SQLFilter::class)($filter);
        $parameters = [];
        foreach ($raw as $name => $parameter) {
            if ($parameter instanceof Parameter) {
                $parameters[$name] = [$parameter->value, $parameter->type, $parameter->isList];
            } elseif (is_array($parameter)) { // @codeCoverageIgnore
                $parameters[$name] = [$parameter['value'] ?? null, $parameter['type'] ?? null, is_bool($parameter['is_list'] ?? null) && $parameter['is_list']]; // @codeCoverageIgnore
            }
        }

        return $parameters;
    }
}
