<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use LogicException;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatReference;
use Nowo\ChatKitBundle\Model\ChatReferenceScope;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestBrokenReferenceProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestReferenceProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function str_repeat;

/**
 * ChatReferences rules not reached through the API: parsing outside code, the per-message cap, the placeholder of a
 * slot without a token, plain-text previews per reader, scopes and the per-request cache.
 */
final class ChatReferencesTest extends DatabaseTestCase
{
    public function testParseTokenAndRendererSlots(): void
    {
        self::assertSame(
            [['title' => 'A', 'type' => 'task', 'id' => '1'], ['title' => 'A', 'type' => 'task', 'id' => '1']],
            ChatReferences::parse('#[A](task:1) `#[B](task:2)` ```#[C](task:3)``` #[A](task:1) #[bad](Task:1) #[x](task:with space)'),
        );
        self::assertSame('#[a b ?](task:1)', ChatReferences::token(new ChatReference('task', '1', "a [b]\n?", '/x')));
        self::assertSame('#[?](task:1)', ChatReferences::token(new ChatReference('task', '1', ' ', '/x')));

        $renderer = new ChatMarkdownRenderer();
        self::assertSame('<p><strong>see</strong> <span class="nowo-chat-ref-slot"></span> and <code>#[B](task:2)</code></p>', $renderer->render('**see** #[A](task:1) and `#[B](task:2)`'));
        self::assertSame('see #… now', $renderer->plainText('see #[Secret](task:2) now'), 'no title without a reader');
    }

    public function testValidateKeepsAtMostTenAndLeavesCodeAlone(): void
    {
        $references = $this->get(ChatReferences::class);
        $ana        = $this->user('Ana');
        $channel    = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));

        self::assertSame('no tokens', $references->validate($channel, $ana, 'no tokens'));
        $body = $references->validate($channel, $ana, str_repeat('#[t](task:1) ', 11) . '`#[t](task:1)`');
        self::assertSame(10, substr_count($body, '#[Deploy the API](task:1)'));
        self::assertStringEndsWith('#t `#[t](task:1)`', $body);
    }

    public function testFillPrimePlainTextAndScopes(): void
    {
        $references = $this->get(ChatReferences::class);
        $chat       = $this->get(ChatService::class);
        $ana        = $this->user('Ana');
        $luis       = $this->user('Luis');
        $nora       = $this->user('NoTasks Nora');
        self::assertTrue($references->isEnabled());

        $direct = $chat->openDirect($ana, $luis);
        $scope  = $references->scopeFor($direct, $ana);
        self::assertSame([$ana, $luis], $scope->audience);
        self::assertNull($scope->channelKey());
        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        self::assertSame(TestChannelProvider::KEY, $references->scopeFor($channel, $ana)->channelKey());
        self::assertSame([$ana], $references->scopeFor($channel, $ana)->audience);
        self::assertSame([$nora], ChatReferenceScope::reader($nora)->audience);

        // A slot without its token (HTML and body out of step) shows the neutral chip.
        $filled = $references->fill(ChatReferences::SLOT . ChatReferences::SLOT, '#[x](task:1)', $luis);
        self::assertStringContainsString('Deploy the API', $filled['html']);
        self::assertStringContainsString('nowo-chat-ref--restricted', $filled['html']);
        self::assertSame(['html' => '<p>x</p>', 'references' => [], 'pending' => false], $references->fill('<p>x</p>', 'x', $luis));

        self::assertSame('see #Deploy the API and [Content not visible]', $references->plainText(new ChatMarkdownRenderer(), 'see #[a](task:1) and #[b](task:2)', $luis, 140));
        self::assertSame('[Contenido no visible]', $references->plainText(new ChatMarkdownRenderer(), '#[a](task:1)', $nora, 140, 'es'));
        self::assertSame('plain', $references->plainText(new ChatMarkdownRenderer(), 'plain', $nora, 140));

        // Priming resolves several messages at once; deleted and bot messages are skipped; reset() forgets the cache.
        $message = $chat->send($ana, $channel, 'one #[x](task:1)');
        $deleted = $chat->send($ana, $channel, 'two #[x](task:2)');
        $chat->delete($ana, $deleted);
        $bot = ChatMessage::bot($channel, 'chat.support_bot', null);
        $references->reset();
        $references->prime($luis, [$message, $deleted, $bot]);
        self::assertStringContainsString('Deploy the API', $references->fill($message->getBodyHtml(), $message->getBody(), $luis)['html']);

        // The conversation preview of the list shows the reader's view.
        $summary = $this->get(ChatPresenter::class)->conversation($channel, $nora, null, new ChatChannel(TestChannelProvider::KEY, 'Acme'), true, 0, $message);
        self::assertSame('one [Content not visible]', $summary['lastMessage']['preview']);
    }

    public function testInvalidOrDuplicateKeysFailLoudly(): void
    {
        $access     = $this->get(ChatAccess::class);
        $translator = $this->get(TranslatorInterface::class);
        foreach ([[new TestBrokenReferenceProvider('Bad Key')], [new TestReferenceProvider(), new TestBrokenReferenceProvider('task')]] as $providers) {
            try {
                (new ChatReferences($providers, $access, $translator))->isEnabled();
                self::fail('a broken provider list must fail');
            } catch (LogicException $exception) {
                self::assertStringContainsString('TestBrokenReferenceProvider', $exception->getMessage());
            }
        }
        self::assertFalse((new ChatReferences([], $access, $translator))->isEnabled());
    }
}
