<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;

use const JSON_THROW_ON_ERROR;

/**
 * Support ticket API per profile: the requester (intake, topic, continue, reopen, own tickets), an agent (inbox
 * filters and counts, topic, status, assignment, a requester's tickets) and an outsider (404 everywhere).
 */
final class ChatSupportTicketsApiTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testRequesterAgentAndOutsider(): void
    {
        $ana      = $this->user('Ana');
        $agent    = $this->user('Agent Smith');
        $outsider = $this->user('Bob');

        // Requester: support opens the intake draft with the topics.
        $config = $this->login($ana);
        $csrf   = $config['csrf'];
        self::assertStringEndsWith('/chat/api/support/__uuid__/topic', $config['endpoints']['supportTopic']);
        $draft = $this->postJson('/chat/api/support', ['context' => ['url' => '/billing', 'label' => 'Billing']], $csrf)['conversation'];
        self::assertNull($draft['ticket']['number']);
        self::assertSame('topic', $draft['intake']['prompt']);
        self::assertSame('Support', $draft['title']);
        $this->postJson('/chat/api/conversations/' . $draft['uuid'] . '/messages', ['body' => 'Invoice 42 is wrong'], $csrf);
        $this->postJson('/chat/api/support/' . $draft['uuid'] . '/topic', ['topic' => 'nope'], $csrf);
        $this->assertStatus(422);
        self::assertSame('topic', $this->decode()['error']);
        $opened = $this->postJson('/chat/api/support/' . $draft['uuid'] . '/topic', ['topic' => 'billing'], $csrf);
        self::assertSame('SUP-000001', $opened['conversation']['ticket']['number']);
        self::assertSame('SUP-000001 · Billing', $opened['conversation']['title']);
        self::assertArrayNotHasKey('intake', $opened['conversation']);
        self::assertSame('Tell us the invoice number or the plan your question is about.', strip_tags($opened['messages'][1]['html']));
        $ticketUuid = $draft['uuid'];
        self::assertSame(['SUP-000001'], array_column($this->getJson('/chat/api/support/tickets')['tickets'], 'number'));
        $this->postJson('/chat/api/support/' . $ticketUuid . '/topic', ['topic' => 'incident'], $csrf);
        $this->assertStatus(404, 'the requester does not change the topic of a ticket');
        $this->postJson('/chat/api/support/' . $ticketUuid . '/assign', ['assignee' => null], $csrf);
        $this->assertStatus(404, 'only agents assign');
        $this->postJson('/chat/api/support/' . $ticketUuid . '/status', ['status' => 'closed'], $csrf);
        $this->assertStatus(422, 'the requester only reopens');
        $this->client->request(Request::METHOD_GET, '/chat/api/support/tickets?owner=' . $agent->getChatPublicId());
        $this->assertStatus(404, 'not someone else\'s tickets');

        // Agent: inbox with topics and counts, assign, resolve.
        $agentCsrf = $this->login($agent)['csrf'];
        $inbox     = $this->getJson('/chat/api/support/inbox?status=open&topic=billing');
        self::assertSame(['Ana'], array_column($inbox['threads'], 'title'));
        self::assertSame(1, array_column($inbox['topics'], 'count', 'key')['billing']);
        self::assertSame([], $this->getJson('/chat/api/support/inbox?status=all&topic=incident')['threads']);
        $this->postJson('/chat/api/support/' . $ticketUuid . '/assign', ['assignee' => 'unknown'], $agentCsrf);
        $this->assertStatus(422);
        $this->postJson('/chat/api/support/' . $ticketUuid . '/assign', ['assignee' => $outsider->getChatPublicId()], $agentCsrf);
        $this->assertStatus(422, 'only agents are assignees');
        self::assertSame('Agent Smith', $this->postJson('/chat/api/support/' . $ticketUuid . '/assign', ['assignee' => $agent->getChatPublicId()], $agentCsrf)['conversation']['ticket']['assignee']['name']);
        self::assertSame('SUP-000001 · Account and access', $this->postJson('/chat/api/support/' . $ticketUuid . '/topic', ['topic' => 'account'], $agentCsrf)['conversation']['subtitle']);
        $this->postJson('/chat/api/support/' . $ticketUuid . '/status', ['status' => 'bogus'], $agentCsrf);
        $this->assertStatus(422);
        self::assertSame('resolved', $this->postJson('/chat/api/support/' . $ticketUuid . '/status', ['status' => 'resolved'], $agentCsrf)['conversation']['ticket']['status']);
        self::assertSame(['SUP-000001'], array_column($this->getJson('/chat/api/support/tickets?owner=' . $ana->getChatPublicId())['tickets'], 'number'));
        $this->client->request(Request::METHOD_GET, '/chat/api/support/tickets?owner=unknown');
        $this->assertStatus(404);

        // Outsider: nothing of Ana's ticket is reachable.
        $outsiderCsrf = $this->login($outsider)['csrf'];
        foreach (['topic' => ['topic' => 'billing'], 'resume' => [], 'status' => ['status' => 'open'], 'assign' => ['assignee' => null]] as $action => $body) {
            $this->postJson('/chat/api/support/' . $ticketUuid . '/' . $action, $body, $outsiderCsrf);
            $this->assertStatus(404, $action);
        }
        $this->client->request(Request::METHOD_GET, '/chat/api/support/inbox');
        $this->assertStatus(404);
        $channelUuid = $this->postJson('/chat/api/direct', ['userUuid' => $ana->getChatPublicId()], $outsiderCsrf)['conversation']['uuid'];
        $this->postJson('/chat/api/support/' . $channelUuid . '/status', ['status' => 'open'], $outsiderCsrf);
        $this->assertStatus(404, 'not a support conversation');

        // Requester: resolved → "continue or new?"; Continue moves the draft text and reopens.
        $csrf  = $this->login($ana)['csrf'];
        $draft = $this->postJson('/chat/api/support', [], $csrf)['conversation'];
        self::assertSame('resume', $draft['intake']['prompt']);
        self::assertSame('SUP-000001', $draft['intake']['resume']['number']);
        $this->postJson('/chat/api/support/' . $draft['uuid'] . '/resume', [], $csrf);
        $this->assertStatus(404, 'a draft is not a ticket');
        $resumed = $this->postJson('/chat/api/support/' . $ticketUuid . '/resume', ['draft' => $draft['uuid']], $csrf)['conversation'];
        self::assertSame('open', $resumed['ticket']['status']);
        self::assertSame($ticketUuid, $this->postJson('/chat/api/support', [], $csrf)['conversation']['uuid'], 'active again');

        // Resolved long ago: read-only, Reopen refused, the question offers a new ticket only.
        $ticket = $this->get(ChatConversationRepository::class)->findOneByUuid($ticketUuid);
        assert($ticket instanceof ChatConversation);
        $resolver = $this->em->find(TestUser::class, $agent->getId());
        assert($resolver instanceof TestUser);
        $ticket->resolve($resolver, new DateTimeImmutable('-60 days'));
        $this->em->flush();
        $this->postJson('/chat/api/support/' . $ticketUuid . '/status', ['status' => 'open'], $csrf);
        $this->assertStatus(422);
        self::assertSame('read_only', $this->decode()['error']);
        $this->postJson('/chat/api/support/' . $ticketUuid . '/resume', [], $csrf);
        $this->assertStatus(422);
        self::assertFalse($this->getJson('/chat/api/conversations/' . $ticketUuid)['conversation']['canSend']);
        $draft = $this->postJson('/chat/api/support', [], $csrf)['conversation'];
        self::assertSame('topic', $draft['intake']['prompt']);
        self::assertTrue($draft['intake']['tickets'][0]['readOnly']);
    }

    /**
     * @return array<string, mixed>
     */
    private function login(TestUser $user): array
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request(Request::METHOD_GET, '/page');
        /** @var array<string, mixed> $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url): array
    {
        $this->client->request(Request::METHOD_GET, $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $body, string $csrf): array
    {
        $this->client->request(Request::METHOD_POST, $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    private function assertStatus(int $status, string $message = ''): void
    {
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), $message . ' ' . $this->client->getResponse()->getContent());
    }
}
