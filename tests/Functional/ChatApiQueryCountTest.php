<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Doctrine\QueryCounter;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;

use const JSON_THROW_ON_ERROR;

/**
 * N+1 regression: the SQL statements of the list endpoints do not grow with the number of rows (5 vs 50).
 */
final class ChatApiQueryCountTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    /** @var list<string> SQL of the last measured request */
    private array $lastQueries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testConversationListDoesNotScaleWithDirectThreads(): void
    {
        $few  = $this->measure(5, $this->seedDirectThreads(...), '/chat/api/conversations', 'conversations');
        $many = $this->measure(50, $this->seedDirectThreads(...), '/chat/api/conversations', 'conversations');

        self::assertSame($few, $many, 'GET /conversations runs the same SQL for 5 and 50 direct threads');
        $settingsReads = array_filter($this->lastQueries, static fn (string $sql): bool => str_contains($sql, 'user_settings'));
        // The viewer's own row (presence visibility) once, plus the peers' rows in one batch (ChatPresence).
        self::assertCount(2, $settingsReads, "the viewer's settings are read once, not per row");
        self::assertCount(1, array_filter($settingsReads, static fn (string $sql): bool => str_contains($sql, ' IN (')));
    }

    public function testConversationListAndInboxDoNotScaleWithSupportThreads(): void
    {
        $few  = $this->measure(5, $this->seedSupportThreads(...), '/chat/api/conversations', 'conversations');
        $many = $this->measure(50, $this->seedSupportThreads(...), '/chat/api/conversations', 'conversations');
        self::assertSame($few, $many, 'GET /conversations runs the same SQL for 5 and 50 support threads');

        $few  = $this->measure(5, $this->seedSupportThreads(...), '/chat/api/support/inbox?status=all', 'threads');
        $many = $this->measure(50, $this->seedSupportThreads(...), '/chat/api/support/inbox?status=all', 'threads');
        self::assertSame($few, $many, 'GET /support/inbox runs the same SQL for 5 and 50 threads');
    }

    public function testMessagePageDoesNotScaleWithAuthorsAndReaders(): void
    {
        $few  = $this->measure(5, $this->seedChannelAuthors(...), '/chat/api/conversations/{uuid}/messages', 'messages');
        $many = $this->measure(25, $this->seedChannelAuthors(...), '/chat/api/conversations/{uuid}/messages', 'messages');

        self::assertSame($few, $many, 'GET /messages runs the same SQL for 5 and 25 authors / readers');
    }

    /**
     * Seeds a fresh database, signs the viewer in and counts the SQL statements of one GET.
     *
     * @param callable(int): array{TestUser, string} $seed Returns the viewer and the conversation uuid (or '')
     */
    private function measure(int $rows, callable $seed, string $url, string $listKey): int
    {
        $this->tearDown();
        $this->setUp();
        [$viewer, $uuid] = $seed($rows);
        $this->em->clear();

        $this->client->loginUser($viewer);
        $this->client->request(Request::METHOD_GET, '/page');
        $counter = $this->get(QueryCounter::class);
        $counter->clear();
        $this->client->request(Request::METHOD_GET, str_replace('{uuid}', $uuid, $url));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        /** @var array<string, list<mixed>> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount($rows, $data[$listKey], 'every seeded row is listed');
        $this->lastQueries = $counter->queries();

        return $counter->count();
    }

    /**
     * @return array{TestUser, string}
     */
    private function seedDirectThreads(int $rows): array
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        for ($i = 1; $i <= $rows; ++$i) {
            $peer = $this->user('Peer ' . $i);
            $chat->send($peer, $chat->openDirect($ana, $peer), 'Hello ' . $i);
        }

        return [$ana, ''];
    }

    /**
     * @return array{TestUser, string}
     */
    private function seedSupportThreads(int $rows): array
    {
        $chat  = $this->get(ChatService::class);
        $agent = $this->user('Agent Smith');
        for ($i = 1; $i <= $rows; ++$i) {
            $customer = $this->user('Stranger ' . $i);
            $chat->send($customer, $chat->openSupport($customer), 'Help ' . $i);
        }

        return [$agent, ''];
    }

    /**
     * @return array{TestUser, string}
     */
    private function seedChannelAuthors(int $rows): array
    {
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $channel = $this->get(TestChannelProvider::class)->find(TestChannelProvider::KEY);
        assert($channel !== null);
        $conversation = $chat->openChannel($ana, $channel);
        for ($i = 1; $i <= $rows; ++$i) {
            $author  = $this->user('Member ' . $i);
            $message = $chat->send($author, $chat->openChannel($author, $channel), 'Note ' . $i);
            $chat->markRead($author, $conversation, (int) $message->getId());
        }

        return [$ana, (string) $conversation->getUuid()];
    }
}
