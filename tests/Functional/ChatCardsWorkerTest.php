<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Card\TestVaultCardProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;

use function gc_collect_cycles;
use function memory_get_usage;

/**
 * 2.6 (spec 259, REQ-RUNTIME-002): typed cards on one kernel with no services resetter (FrankenPHP worker mode). The
 * host revokes and grants a card every round (a membership removed and restored, a one-time secret burnt): each
 * request renders the card as the host answers it now — open, then the neutral placeholder — never a card memoised by
 * an earlier request; the render scope names the API and the request's context; memory stays flat.
 */
final class ChatCardsWorkerTest extends DatabaseTestCase
{
    use ClientApiCalls;

    private const int ROUNDS = 300;

    private const int WARM_UP_ROUNDS = 50;

    private const int MEMORY_MARGIN = 4 * 1024 * 1024;

    protected function cardProviders(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return self::clientApiConfig(['client_api' => ['limits' => ['per_ip_per_minute' => 100_000, 'per_token_per_minute' => 100_000]]]);
    }

    public function testCardsFollowTheHostEveryRequest(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $this->tokens()->grant('luis', $luis, [ChatClientPrincipal::SCOPE_READ], null, 'tok-luis');
        $provider                                 = $this->get(TestVaultCardProvider::class);
        $provider->names[$ana->getChatPublicId()] = 'Ana';
        $dm                                       = $this->get(ChatService::class)->openDirect($ana, $luis);
        $this->get(ChatService::class)->send($ana, $dm, '#[…](vault_item:v1)');
        $realtime = $this->get(RecordingRealtime::class);

        $baseline = 0;
        for ($round = 0; $round < self::ROUNDS; ++$round) {
            $open                                      = $round % 2 === 0;
            $provider->names[$luis->getChatPublicId()] = $open ? 'Luis' : 'Hidden Luis';
            $token                                     = $this->ok('GET', '/api/chat/v1/conversations/' . $dm->getUuid() . '/messages', 'luis')['messages'][0]['body']['blocks'][0]['tokens'][0];
            self::assertSame($open, $token['visible'], 'round ' . $round . ': the card as the host answers now');
            self::assertSame($open ? 'Prod root DB' : null, $token['title'] ?? null);
            self::assertSame($open ? 'open' : null, $token['card']['state'] ?? null);

            self::assertContains(['render', $luis->getChatPublicId(), 'v1', 'api:personal'], $provider->calls, 'the API scope and the request context');
            $provider->calls          = [];
            $realtime->published      = [];
            $this->tokens()->requests = [];
            $this->contexts()->asked  = [];
            if ($round === self::WARM_UP_ROUNDS) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan($baseline + self::MEMORY_MARGIN, memory_get_usage(), 'memory stays flat over the rounds on one kernel');
    }
}
