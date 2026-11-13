<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * 1.27: wraps the driver connections of the private entity manager's connection ({@see PrivateEntityManagerMiddleware}).
 *
 * @internal
 */
final class PrivateEntityManagerDriver extends AbstractDriverMiddleware
{
    /**
     * @param Driver $driver The driver
     * @param ChatKitEntityManager $entityManager The private manager
     */
    public function __construct(
        Driver $driver,
        private readonly ChatKitEntityManager $entityManager,
    ) {
        parent::__construct($driver);
    }

    /**
     * Connects and wraps the connection.
     *
     * @param array<string, mixed> $params Connection parameters
     *
     * @return Connection The wrapped connection
     */
    public function connect(#[SensitiveParameter] array $params): Connection
    {
        return new PrivateEntityManagerConnection(parent::connect($params), $this->entityManager);
    }
}
