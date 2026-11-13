<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;

/**
 * 1.27: DBAL middleware on the connection of the private entity manager (adapted from TaskBoardBundle 2.15.0): tells
 * {@see ChatKitEntityManager} when a transaction or a savepoint is rolled back, so a private identity map that may
 * describe rolled-back rows is replaced before the next write. Registered only with doctrine/dbal 4 and only on that
 * connection ({@see PrivateEntityManagerPass}).
 *
 * @internal
 */
final readonly class PrivateEntityManagerMiddleware implements Middleware
{
    /**
     * @param ChatKitEntityManager $entityManager The private manager
     */
    public function __construct(
        private ChatKitEntityManager $entityManager,
    ) {
    }

    /**
     * Wraps the driver.
     *
     * @param Driver $driver The driver
     *
     * @return Driver The wrapped driver
     */
    public function wrap(Driver $driver): Driver
    {
        return new PrivateEntityManagerDriver($driver, $this->entityManager);
    }
}
