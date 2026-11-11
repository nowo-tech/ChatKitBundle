<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Doctrine\DBAL\Connection;
use Nowo\ChatKitBundle\Contract\ChatReferenceProviderInterface;
use Nowo\ChatKitBundle\Model\ChatReference;
use Nowo\ChatKitBundle\Model\ChatReferenceScope;

use function array_intersect;
use function array_map;
use function array_slice;
use function array_values;

/**
 * `/doc` (worker tests, 1.26.3): who may open which document is read from the `test_reference_grant` table on every
 * call, so a test grants and revokes with plain SQL between two requests, as an application changes a permission. The
 * table is created by the test (createTable()); only kernels built with `grantReferences` register this provider.
 */
final class TestGrantReferenceProvider implements ChatReferenceProviderInterface
{
    public const string TABLE = 'test_reference_grant';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Creates the empty grant table (drops a previous one).
     */
    public function createTable(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->connection->executeStatement('CREATE TABLE ' . self::TABLE . ' (user_id INTEGER NOT NULL, item_id VARCHAR(16) NOT NULL)');
    }

    public function key(): string
    {
        return 'doc';
    }

    public function label(): string
    {
        return 'Doc';
    }

    public function icon(): string
    {
        return 'doc';
    }

    public function isAvailable(ChatReferenceScope $scope): bool
    {
        return $this->readable($scope) !== [];
    }

    public function search(ChatReferenceScope $scope, string $query, int $limit): array
    {
        return array_slice(array_values($this->cards($this->readable($scope))), 0, $limit);
    }

    public function resolve(ChatReferenceScope $scope, array $ids): array
    {
        return $this->cards(array_values(array_intersect($this->readable($scope), $ids)));
    }

    /**
     * The documents every person of the audience may open, read from the table now.
     *
     * @return list<string>
     */
    private function readable(ChatReferenceScope $scope): array
    {
        $common = null;
        foreach ($scope->audience as $user) {
            $ids    = array_map('strval', $this->connection->fetchFirstColumn('SELECT item_id FROM ' . self::TABLE . ' WHERE user_id = ?', [(int) $user->getId()]));
            $common = $common === null ? $ids : array_values(array_intersect($common, $ids));
        }

        return $common ?? [];
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, ChatReference>
     */
    private function cards(array $ids): array
    {
        $cards = [];
        foreach ($ids as $id) {
            $cards[$id] = new ChatReference('doc', $id, 'Doc ' . $id . ' (granted)', '/docs/' . $id);
        }

        return $cards;
    }
}
