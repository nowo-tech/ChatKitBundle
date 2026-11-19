<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Database;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\Command\ChatSearchIndexCommand;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Search\ChatMessageSearch;
use Nowo\ChatKitBundle\Search\ChatSearchIndex;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Database\Support\SecondConnection;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

use function array_filter;
use function array_map;
use function array_values;
use function assert;
use function implode;
use function ltrim;
use function parse_url;
use function preg_match;
use function sort;
use function sprintf;
use function str_contains;
use function str_starts_with;

use const PHP_URL_PATH;

/**
 * 1.37 (spec 002): message search on real servers (MySQL 8.4, MariaDB 11.4, PostgreSQL 16; job "Load readiness",
 * `CHAT_KIT_RACE_DATABASE_URL`): the index (mapped FULLTEXT on MySQL / MariaDB, `CONCURRENTLY` GIN on PostgreSQL) is
 * built, used by the planner and never dropped by a schema diff; the engines match prefixes, every word, short words and
 * punctuation through LIKE, never pass an operator through; the permission filter holds; and a build blocked by another
 * session's lock stops with CONFLICT_001, nothing half-built. The schema is only updated (never dropped), so the other
 * tests of the suite keep theirs.
 */
final class MessageSearchDatabaseTest extends TestCase
{
    private TestKernel $kernel;

    private EntityManagerInterface $em;

    private ?SecondConnection $other = null;

    protected function setUp(): void
    {
        $url = TestKernel::databaseUrl();
        if ($url === null) {
            self::markTestSkipped('Set CHAT_KIT_RACE_DATABASE_URL to a throwaway server database (MySQL or PostgreSQL).');
        }
        $database = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        if (preg_match('/(?:^|_)(?:test|race|throwaway)(?:_|$)/', $database) !== 1) {
            throw new RuntimeException(sprintf('Refusing to use database "%s": the test deletes its rows.', $database));
        }
        $this->kernel = new TestKernel(['profiles' => ['default' => ['search' => ['enabled' => true]]]]);
        $this->kernel->boot();
        $em = $this->kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        // Add what is missing (the FULLTEXT index on MySQL / MariaDB), never drop: the other tests' own tables stay.
        foreach ((new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()) as $sql) {
            if (preg_match('/^DROP (TABLE|SEQUENCE)\b/i', $sql) !== 1) {
                $em->getConnection()->executeStatement($sql);
            }
        }
        foreach (['nowo_chat_message_link', 'nowo_chat_message_mention', 'nowo_chat_reaction', 'nowo_chat_message_revision', 'nowo_chat_saved_message', 'nowo_chat_thread_read', 'nowo_chat_read_mark', 'nowo_chat_message', 'nowo_chat_conversation_link', 'nowo_chat_participant', 'nowo_chat_ticket_counter', 'nowo_chat_conversation', 'nowo_chat_quick_reply', 'nowo_chat_user_settings', 'nowo_chat_idempotency', 'test_user'] as $table) {
            if ($this->connection()->createSchemaManager()->tablesExist([$table])) {
                $this->connection()->executeStatement('DELETE FROM ' . $table);
            }
        }
        if ($this->index()->state() !== ChatSearchIndex::READY) {
            self::assertSame(0, $this->command(['--force' => true])->getStatusCode());
        }
    }

    protected function tearDown(): void
    {
        $this->other?->close();
        $this->other = null;
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    public function testTheIndexIsBuiltUsedAndNeverDroppedByADiff(): void
    {
        $index = $this->index();
        self::assertSame(ChatSearchIndex::READY, $index->state());
        // A schema diff of the mapping keeps it (mapped on MySQL / MariaDB, invisible to DBAL on PostgreSQL).
        $diff = (new SchemaTool($this->em))->getUpdateSchemaSql($this->em->getMetadataFactory()->getAllMetadata());
        self::assertSame([], array_values(array_filter($diff, static fn (string $sql): bool => str_contains($sql, $index->name()))), implode("\n", $diff));
        // The command: the state and the SQL; --force again is a no-op.
        $output = $this->command([])->getDisplay();
        self::assertStringContainsString($index->name(), $output);
        self::assertStringContainsString('ready', $output);
        self::assertStringContainsString($this->mysql() ? 'CREATE FULLTEXT INDEX' : 'CREATE INDEX CONCURRENTLY IF NOT EXISTS', $output);
        self::assertStringContainsString('already there', $this->command(['--force' => true])->getDisplay());
        // Dropped and built again.
        self::assertSame(0, $this->command(['--force' => true, '--drop' => true])->getStatusCode());
        self::assertSame(ChatSearchIndex::MISSING, $index->state());
        self::assertStringContainsString('Nothing to drop', $this->command(['--force' => true, '--drop' => true])->getDisplay());
        self::assertSame(0, $this->command(['--force' => true])->getStatusCode());
        self::assertSame(ChatSearchIndex::READY, $index->state());

        // The planner uses it for a full-text match.
        $table = $index->table();
        if ($this->mysql()) {
            $plan = $this->connection()->fetchAllAssociative("EXPLAIN SELECT id FROM {$table} WHERE MATCH (body) AGAINST ('+invoice*' IN BOOLEAN MODE)");
            self::assertSame($index->name(), $plan[0]['key'] ?? null);
        } else {
            $this->connection()->executeStatement('SET enable_seqscan = off');
            $plan = implode("\n", $this->connection()->fetchFirstColumn("EXPLAIN SELECT id FROM {$table} WHERE to_tsvector('simple'::regconfig, body) @@ to_tsquery('simple'::regconfig, 'invoice:*')"));
            $this->connection()->executeStatement('SET enable_seqscan = on');
            self::assertStringContainsString($index->name(), $plan);
        }
    }

    public function testEnginesAndPermissions(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $eve  = $this->user('Eve');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $a    = $chat->send($luis, $dm, 'The invoice for March is ready, order C-12');
        $b    = $chat->send($ana, $dm, 'Invoices arrive monthly; ok?');
        $c    = $chat->send($ana, $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme')), 'Channel invoice 2026 notes');
        $d    = $chat->send($eve, $chat->openDirect($luis, $eve), 'Hidden invoice of Eve');
        $e    = $chat->send($ana, $chat->openSupport($ana), 'Support invoice from Ana');
        $chat->send($luis, $dm, 'nothing relevant');

        self::assertSame($this->uuids([$a, $b, $c, $e]), $this->find($ana, 'invoice'), 'prefix: invoice also finds Invoices');
        self::assertSame($this->uuids([$a, $b, $c, $d]), $this->find($luis, 'invoice'));
        self::assertSame($this->uuids([$a]), $this->find($ana, 'invoice march'), 'every word');
        self::assertSame($this->uuids([$a]), $this->find($ana, 'C-12'), 'punctuation through LIKE');
        self::assertSame($this->uuids([$b]), $this->find($ana, 'ok invoices'), 'a short word through LIKE on MySQL');
        self::assertSame($this->uuids([$c]), $this->find($ana, '2026'), 'digits');
        // Boolean-mode and tsquery operators are plain text: no error, no widening.
        foreach (['+invoice -march', '"invoice" (march)', 'invoice:* | march', 'invoice & !march', "inv'oice", 'march* <ready> ~order @2'] as $text) {
            $this->find($ana, $text);
        }
        self::assertSame([], $this->find($ana, 'invoice -march'), '"-march" is a word that is not there');
        self::assertSame([], $this->find($ana, 'hidden'));
    }

    public function testABuildBlockedByAnotherSessionAnswersConflict001(): void
    {
        $index = $this->index();
        self::assertSame(0, $this->command(['--force' => true, '--drop' => true])->getStatusCode());
        $this->user('Ana');
        $other = $this->other = new SecondConnection((string) TestKernel::databaseUrl());
        // The other session holds a write lock on the message table in an open transaction: the build must wait for it
        // (metadata lock on MySQL / MariaDB, the writers' lock for PostgreSQL's CONCURRENTLY) and gives up after a second.
        self::assertSame('OK', $other->execute($this->mysql() ? 'START TRANSACTION' : 'BEGIN'));
        self::assertSame('OK', $other->execute('UPDATE ' . $index->table() . ' SET body = body WHERE 1 = 0'));
        $this->connection()->executeStatement($this->mysql() ? 'SET SESSION lock_wait_timeout = 1' : "SET lock_timeout = '1s'");

        $tester = $this->command(['--force' => true]);
        self::assertSame(ChatSearchIndexCommand::CONFLICT, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('CONFLICT_001', $tester->getDisplay());
        self::assertNotSame(ChatSearchIndex::READY, $index->state());

        $other->execute('ROLLBACK');
        $this->connection()->executeStatement($this->mysql() ? 'SET SESSION lock_wait_timeout = 31536000' : 'SET lock_timeout = 0');
        // Run again: an INVALID index left by the failed CONCURRENTLY build is dropped first.
        $again = $this->command(['--force' => true]);
        self::assertSame(0, $again->getStatusCode(), 'connection still usable, index built');
        self::assertStringContainsString($this->mysql() ? 'missing' : 'invalid', $again->getDisplay(), $again->getDisplay());
        self::assertSame(ChatSearchIndex::READY, $index->state());
    }

    /**
     * @return list<string>
     */
    private function find(ChatUserInterface $user, string $text): array
    {
        $search = $this->get(ChatMessageSearch::class);
        $uuids  = array_map(static fn ($hit): string => $hit->messageUuid, $search->search($user, $search->query($text))->hits);
        sort($uuids);

        return $uuids;
    }

    /**
     * @param list<ChatMessage> $messages
     *
     * @return list<string>
     */
    private function uuids(array $messages): array
    {
        $uuids = array_map(static fn (ChatMessage $message): string => $message->getUuid(), $messages);
        sort($uuids);

        return $uuids;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function command(array $input): CommandTester
    {
        $tester = new CommandTester(new ChatSearchIndexCommand($this->index()));
        $tester->execute($input);

        return $tester;
    }

    private function index(): ChatSearchIndex
    {
        return $this->get(ChatSearchIndex::class);
    }

    private function mysql(): bool
    {
        return str_starts_with((string) TestKernel::databaseUrl(), 'mysql');
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }

    private function user(string $name): TestUser
    {
        $user = new TestUser($name, $name . '@example.test');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function get(string $id): object
    {
        $service = $this->kernel->getContainer()->get($id);
        assert($service instanceof $id);

        return $service;
    }
}
