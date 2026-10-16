<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;

/**
 * The full-page chat (FR-005): server-rendered list and thread, form POST + redirect, same permissions as the API.
 */
final class ChatPageTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['limits' => ['send_per_minute' => 2]]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testGuestsAndUnreachableUsersAreDenied(): void
    {
        $this->client->request(Request::METHOD_GET, '/chat/');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->client->loginUser($this->user('Disabled Dan'));
        $this->client->request(Request::METHOD_GET, '/chat/');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testListThreadAndSend(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $eve  = $this->user('Eve');
        $dm   = $chat->openDirect($ana, $luis);
        $chat->send($luis, $dm, 'Hola **Ana**');
        $chat->openDirect($ana, $eve); // empty direct conversations stay out of the list
        $foreign = $chat->openDirect($luis, $eve);

        $this->client->loginUser($ana);
        $this->client->request(Request::METHOD_GET, '/chat');
        self::assertSame(301, $this->client->getResponse()->getStatusCode(), 'the root route keeps Symfony\'s trailing slash');
        $crawler = $this->client->request(Request::METHOD_GET, '/chat/');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('Messages', $crawler->filter('title')->text());
        self::assertSame(['Luis'], $crawler->filter('.nowo-chat-page__row-title')->each(static fn ($node): string => $node->text()));
        self::assertCount(1, $crawler->filter('.nowo-chat-page__row.is-unread'));
        self::assertSame('Pick a conversation.', $crawler->filter('.nowo-chat-page__thread .nowo-chat-page__empty')->text());

        // The thread offers the composer; the GET changes nothing (REQ-SEC-005): the page script marks it read
        // through the CSRF-protected API, with the URL, message id and header token the page carries.
        $crawler = $this->client->request(Request::METHOD_GET, '/chat/' . $dm->getUuid());
        self::assertSame('Luis', $crawler->filter('title')->text());
        self::assertSame('<p>Hola <strong>Ana</strong></p>', $crawler->filter('#nowo-chat-latest .nowo-chat-bubble')->html());
        self::assertCount(0, $crawler->filter('.nowo-chat-page__row.is-unread'), 'the open conversation shows no unread badge');
        self::assertCount(1, $crawler->filter('.nowo-chat-page__row.is-current[aria-current="page"]'));
        self::assertCount(1, $crawler->filter('link[href="/bundles/nowochatkit/css/nowo-chat.css"]'));
        self::assertCount(1, $crawler->filter('script[src="/bundles/nowochatkit/js/nowo-chat.js"]'));
        self::assertSame([$dm->getId() => 1], $this->get(ChatMessageRepository::class)->countUnreadByConversation((int) $ana->getId(), [(int) $dm->getId()]));
        $page = $crawler->filter('[data-testid="chat-page"]');
        self::assertSame('/chat/api/conversations/' . $dm->getUuid() . '/read', $page->attr('data-nowo-chat-read-url'));
        $this->client->request(Request::METHOD_POST, (string) $page->attr('data-nowo-chat-read-url'), server: ['HTTP_X_CSRF_TOKEN' => (string) $page->attr('data-nowo-chat-read-csrf'), 'CONTENT_TYPE' => 'application/json'], content: '{"messageId":' . $page->attr('data-nowo-chat-read-id') . '}');
        self::assertLessThan(300, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->get(ChatMessageRepository::class)->countUnreadByConversation((int) $ana->getId(), [(int) $dm->getId()]));
        $form = $crawler->filter('.nowo-chat-page__composer')->form(['nowo_chat_message[body]' => 'Hey _Luis_']);
        $this->client->submit($form);
        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertStringEndsWith('/chat/' . $dm->getUuid() . '#nowo-chat-latest', (string) $this->client->getResponse()->headers->get('Location'));
        $crawler = $this->client->followRedirect();
        self::assertSame('<p>Hey <em>Luis</em></p>', $crawler->filter('#nowo-chat-latest .nowo-chat-bubble')->html());
        self::assertCount(0, $crawler->filter('[role="alert"]'));

        // Errors come back through the redirect.
        $this->client->request(Request::METHOD_POST, '/chat/' . $dm->getUuid(), ['nowo_chat_message' => ['_token' => 'bad', 'body' => 'x']]);
        self::assertStringContainsString('error=error', (string) $this->client->getResponse()->headers->get('Location'));
        $crawler = $this->client->followRedirect();
        self::assertSame('Something went wrong. Try again.', $crawler->filter('[role="alert"]')->text());
        $this->client->submit($form, ['nowo_chat_message[body]' => str_repeat('a', 4001)]);
        self::assertStringContainsString('error=too_long', (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->submit($form, ['nowo_chat_message[body]' => 'third']);
        self::assertStringContainsString('error=rate_limited', (string) $this->client->getResponse()->headers->get('Location'));

        // IDOR: someone else's conversation is a 404, read or write.
        $this->client->request(Request::METHOD_GET, '/chat/' . $foreign->getUuid());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request(Request::METHOD_POST, '/chat/' . $foreign->getUuid(), ['nowo_chat_message' => ['body' => 'x']]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testReadOnlyConversation(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = $chat->openDirect($ana, $luis);
        $chat->send($ana, $dm, 'Hola');
        $this->client->loginUser($ana);
        $token = $this->client->request(Request::METHOD_GET, '/chat/' . $dm->getUuid())->filter('input[name="nowo_chat_message[_token]"]')->attr('value');
        // Luis loses the right to be messaged: the conversation stays readable, never writable.
        $this->em->createQuery('UPDATE ' . TestUser::class . ' u SET u.name = :name WHERE u.id = :id')
            ->setParameters(['name' => 'Stranger Luis', 'id' => $luis->getId()])->execute();
        $this->em->clear();

        $crawler = $this->client->request(Request::METHOD_GET, '/chat/' . $dm->getUuid());
        self::assertCount(0, $crawler->filter('.nowo-chat-page__composer'));
        self::assertSame('You can no longer message this person.', $crawler->filter('.nowo-chat-page__thread .nowo-chat-notice')->text());
        $this->client->request(Request::METHOD_POST, '/chat/' . $dm->getUuid(), ['nowo_chat_message' => ['_token' => $token, 'body' => 'x']]);
        self::assertStringContainsString('error=read_only', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
