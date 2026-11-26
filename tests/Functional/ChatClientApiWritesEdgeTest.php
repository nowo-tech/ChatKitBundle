<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use InvalidArgumentException;
use Nowo\ChatKitBundle\ClientApi\ChatClientApiController;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Http\ChatApiException;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Service\ChatIdempotencyConflict;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

use function array_key_exists;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * 2.5 (client API v1 writes): the edges — a host that replaced ChatKit's chat service (its writes cannot join an
 * Idempotency-Key's unit of work: no write route, `me.features.send` false), threads off, and a replay whose message is
 * gone (purged meanwhile: 404).
 */
final class ChatClientApiWritesEdgeTest extends DatabaseTestCase
{
    use ClientApiCalls;

    private const string API = '/api/chat/v1';

    private const array RW = [ChatClientPrincipal::SCOPE_READ, ChatClientPrincipal::SCOPE_WRITE];

    private TestUser $ana;

    private TestUser $luis;

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
        $this->luis = $this->user('Luis');
        $this->tokens()->grant('ana', $this->ana, self::RW, null, 'tok-ana');
    }

    public function testAHostChatServiceOffersNoWrite(): void
    {
        $controller = $this->controller(['chat' => self::createStub(ChatServiceInterface::class)]);
        $me         = self::decode($controller->me($this->request('GET', '/me')));
        self::assertFalse($me['features']['send']);
        self::assertFalse($me['features']['threadReply']);

        $dm = $this->dm();
        $this->refused(404, 'not_found', fn () => $controller->send($dm->getUuid(), $this->request('POST', '/x', ['text' => 'hi'])));
    }

    public function testRawBytesAsAnEmojiAreAnInvalidReaction(): void
    {
        // Audit ZCG-L1: whatever the router lets through, the controller refuses a non-UTF-8 emoji before hashing it.
        $dm      = $this->dm();
        $message = $this->get(ChatService::class)->send($this->luis, $dm, 'hi');
        foreach (["\xFF", "\xC3", "\xF0\x9F"] as $bytes) {
            $this->refused(422, 'invalid_reaction', fn () => $this->get(ChatClientApiController::class)->reaction($dm->getUuid(), $message->getUuid(), $bytes, $this->request('PUT', '/x')));
        }
    }

    public function testThreadRepliesNeedThreads(): void
    {
        $dm   = $this->dm();
        $root = $this->get(ChatService::class)->send($this->ana, $dm, 'root');
        $this->refused(404, 'not_found', fn () => $this->controller(['threads' => null])->threadReply($dm->getUuid(), $root->getUuid(), $this->request('POST', '/x', ['text' => 'hi'])));
    }

    public function testAReplayWhoseMessageIsGoneIsNotFound(): void
    {
        $dm     = $this->dm();
        $chat   = $this->get(ChatService::class);
        $root   = $chat->send($this->luis, $dm, 'root');
        $mine   = $chat->send($this->ana, $dm, 'mine');
        $base   = self::API . '/conversations/' . $dm->getUuid() . '/messages';
        $writes = [
            ['POST', $base, ['text' => 'sent']],
            ['POST', $base . '/' . $root->getUuid() . '/thread', ['text' => 'reply']],
            ['PATCH', $base . '/' . $mine->getUuid(), ['text' => 'edited']],
            ['DELETE', $base . '/' . $mine->getUuid(), null],
        ];
        $keys = [];
        foreach ($writes as $i => [$method, $url, $body]) {
            $response = $this->call($method, $url, 'ana', 'personal', $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), ['Idempotency-Key' => 'gone-' . $i]);
            self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());
            $keys[] = self::decode($response)['message']['uuid'];
        }
        // The rows the keys point at are purged (retention), then every write is retried with its key.
        $this->em->getConnection()->executeStatement('UPDATE nowo_chat_message SET thread_root_id = NULL');
        $this->em->getConnection()->executeStatement('DELETE FROM nowo_chat_message WHERE uuid IN (?, ?, ?)', [$keys[0], $keys[1], $keys[2]]);
        $this->em->clear();
        foreach ($writes as $i => [$method, $url, $body]) {
            if ($i >= 2) {
                // The edited / deleted message itself is gone: the lookup answers first.
                continue;
            }
            self::assertError($this->call($method, $url, 'ana', 'personal', $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), ['Idempotency-Key' => 'gone-' . $i]), 404, 'not_found');
        }
    }

    public function testAKeyClaimedByAnotherRequestWritesNothing(): void
    {
        // The claim is the first statement of each unit of work: refused, nothing of the edit, delete or reaction stays.
        $chat    = $this->get(ChatService::class);
        $message = $chat->send($this->ana, $this->dm(), 'keep me');
        $lost    = static fn (): bool => false;
        foreach ([
            static fn () => $chat->editWith($message, 'changed', $lost),
            fn () => $chat->deleteWith($this->ana, $message, $lost),
            fn () => $chat->setReaction($this->luis, $message, '👍', true, $lost),
        ] as $write) {
            try {
                $write();
                self::fail('written');
            } catch (ChatIdempotencyConflict) {
            }
        }
        $this->em->clear();
        $stored = $this->em->find(ChatMessage::class, $message->getId());
        self::assertInstanceOf(ChatMessage::class, $stored);
        self::assertSame(['keep me', false], [$stored->getBody(), $stored->isDeleted()]);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_reaction'));
        // The service refuses an emoji it does not accept by itself too (the API checks it first).
        try {
            $chat->setReaction($this->luis, $message, 'nope', true);
            self::fail('accepted');
        } catch (InvalidArgumentException $refused) {
            self::assertSame('reaction', $refused->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $replace Constructor arguments of the container's controller to replace
     */
    private function controller(array $replace): ChatClientApiController
    {
        $original   = $this->get(ChatClientApiController::class);
        $reflection = new ReflectionClass(ChatClientApiController::class);
        $arguments  = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name             = $parameter->getName();
            $arguments[$name] = array_key_exists($name, $replace) ? $replace[$name] : $reflection->getProperty($name)->getValue($original);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $path, ?array $body = null): Request
    {
        return Request::create(self::API . $path, $method, [], [], [], [
            'REMOTE_ADDR'           => '203.0.113.9',
            'HTTP_AUTHORIZATION'    => 'Bearer ana',
            'HTTP_X_BEACON_CONTEXT' => 'personal',
            'HTTP_IDEMPOTENCY_KEY'  => 'edge-1',
            'CONTENT_TYPE'          => 'application/json',
        ], $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param callable(): mixed $call
     */
    private function refused(int $status, string $error, callable $call): void
    {
        try {
            $call();
            self::fail('not refused');
        } catch (ChatApiException $exception) {
            self::assertSame([$status, $error], [$exception->getCode(), $exception->getMessage()]);
        }
    }

    private function dm(): ChatConversation
    {
        return $this->get(ChatService::class)->openDirect($this->ana, $this->luis);
    }
}
