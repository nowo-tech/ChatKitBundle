<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Card\TestVaultCardProvider;
use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Component\HttpFoundation\Response;

use function array_filter;
use function array_values;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * 2.6 (spec 259): typed cards in the client API v1 — `me.features.cards`, the `cards` write field of a send and a
 * thread reply (refused on edit), the `card` object of a reference token (rendered with the API scope and the context)
 * and `composerActions` on a conversation.
 */
final class ChatClientApiCardsTest extends DatabaseTestCase
{
    use ClientApiCalls;

    private const string API = '/api/chat/v1';

    private const array RW = [ChatClientPrincipal::SCOPE_READ, ChatClientPrincipal::SCOPE_WRITE];

    private TestUser $ana;

    private TestUser $luis;

    protected function cardProviders(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return self::clientApiConfig([
            'client_api'   => ['writes' => true, 'limits' => ['per_ip_per_minute' => 100_000, 'per_token_per_minute' => 100_000, 'writes_per_token_per_minute' => 100_000]],
            'message_menu' => ['threads' => ['enabled' => true]],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana  = $this->user('Ana');
        $this->luis = $this->user('Locked Luis');
        $provider   = $this->get(TestVaultCardProvider::class);
        foreach ([$this->ana, $this->luis] as $user) {
            $provider->names[$user->getChatPublicId()] = $user->getChatDisplayName();
        }
        $this->tokens()->grant('ana', $this->ana, self::RW, null, 'tok-ana');
        $this->tokens()->grant('luis', $this->luis, self::RW, null, 'tok-luis');
    }

    public function testCardsThroughTheClientApi(): void
    {
        $me = $this->ok('GET', self::API . '/me', 'ana');
        self::assertTrue($me['features']['cards']);
        $dm = $this->get(ChatService::class)->openDirect($this->ana, $this->luis);

        $conversation = $this->ok('GET', self::API . '/conversations/' . $dm->getUuid(), 'ana')['conversation'];
        self::assertSame([['key' => 'secret', 'label' => 'One-time secret', 'icon' => 'lock', 'slash' => 'secret'], ['key' => 'vault', 'label' => 'Share a vault item', 'icon' => 'key', 'slash' => null]], $conversation['composerActions']);

        // Refusals: not shareable, a reference type, malformed; nothing written.
        self::assertError($this->write('POST', $this->messages($dm), 'ana', ['text' => 'x', 'cards' => [['type' => 'vault_item', 'id' => 'nope']]], 'k-0'), 422, 'card_not_allowed');
        self::assertError($this->write('POST', $this->messages($dm), 'ana', ['text' => 'x', 'cards' => [['type' => 'task', 'id' => '1']]], 'k-1'), 422, 'card_not_allowed');
        self::assertError($this->write('POST', $this->messages($dm), 'ana', ['text' => 'x', 'cards' => [['type' => 'vault_item', 'id' => 'v1', 'title' => 'x']]], 'k-2'), 422, 'invalid_cards');
        self::assertError($this->write('POST', $this->messages($dm), 'ana', ['text' => 'x', 'cards' => ['a' => 1]], 'k-3'), 422, 'invalid_cards');
        self::assertSame(0, $this->rows());

        $sent = $this->write('POST', $this->messages($dm), 'ana', ['text' => '', 'cards' => [['type' => 'vault_item', 'id' => 'v1']]], 'k-4');
        self::assertSame(201, $sent->getStatusCode(), (string) $sent->getContent());
        $token = self::decode($sent)['message']['body']['blocks'][0]['tokens'][0];
        self::assertSame('reference', $token['type']);
        self::assertTrue($token['visible']);
        self::assertSame(['vault_item', 'v1', 'Vault item', 'key', 'Prod root DB'], [$token['refType'], $token['id'], $token['typeLabel'], $token['icon'], $token['title']]);
        self::assertSame('open', $token['card']['state']);
        self::assertSame([['key' => 'open', 'label' => 'Open', 'kind' => 'open', 'url' => '/vault/v1', 'dialog' => false]], $token['card']['actions']);
        // The same key and cards again: a replay.
        self::assertTrue(self::decode($this->write('POST', $this->messages($dm), 'ana', ['text' => '', 'cards' => [['type' => 'vault_item', 'id' => 'v1']]], 'k-4'))['replayed']);

        // The reader's view through the API scope (api: true, the request's context).
        $page = $this->ok('GET', $this->messages($dm), 'luis');
        self::assertSame('locked', $page['messages'][0]['body']['blocks'][0]['tokens'][0]['card']['state']);
        $renders = array_values(array_filter($this->get(TestVaultCardProvider::class)->calls, static fn (array $call): bool => $call[0] === 'render'));
        self::assertNotSame([], $renders);

        // A thread reply takes cards too; an edit does not (the cards stay as sent).
        $root  = self::decode($sent)['message']['uuid'];
        $reply = $this->write('POST', $this->messages($dm) . '/' . $root . '/thread', 'ana', ['text' => 'see', 'cards' => [['type' => 'note', 'id' => 'n1']]], 'k-5');
        self::assertSame(201, $reply->getStatusCode(), (string) $reply->getContent());
        self::assertError($this->write('PATCH', $this->messages($dm) . '/' . $root, 'ana', ['text' => 'x', 'cards' => []], 'k-6'), 422, 'unsupported_field');
        $edited = $this->write('PATCH', $this->messages($dm) . '/' . $root, 'ana', ['text' => 'now with text'], 'k-7');
        self::assertSame(200, $edited->getStatusCode(), (string) $edited->getContent());
        $blocks = self::decode($edited)['message']['body']['blocks'];
        self::assertSame('now with text', $blocks[0]['tokens'][0]['text']);
        self::assertSame('vault_item', $blocks[1]['tokens'][0]['refType'], 'the card stays after the edit');
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function write(string $method, string $uri, string $token, ?array $body, string $key): Response
    {
        return $this->call($method, $uri, $token, 'personal', $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), ['Idempotency-Key' => $key]);
    }

    private function messages(ChatConversation $conversation): string
    {
        return self::API . '/conversations/' . $conversation->getUuid() . '/messages';
    }

    private function rows(): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(m)')->from(ChatMessage::class, 'm')->getQuery()->getSingleScalarResult();
    }
}
