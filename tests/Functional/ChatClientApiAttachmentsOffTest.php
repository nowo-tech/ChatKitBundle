<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * 2.7 (spec 259): the client API while attachments are off: `me.features.attachments` is false, `me.limits` has no
 * `attachments`, messages keep 2.6's shape, every attachment route answers 404 and a send with `attachments` is refused
 * as an unsupported field.
 */
final class ChatClientApiAttachmentsOffTest extends DatabaseTestCase
{
    use ClientApiCalls;

    protected function chatKitConfig(): array
    {
        return self::clientApiConfig(['client_api' => ['writes' => true]]);
    }

    public function testTheApiIsAsIn26(): void
    {
        $ana = $this->user('Ana');
        $bea = $this->user('Bea');
        $this->tokens()->grant('ana', $ana, [ChatClientPrincipal::SCOPE_READ, ChatClientPrincipal::SCOPE_WRITE], null, 'tok-ana');
        $dm  = $this->get(ChatService::class)->openDirect($ana, $bea);
        $api = '/api/chat/v1';
        $me  = $this->ok('GET', $api . '/me', 'ana');
        self::assertFalse($me['features']['attachments']);
        self::assertArrayNotHasKey('attachments', $me['limits']);
        $this->get(ChatService::class)->send($ana, $dm, 'hi');
        self::assertArrayNotHasKey('attachments', $this->ok('GET', $api . '/conversations/' . $dm->getUuid() . '/messages', 'ana')['messages'][0]);
        foreach ([['GET', '/attachments/01a1f09f-0000-7000-8000-00000000beef'], ['DELETE', '/attachments/01a1f09f-0000-7000-8000-00000000beef'], ['GET', '/conversations/' . $dm->getUuid() . '/attachments/drafts'], ['POST', '/conversations/' . $dm->getUuid() . '/attachments'], ['GET', '/files/01a1f09f-0000-7000-8000-00000000beef/original?e=1&s=x']] as [$method, $path]) {
            self::assertSame(404, $this->call($method, $api . $path, 'ana', 'personal', null, ['Idempotency-Key' => 'k'])->getStatusCode(), $path);
        }
        self::assertError($this->call('POST', $api . '/conversations/' . $dm->getUuid() . '/messages', 'ana', 'personal', json_encode(['text' => 'x', 'attachments' => []], JSON_THROW_ON_ERROR), ['Idempotency-Key' => 'k2']), 422, 'unsupported_field');
    }
}
