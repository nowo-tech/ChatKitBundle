<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use InvalidArgumentException;
use LogicException;
use Nowo\ChatKitBundle\Card\ChatCards;
use Nowo\ChatKitBundle\Contract\ChatCardProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatMessageActionsInterface;
use Nowo\ChatKitBundle\Model\ChatCardScope;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Card\TestNoteCardProvider;
use Nowo\ChatKitBundle\Tests\Support\Card\TestVaultCardProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestReferenceProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;
use function str_repeat;

/**
 * 2.6 (spec 259): ChatReferences with typed cards — the `cards` field, the per-message cap, edits that keep the cards,
 * the reader views without a conversation, the key uniqueness between cards and references, and (audit ZCG-v-I1) the
 * facade's refusal of the client API key namespace in any letter case.
 */
final class ChatCardReferencesTest extends DatabaseTestCase
{
    protected function cardProviders(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['message_menu' => ['forward' => ['enabled' => true], 'threads' => ['enabled' => true]]]]];
    }

    public function testWithCardsKeepCardsAndTheCap(): void
    {
        $references = $this->get(ChatReferences::class);
        self::assertSame(['task', 'vault_item', 'note'], $references->typeKeys());
        self::assertTrue($references->isCardType('note'));
        self::assertFalse($references->isCardType('task'));
        self::assertSame('x', $references->withCards('x', null));
        self::assertSame('x', $references->withCards('x', []));
        self::assertSame('#[…](note:n1)', $references->withCards('  ', [['type' => 'note', 'id' => 'n1'], ['type' => 'note', 'id' => 'n1']]), 'a repeated card once');
        foreach ([['a' => ['type' => 'note', 'id' => 'n1']], str_repeat('x', 3), [[1, 2]], [['type' => 1, 'id' => 'x']], [['type' => 'note', 'id' => 'n1', 'x' => 1]], [...array_fill(0, 11, ['type' => 'note', 'id' => 'n1'])]] as $bad) {
            try {
                $references->withCards('x', $bad);
                self::fail('malformed cards must be refused');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(ChatReferences::INVALID_CARDS, $exception->getMessage());
            }
        }

        self::assertSame('plain', $references->keepCards('stored', 'plain'), 'no cards on either side');
        self::assertSame('text  `#[…](note:n2)`', $references->keepCards('old', 'text #[…](note:n9) `#[…](note:n2)`'), 'a card typed in an edit is dropped; code is kept');
        self::assertSame("new\n\n#[…](note:n1)", $references->keepCards("old\n\n#[…](note:n1)", 'new'));
        self::assertSame([['type' => 'note', 'id' => 'n1']], $references->cardTokens('#[…](note:n1) #[…](note:n1) #[…](task:1) `#[…](note:n2)`'));

        $ana     = $this->named('Ana');
        $channel = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        // Ten references and a card: the card past the cap refuses the message (never plain text).
        try {
            $references->validate($channel, $ana, str_repeat('#[t](task:1) ', 10) . '#[…](note:n1)');
            self::fail('a card past the cap must be refused');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(ChatReferences::CARD_NOT_ALLOWED, $exception->getMessage());
        }
        // A card already in the stored message is not asked again (here: one Ana may not share).
        self::assertSame('#[…](vault_item:nope)', $references->validate($channel, $ana, '#[x](vault_item:nope)', ['vault_item:nope']));
    }

    public function testReaderViewsWithoutAConversationAreNeutral(): void
    {
        $references = $this->get(ChatReferences::class);
        $ana        = $this->named('Ana');
        $channel    = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $message    = $this->get(ChatService::class)->send($ana, $channel, 'see #[…](vault_item:v1) and #[…](note:n1)');

        $markdown = new ChatMarkdownRenderer();
        self::assertSame('see [Content not visible] and [Content not visible]', $references->plainText($markdown, $message->getBody(), $ana, 140));
        self::assertSame('see 🔑 Shared a secret and #Release notes', $references->plainText($markdown, $message->getBody(), $ana, 140, null, $channel));
        self::assertSame('see 🔑 Ha compartido un secreto and #Release notes', $references->plainText($markdown, $message->getBody(), $ana, 140, 'es', $channel));
        $neutral = $references->fill($message->getBodyHtml(), $message->getBody(), $ana);
        self::assertSame(2, substr_count($neutral['html'], 'nowo-chat-ref--restricted'));
        self::assertSame([], $references->cardsFor($ana, $message->getBody()));
        self::assertSame(['vault_item:v1', 'note:n1'], array_keys($references->cardsFor($ana, $message->getBody(), $channel, null, false)));
        // A card within text is still a full card; prime renders once per type and conversation.
        $references->reset();
        $deleted = $this->get(ChatService::class)->send($ana, $channel, 'gone #[…](note:n1)');
        $this->get(ChatService::class)->delete($ana, $deleted);
        $provider = $this->get(TestVaultCardProvider::class);
        $before   = count($provider->calls);
        $references->prime($ana, [$message, $deleted, $message]);
        self::assertSame(1, count($provider->calls) - $before);
        self::assertStringContainsString('nowo-chat-card--open', $references->fill('<p>' . ChatReferences::SLOT . '</p>', '#[…](vault_item:v1)', $ana, $channel)['html']);
        self::assertStringContainsString('nowo-chat-ref--block', $references->fill('<p>' . ChatReferences::SLOT . '</p>', '#[…](note:n1)', $ana, $channel)['html']);
        self::assertStringNotContainsString('nowo-chat-ref--block', $references->fill('<p>a ' . ChatReferences::SLOT . ' b</p>', 'a #[…](note:n1) b', $ana, $channel)['html']);
        self::assertSame(['label' => 'Note', 'icon' => 'key'], $references->typeView('note'));
        self::assertNull($references->typeView('unknown'));
    }

    public function testCardAndReferenceKeysMustBeUnique(): void
    {
        $taskCards = new ChatCards([new class implements ChatCardProviderInterface {
            public function key(): string
            {
                return 'task';
            }

            public function label(): string
            {
                return 'Task';
            }

            public function icon(): string
            {
                return 'key';
            }

            public function isSecret(): bool
            {
                return false;
            }

            public function canShare(ChatCardScope $authorScope, string $id): bool
            {
                return false;
            }

            public function render(ChatCardScope $readerScope, array $ids): array
            {
                return [];
            }
        }]);
        $references = new ChatReferences([new TestReferenceProvider()], $this->get(ChatAccess::class), $this->get(TranslatorInterface::class), $taskCards);
        $this->expectException(LogicException::class);
        $references->typeKeys();
    }

    public function testCardsOffMeansPlainReferences(): void
    {
        $references = new ChatReferences([new TestReferenceProvider()], $this->get(ChatAccess::class), $this->get(TranslatorInterface::class));
        self::assertFalse($references->hasCards('#[…](note:n1)'));
        self::assertSame("x\n\nkept", $references->keepCards('#[…](note:n1)', "x\n\nkept"));
        self::assertSame(['task'], $references->typeKeys());
        $this->expectException(InvalidArgumentException::class);
        $references->withCards('x', [['type' => 'note', 'id' => 'n1']]);
    }

    public function testFacadeRefusesTheClientKeyNamespaceInAnyCase(): void
    {
        $ana     = $this->named('Ana');
        $luis    = $this->named('Luis');
        $chat    = $this->get(ChatService::class);
        $dm      = $chat->openDirect($ana, $luis);
        $message = $chat->send($ana, $dm, 'hello');
        $menu    = $this->get(ChatMessageActionsInterface::class);
        foreach (['client:abc', 'CLIENT:abc', 'Client:abc'] as $key) {
            try {
                $menu->replyInThread($ana, $dm->getUuid(), $message->getUuid(), 'reply', $key);
                self::fail('the client namespace must be refused: ' . $key);
            } catch (InvalidArgumentException $exception) {
                self::assertSame('idempotency_key_invalid', $exception->getMessage());
            }
        }
        // A message with cards is never forwarded (DDD path too).
        $carded = $chat->send($ana, $dm, '#[…](note:n1)');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('card_not_allowed');
        $menu->forward($ana, $dm->getUuid(), $carded->getUuid(), [['conversationUuid' => $dm->getUuid()]], null, 'fw-1');
    }

    private function named(string $name): TestUser
    {
        $user                                                                     = $this->user($name);
        $this->get(TestVaultCardProvider::class)->names[$user->getChatPublicId()] = $name;
        // The note provider needs no names.
        $this->get(TestNoteCardProvider::class);

        return $user;
    }
}
