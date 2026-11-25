<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestGrantReferenceProvider;
use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Doctrine\QueryCounter;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;

use function array_column;
use function gc_collect_cycles;
use function memory_get_usage;

/**
 * 2.4 (client API v1, R7 / REQ-RUNTIME-002): one kernel, every request through the container's `http_kernel`, no
 * services resetter (FrankenPHP worker mode, kernel reset false). Two people in two contexts are served in turn for
 * {@see self::ROUNDS} rounds: neither ever sees the other's context, a #reference granted and revoked every round is
 * shown exactly as the grant stands, a token revoked half-way is refused on the very next request (and its successor
 * works), and memory stays flat.
 */
final class ChatClientApiWorkerTest extends DatabaseTestCase
{
    use ClientApiCalls;

    /** Rounds (four requests each). */
    private const int ROUNDS = 500;

    /** Rounds before the memory baseline is taken. */
    private const int WARM_UP_ROUNDS = 50;

    /** Allowed memory growth after the warm-up (bytes). */
    private const int MEMORY_MARGIN = 4 * 1024 * 1024;

    protected function chatKitConfig(): array
    {
        return self::clientApiConfig(['client_api' => ['limits' => ['per_ip_per_minute' => 100_000, 'per_token_per_minute' => 100_000]]]);
    }

    protected function grantReferences(): bool
    {
        return true;
    }

    public function testTwoPeopleInTwoContextsOnOneKernel(): void
    {
        $this->get(TestGrantReferenceProvider::class)->createTable();
        $ana                           = $this->user('Ana');
        $luis                          = $this->user('Luis');
        $bea                           = $this->user('Bea');
        $this->contexts()->acmeMembers = [$bea->getChatPublicId() => true];
        $this->tokens()->grant('ana-1', $ana, id: 'ana-1');
        $this->tokens()->grant('bea-1', $bea, id: 'bea-1');
        $this->grant($ana, '7');

        $service = $this->get(ChatService::class);
        $direct  = $service->openDirect($ana, $luis);
        $service->send($luis, $direct, 'For Ana');
        $channel = $service->openChannel($bea, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $service->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $service->send($ana, $channel, 'See #[Doc 7](doc:7)');

        $realtime = $this->get(RecordingRealtime::class);
        $queries  = $this->get(QueryCounter::class);
        $granted  = false;
        $baseline = 0;
        $anaToken = 'ana-1';
        for ($round = 0; $round < self::ROUNDS; ++$round) {
            if ($round === self::ROUNDS / 2) {
                // Ana revokes the desktop's token from her account; the app signs in again.
                $this->tokens()->revoke('ana-1');
                self::assertError($this->call('GET', '/api/chat/v1/me', 'ana-1'), 401, 'invalid_token');
                $this->tokens()->grant('ana-2', $ana, id: 'ana-2');
                $anaToken = 'ana-2';
            }
            if ($round > self::ROUNDS / 2) {
                self::assertSame(401, $this->call('GET', '/api/chat/v1/me', 'ana-1')->getStatusCode(), 'round ' . $round . ': a revoked token stays refused');
            }

            $anaList = $this->ok('GET', '/api/chat/v1/conversations', $anaToken);
            self::assertSame([$direct->getUuid()], array_column($anaList['conversations'], 'uuid'), 'round ' . $round . ': Ana\'s personal context');

            $beaList = $this->ok('GET', '/api/chat/v1/conversations', 'bea-1', 'org:acme');
            self::assertSame([$channel->getUuid()], array_column($beaList['conversations'], 'uuid'), 'round ' . $round . ': Bea\'s Acme context');
            self::assertSame(403, $this->call('GET', '/api/chat/v1/conversations', $anaToken, 'org:acme')->getStatusCode(), 'round ' . $round . ': Ana is no Acme member');

            $reference = $this->ok('GET', '/api/chat/v1/conversations/' . $channel->getUuid() . '/messages', 'bea-1', 'org:acme')['messages'][0]['body']['blocks'][0]['tokens'][1];
            self::assertSame($granted, $reference['visible'], 'round ' . $round . ': the card follows the grant of this request');
            self::assertSame($granted ? 'Doc 7 (granted)' : null, $reference['title'] ?? null);

            $granted ? $this->revoke($bea, '7') : $this->grant($bea, '7');
            $granted = !$granted;
            // The test doubles' own records are not the kernel's state.
            $this->tokens()->requests = [];
            $this->contexts()->asked  = [];
            $realtime->published      = [];
            $this->denied             = [];
            $queries->clear();

            if ($round === self::WARM_UP_ROUNDS) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }

        gc_collect_cycles();
        self::assertLessThan($baseline + self::MEMORY_MARGIN, memory_get_usage(), 'memory stays flat over the rounds on one kernel');
    }

    private function grant(TestUser $user, string $item): void
    {
        $this->connection()->insert(TestGrantReferenceProvider::TABLE, ['user_id' => $user->getId(), 'item_id' => $item]);
    }

    private function revoke(TestUser $user, string $item): void
    {
        $this->connection()->delete(TestGrantReferenceProvider::TABLE, ['user_id' => $user->getId(), 'item_id' => $item]);
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
