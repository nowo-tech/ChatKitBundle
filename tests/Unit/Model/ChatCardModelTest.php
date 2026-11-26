<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Model;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Enum\ChatCardActionKind;
use Nowo\ChatKitBundle\Enum\ChatCardState;
use Nowo\ChatKitBundle\Enum\ChatMenuSlotTarget;
use Nowo\ChatKitBundle\Event\ChatCardSharedEvent;
use Nowo\ChatKitBundle\Model\ChatCard;
use Nowo\ChatKitBundle\Model\ChatCardAction;
use Nowo\ChatKitBundle\Model\ChatCardFact;
use Nowo\ChatKitBundle\Model\ChatCardText;
use Nowo\ChatKitBundle\Model\ChatComposerAction;
use Nowo\ChatKitBundle\Model\ChatComposerScope;
use Nowo\ChatKitBundle\Model\ChatMenuSlot;
use Nowo\ChatKitBundle\Model\ChatReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * 2.6 (spec 259): the card and slot value objects: plain text clipped and cleaned, same-origin paths only, key
 * grammar, caps on facts and actions.
 */
final class ChatCardModelTest extends TestCase
{
    public function testCardTextRules(): void
    {
        self::assertSame('a b', ChatCardText::clip("  a\u{202E}b \n", 10));
        self::assertSame('abc', ChatCardText::clip('abcdef', 3));
        self::assertSame('x', ChatCardText::required(' x ', 5, 'A card'));
        self::assertSame('key', ChatCardText::icon('Bad icon', 'key'));
        self::assertSame('lock', ChatCardText::icon('lock', 'key'));
        self::assertTrue(ChatCardText::isPath('/vault/1?x=1'));
        foreach (['//evil', 'https://evil.test/', 'javascript:alert(1)', '/a b', "/a\u{00A0}b", '/a\\b', 'vault', ''] as $bad) {
            self::assertFalse(ChatCardText::isPath($bad), $bad);
        }
    }

    public function testCardKeepsPlainTextAndCaps(): void
    {
        $facts   = [new ChatCardFact('A', '1'), new ChatCardFact('B', str_repeat('x', 200)), new ChatCardFact('C', ''), new ChatCardFact('D', '4'), new ChatCardFact('E', '5')];
        $actions = [
            new ChatCardAction('open', 'Open', ChatCardActionKind::Open, '/a'),
            new ChatCardAction('request_access', 'Request access', ChatCardActionKind::RequestAccess, '/b', true),
            new ChatCardAction('host', 'More', ChatCardActionKind::Host, '/c'),
            new ChatCardAction('extra', 'Extra', ChatCardActionKind::Host, '/d'),
        ];
        $card = new ChatCard(ChatCardState::Locked, " Title\u{2066} ", 'Sub', 'Badge', 'pink', $facts, $actions);
        self::assertSame('Title', $card->title);
        self::assertSame('neutral', $card->tone);
        self::assertCount(ChatCard::MAX_FACTS, $card->facts);
        self::assertCount(ChatCard::MAX_ACTIONS, $card->actions);
        self::assertSame(ChatCardFact::VALUE_MAX, mb_strlen($card->facts[1]->value));
        self::assertSame(['state' => 'locked', 'facts' => [['label' => 'A', 'value' => '1']], 'actions' => [['key' => 'open', 'label' => 'Open', 'kind' => 'open', 'url' => '/a', 'dialog' => false]]], ['state' => $card->toArray()['state'], 'facts' => [$card->toArray()['facts'][0]], 'actions' => [$card->toArray()['actions'][0]]]);
        self::assertSame('success', (new ChatCard(ChatCardState::Open, 'T', tone: 'success'))->tone);

        $reference = new ChatReference('vault_item', 'v1', 'T', '', card: $card);
        self::assertSame('locked', $reference->toArray()['card']['state'] ?? null);
        self::assertArrayNotHasKey('card', (new ChatReference('task', '1', 'T', '/t'))->toArray());
    }

    /**
     * @return iterable<string, array{callable(): mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'empty title' => [static fn (): ChatCard => new ChatCard(ChatCardState::Open, "\u{200E} ")];
        // @phpstan-ignore argument.type (a wrong fact, on purpose)
        yield 'fact type' => [static fn (): ChatCard => new ChatCard(ChatCardState::Open, 'T', facts: ['x'])];
        // @phpstan-ignore argument.type (a wrong action, on purpose)
        yield 'action type' => [static fn (): ChatCard => new ChatCard(ChatCardState::Open, 'T', actions: ['x'])];
        yield 'fact label' => [static fn (): ChatCardFact => new ChatCardFact(' ', 'v')];
        yield 'action url' => [static fn (): ChatCardAction => new ChatCardAction('open', 'Open', ChatCardActionKind::Open, 'https://evil.test')];
        yield 'action key' => [static fn (): ChatCardAction => new ChatCardAction('Open', 'Open', ChatCardActionKind::Open, '/a')];
        yield 'composer url' => [static fn (): ChatComposerAction => new ChatComposerAction('a', 'A', 'key', '//evil')];
        yield 'composer slash' => [static fn (): ChatComposerAction => new ChatComposerAction('a', 'A', 'key', '/a', '/secret')];
        yield 'composer label' => [static fn (): ChatComposerAction => new ChatComposerAction('a', '', 'key', '/a')];
        yield 'slot card types' => [static fn (): ChatMenuSlot => new ChatMenuSlot('a', 'A', 'key', ChatMenuSlotTarget::Card, '/a')];
        yield 'slot card type key' => [static fn (): ChatMenuSlot => new ChatMenuSlot('a', 'A', 'key', ChatMenuSlotTarget::Card, '/a', ['Bad'])];
        // @phpstan-ignore argument.type (a wrong card type, on purpose)
        yield 'slot card type value' => [static fn (): ChatMenuSlot => new ChatMenuSlot('a', 'A', 'key', ChatMenuSlotTarget::Card, '/a', [1])];
    }

    /**
     * @param callable(): mixed $build
     */
    #[DataProvider('invalid')]
    public function testInvalidValuesAreRefused(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $build();
    }

    public function testSlotsComposerActionsAndEvent(): void
    {
        $action = new ChatComposerAction('secret', ' One-time secret ', 'Bad Icon', '/host/secret', 'secret', 3);
        self::assertSame(['secret', 'One-time secret', 'key', '/host/secret', 'secret', 3], [$action->key, $action->label, $action->icon, $action->dialogUrl, $action->slash, $action->order]);
        self::assertNull((new ChatComposerAction('a', 'A', 'lock', '/a'))->slash);

        $slot = new ChatMenuSlot('info', 'Info', 'key', ChatMenuSlotTarget::Card, '/i', ['vault_item', 'vault_item', 'note'], true);
        self::assertSame(['vault_item', 'note'], $slot->cardTypes);
        self::assertTrue($slot->ownOnly);
        self::assertSame([], (new ChatMenuSlot('m', 'M', 'key', ChatMenuSlotTarget::Message, '/m', ['note']))->cardTypes, 'card types only for card slots');

        $scope = new ChatComposerScope('u', 'c', 'channel', 'acme', 'ctx', false);
        self::assertSame(['u', 'c', 'channel', 'acme', 'ctx', false], [$scope->viewerPublicId, $scope->conversationUuid, $scope->conversationKind, $scope->channelKey, $scope->contextKey, $scope->canSend]);

        $event = new ChatCardSharedEvent('vault_item', 'v1', 'c', 'm', 'u');
        self::assertSame(['vault_item', 'v1', 'c', 'm', 'u'], [$event->cardType, $event->cardId, $event->conversationUuid, $event->messageUuid, $event->authorPublicId]);
    }
}
