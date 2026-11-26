<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Event\ChatCardSharedEvent;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Card\TestVaultCardProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingPushSender;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

use function array_column;
use function array_filter;
use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function assert;
use function count;
use function json_decode;
use function json_encode;
use function substr_count;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

/**
 * 2.6 (spec 259): typed cards, composer actions and message menu slots through the dock's JSON API. A secret card is
 * stored with the neutral title only, refused when the author may not share it, rendered per reader (open, locked or
 * the neutral chip), never named in push, previews or hub payloads, kept unchanged by an edit, and never forwarded.
 */
final class ChatCardsApiTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    /** @var list<ChatCardSharedEvent> */
    private array $shared = [];

    protected function cardProviders(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => [
            'push'         => true,
            'push_preview' => true,
            'message_menu' => ['forward' => ['enabled' => true], 'order' => ['reply', 'slot:report', 'forward', 'edit', 'delete']],
        ]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
        $dispatcher   = $this->kernel->getContainer()->get('event_dispatcher');
        assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->addListener(ChatCardSharedEvent::class, function (ChatCardSharedEvent $event): void {
            $this->shared[] = $event;
        });
    }

    public function testSecretCardsAreRefusedStoredNeutralAndRenderedPerReader(): void
    {
        $ana    = $this->person('Ana');
        $luis   = $this->person('Locked Luis');
        $hidden = $this->person('Hidden Hal');
        $this->enablePush($luis);
        $channel = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        foreach ([$luis, $hidden] as $member) {
            $this->get(ChatService::class)->openChannel($member, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        }
        $config = $this->login($ana);
        self::assertSame(['endpoint', 'card', 'dialog'], array_keys($config['slots']));
        self::assertStringContainsString('/cards/__type__/__id__', $config['slots']['card']);
        $base = '/chat/api/conversations/' . $channel->getUuid();

        // A card the author may not share refuses the whole send; nothing is written, nothing dispatched.
        $refused = $this->requestJson(Request::METHOD_POST, $base . '/messages', ['body' => 'here', 'cards' => [['type' => 'vault_item', 'id' => 'nope']]], $config['csrf']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('card_not_allowed', $refused['error']);
        self::assertSame(0, $this->rows());
        self::assertSame([], $this->shared);
        // A reference type in `cards`, and malformed fields.
        foreach ([[['type' => 'task', 'id' => '1']], 'x', [['type' => 'vault_item']], [['type' => 'vault_item', 'id' => 'bad id']]] as $cards) {
            $error = $this->requestJson(Request::METHOD_POST, $base . '/messages', ['body' => 'x', 'cards' => $cards], $config['csrf'])['error'];
            self::assertContains($error, ['card_not_allowed', 'invalid_cards']);
        }
        // A card token typed in the text is a card too: refused the same way.
        $this->requestJson(Request::METHOD_POST, $base . '/messages', ['body' => 'x #[Mine](vault_item:nope)'], $config['csrf']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $realtime = $this->get(RecordingRealtime::class);
        $sent     = $this->requestJson(Request::METHOD_POST, $base . '/messages', ['body' => 'the DB #[Typed title](vault_item:v2)', 'cards' => [['type' => 'vault_item', 'id' => 'v1'], ['id' => 'n1', 'type' => 'note']]], $config['csrf'])['message'];
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $stored = $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $sent['uuid']]);
        assert($stored instanceof ChatMessage);
        self::assertSame("the DB #[…](vault_item:v2)\n\n#[…](vault_item:v1)\n\n#[…](note:n1)", $stored->getBody(), 'neutral titles only');
        self::assertStringContainsString('nowo-chat-card--open', $sent['html']);
        self::assertStringContainsString('<a class="nowo-chat-card__action nowo-chat-card__action--open" href="/vault/v1" data-card-action="open">Open</a>', $sent['html']);
        self::assertTrue($sent['hasCards']);
        self::assertSame(['vault_item:v2', 'vault_item:v1', 'note:n1'], array_map(static fn (array $ref): string => $ref['type'] . ':' . $ref['id'], $sent['references']));
        self::assertSame('open', $sent['references'][0]['card']['state']);
        self::assertSame(['v2', 'v1', 'n1'], array_column($this->shared, 'cardId'), 'one event per card, ids only');
        self::assertSame([$ana->getChatPublicId()], array_values(array_unique(array_map(static fn (ChatCardSharedEvent $event): string => $event->authorPublicId, $this->shared))));

        // The hub payload is viewer-neutral: empty slots, no title.
        $hub = json_encode($realtime->published, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Prod root DB', $hub);
        // Push to Luis: the generic secret line, never the title, even with push_preview.
        $push = json_encode($this->get(RecordingPushSender::class)->sent, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Shared a secret', $push);
        self::assertStringNotContainsString('Prod root DB', $push);
        self::assertStringContainsString('#Release notes', $push, 'a card that is not a secret reads its title');
        // SC-001: no table holds the title or the host's sentinel.
        $this->assertNoTableHolds(['Prod root DB', TestVaultCardProvider::SENTINEL, 'Typed title']);

        // Luis sees the cards locked (with Request access in the dialog), Hal the neutral chip.
        $this->login($luis);
        $message = $this->getJson($base . '/messages')['messages'][0];
        self::assertStringContainsString('nowo-chat-card--locked', $message['html']);
        self::assertStringContainsString('data-card-dialog', $message['html']);
        $preview = $this->getJson('/chat/api/conversations')['conversations'][0]['lastMessage']['preview'];
        self::assertStringContainsString('🔑 Shared a secret', $preview);
        self::assertStringNotContainsString('Prod root DB', $preview);
        $this->login($hidden);
        $html = $this->getJson($base . '/messages')['messages'][0]['html'];
        self::assertSame(2, substr_count($html, 'nowo-chat-ref--restricted'));
        self::assertStringNotContainsString('Prod root DB', $html);
        self::assertStringNotContainsString('vault_item', $html);
        $preview = $this->getJson('/chat/api/conversations')['conversations'][0]['lastMessage']['preview'];
        self::assertStringNotContainsString('secret', $preview);
        self::assertStringContainsString('Content not visible', $preview);

        // An edit changes the text only: the cards stay (not asked again), a card added in the text is dropped.
        $this->login($ana);
        $calls  = count($this->get(TestVaultCardProvider::class)->calls);
        $edited = $this->requestJson(Request::METHOD_PATCH, $base . '/messages/' . $sent['uuid'], ['body' => 'new text #[x](vault_item:nope)'], $config['csrf'])['message'];
        $this->em->clear();
        $stored = $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $sent['uuid']]);
        assert($stored instanceof ChatMessage);
        self::assertSame("new text\n\n#[…](vault_item:v2)\n\n#[…](vault_item:v1)\n\n#[…](note:n1)", $stored->getBody());
        self::assertTrue($edited['edited']);
        $asked = array_filter(array_slice($this->get(TestVaultCardProvider::class)->calls, $calls), static fn (array $call): bool => $call[0] === 'canShare');
        self::assertSame([], $asked);

        // No forward of a message with cards in v1.
        $dm = $this->get(ChatService::class)->openDirect($ana, $luis);
        $this->client->request(Request::METHOD_POST, $base . '/messages/' . $sent['uuid'] . '/forward', server: ['HTTP_X_CSRF_TOKEN' => $config['csrf'], 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'fw-1'], content: json_encode(['targets' => [['conversationUuid' => $dm->getUuid()]]], JSON_THROW_ON_ERROR));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('card_not_allowed', $this->decode()['error']);
    }

    public function testSlotsAndTheComposerChip(): void
    {
        $ana      = $this->person('Ana');
        $hidden   = $this->person('Hidden Hal');
        $stranger = $this->person('Stranger Eve');
        $dm       = $this->get(ChatService::class)->openDirect($ana, $hidden);
        $this->login($ana);
        $base = '/chat/api/conversations/' . $dm->getUuid();

        $slots = $this->getJson($base . '/slots');
        self::assertSame(['vault_item', 'note'], array_column($slots['cardTypes'], 'key'));
        self::assertSame(['key', 'key'], array_column($slots['cardTypes'], 'icon'), 'an invalid icon falls back to key');
        self::assertSame([true, false], array_column($slots['cardTypes'], 'secret'));
        self::assertSame(['secret', 'vault'], array_column($slots['composerActions'], 'key'), 'by order; the repeated key keeps the first');
        self::assertSame(['secret', null], array_column($slots['composerActions'], 'slash'), 'a slash taken by a reference type is dropped');
        self::assertSame('/host/vault?x=1', $slots['composerActions'][1]['dialogUrl']);
        self::assertSame(['save_vault', 'card_info', 'report'], array_column($slots['menuSlots'], 'key'));
        self::assertSame(['vault_item'], $slots['menuSlots'][1]['cardTypes']);
        self::assertTrue($slots['menuSlots'][2]['ownOnly']);

        $card = $this->getJson($base . '/cards/vault_item/v1');
        self::assertSame('Prod root DB', $card['card']['title']);
        self::assertSame('open', $card['card']['card']['state']);
        self::assertSame(['Vault item', 'key', true], [$card['typeLabel'], $card['icon'], $card['secret']]);
        foreach (['/cards/vault_item/v9', '/cards/task/1', '/cards/unknown/1'] as $missing) {
            $this->client->request(Request::METHOD_GET, $base . $missing);
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $missing);
        }
        $this->login($hidden);
        $this->client->request(Request::METHOD_GET, $base . '/cards/vault_item/v1');
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'a hidden card is 404');
        $this->login($stranger);
        foreach (['/slots', '/cards/vault_item/v1'] as $path) {
            $this->client->request(Request::METHOD_GET, $base . $path);
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'a stranger: 404 like reading');
        }

        // The dock: the slot dialog and the placed menu slot, then the model item for the others.
        $page = $this->client->request(Request::METHOD_GET, '/page');
        self::assertCount(1, $page->filter('[data-testid="chat-slot-dialog"]'));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-slot-key="report"', $html);
        self::assertStringContainsString('data-slot-key=""', $html);
    }

    private function person(string $name): TestUser
    {
        $user                                                                     = $this->user($name);
        $this->get(TestVaultCardProvider::class)->names[$user->getChatPublicId()] = $name;

        return $user;
    }

    private function enablePush(TestUser $user): void
    {
        $settings = $this->em->getRepository(ChatUserSettings::class)->forUser($user);
        $settings->setPushEnabled(true);
        $this->em->persist($settings);
        $this->em->flush();
    }

    /**
     * @param list<string> $needles
     */
    private function assertNoTableHolds(array $needles): void
    {
        $connection = $this->em->getConnection();
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            $dump = json_encode($connection->fetchAllAssociative('SELECT * FROM ' . $table), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            foreach ($needles as $needle) {
                self::assertStringNotContainsString($needle, $dump, $table);
            }
        }
    }

    private function rows(): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(m)')->from(ChatMessage::class, 'm')->getQuery()->getSingleScalarResult();
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
    private function requestJson(string $method, string $url, array $body, string $csrf): array
    {
        $this->client->request($method, $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));

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
}
