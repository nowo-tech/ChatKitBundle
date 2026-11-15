<?php

declare(strict_types=1);

/*
 * Second database session of the real-database tests (tests/Database): it reads one command per line from STDIN and
 * answers one line per command on STDOUT, so a test can make it hold locks and block while the test's own connection
 * runs. Modelled on DoctrineDeadlockRetryBundle's tests/Database.
 *
 *   SQL <statement>   execute the statement; answers "OK" or "ERR <driver code> <SQLSTATE>"
 *   VAL <query>       run the query; answers "VAL <first column of the first row>" or "ERR …"
 *   EXIT              close the connection and stop
 *
 * Usage: php second-connection.php <database url>
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$url = $argv[1] ?? '';
if ($url === '') {
    throw new InvalidArgumentException('Usage: php second-connection.php <database url>');
}

$parser     = new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$connection = DriverManager::getConnection($parser->parse($url));
$connection->fetchOne('SELECT 1');

fwrite(\STDOUT, "READY\n");

while (($line = fgets(\STDIN)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($line === 'EXIT') {
        break;
    }

    try {
        if (str_starts_with($line, 'SQL ')) {
            $sql = substr($line, 4);
            if (stripos($sql, 'SELECT') === 0) {
                // A query's rows are read, so the session can take the next statement.
                $connection->executeQuery($sql)->fetchAllNumeric();
            } else {
                $connection->executeStatement($sql);
            }
            fwrite(\STDOUT, "OK\n");
        } elseif (str_starts_with($line, 'VAL ')) {
            fwrite(\STDOUT, 'VAL ' . var_export($connection->fetchOne(substr($line, 4)), true) . "\n");
        } else {
            fwrite(\STDOUT, "ERR 0 unknown-command\n");
        }
    } catch (DriverException $exception) {
        fwrite(\STDOUT, sprintf("ERR %d %s\n", $exception->getCode(), $exception->getSQLState() ?? '-'));
    }
}

$connection->close();
