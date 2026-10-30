<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;

/**
 * Real kernel with FormKit: the chat_kit profile is prepended and the page / dock forms render through it,
 * with translated labels and no raw translation keys (REQ-TWIG-005 rule 11).
 */
final class ChatPageFormKitTest extends DatabaseTestCase
{
    public function testThreadPageRendersFormKitForms(): void
    {
        $profiles = $this->kernel->getContainer()->getParameter('nowo_form_kit.profiles');
        self::assertIsArray($profiles);
        self::assertSame('NowoChatKitBundle', $profiles[NowoChatKitBundle::FORM_KIT_PROFILE]['translation_domain']);

        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $ana = $this->user('Ana');
        $dm  = $this->get(ChatService::class)->openDirect($ana, $this->user('Luis'));

        $client->loginUser($ana);
        $crawler = $client->request(Request::METHOD_GET, '/chat/' . $dm->getUuid());
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $body = $crawler->filter('textarea[name="nowo_chat_message[body]"]');
        self::assertCount(1, $body);
        self::assertNotSame('chat.composer_placeholder', $body->attr('placeholder'));
        self::assertNotSame('chat.composer_placeholder', $crawler->filter('label[for="' . $body->attr('id') . '"]')->text());

        $html = (string) $client->getResponse()->getContent();
        self::assertDoesNotMatchRegularExpression('/(>|placeholder="|aria-label="|title=")\s*chat\.[a-z_]+\s*("|<)/', $html);
    }
}
