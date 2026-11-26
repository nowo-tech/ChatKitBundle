<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Card;

use ArrayIterator;
use LogicException;
use Nowo\ChatKitBundle\Card\ChatCards;
use Nowo\ChatKitBundle\Contract\ChatComposerActionProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatMessageMenuSlotProviderInterface;
use Nowo\ChatKitBundle\Enum\ChatMenuSlotTarget;
use Nowo\ChatKitBundle\Model\ChatCard;
use Nowo\ChatKitBundle\Model\ChatCardScope;
use Nowo\ChatKitBundle\Model\ChatComposerAction;
use Nowo\ChatKitBundle\Model\ChatComposerScope;
use Nowo\ChatKitBundle\Model\ChatMenuSlot;
use Nowo\ChatKitBundle\Tests\Support\Card\FlakyCardProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function array_map;
use function array_slice;
use function json_encode;
use function range;

use const JSON_THROW_ON_ERROR;

/**
 * 2.6 (spec 259): ChatCards isolates every provider (a failure denies and is logged without ids or texts), keeps keys
 * unique, batches renders and keeps only what was asked.
 */
final class ChatCardsTest extends TestCase
{
    public function testEmptyServiceOffersNothing(): void
    {
        $cards = new ChatCards();
        self::assertFalse($cards->isEnabled());
        self::assertFalse($cards->hasSlots());
        self::assertFalse($cards->hasMenuSlots());
        self::assertFalse($cards->isCardType('vault_item'));
        self::assertTrue($cards->isSecret('vault_item'), 'an unknown type counts as a secret');
        self::assertNull($cards->typeView('vault_item'));
        self::assertSame([], $cards->types());
        self::assertFalse($cards->canShare(self::scope(), 'vault_item', 'v1'));
        self::assertSame([], $cards->render(self::scope(), 'vault_item', ['v1']));
        self::assertSame([], $cards->composerActions(self::composer()));
        self::assertSame([], $cards->menuSlots(self::composer()));
        // Generators (not countable) are walked.
        $lazy = new ChatCards(self::lazy([]), self::lazy([self::actions([])]), self::lazy([]));
        self::assertTrue($lazy->hasSlots());
        self::assertFalse((new ChatCards(self::lazy([]), self::lazy([]), self::lazy([])))->hasSlots());
    }

    public function testRenderBatchesKeepsAskedIdsAndDeniesOnFailure(): void
    {
        $provider = self::provider('vault_item');
        $logger   = new RecordingLogger();
        $cards    = new ChatCards([$provider], [], [], $logger);
        self::assertTrue($cards->isEnabled());
        self::assertTrue($cards->hasSlots());
        self::assertSame(['vault_item'], $cards->keys());
        self::assertSame([['key' => 'vault_item', 'label' => 'Vault item', 'icon' => 'key', 'secret' => true]], $cards->types());

        $ids      = array_map('strval', range(1, 120));
        $rendered = $cards->render(self::scope(), 'vault_item', $ids);
        self::assertCount(119, $rendered, 'an answer that is not a card is left out');
        self::assertArrayNotHasKey('1', $rendered);
        self::assertInstanceOf(ChatCard::class, $rendered['120'] ?? null, 'int keys match the asked string ids');
        self::assertSame([50, 50, 20], array_map('count', $provider->batches));
        self::assertArrayNotHasKey('zz', $rendered, 'only what was asked');
        self::assertSame([], $cards->render(self::scope(), 'vault_item', []));
        self::assertSame([], $cards->render(self::scope(), 'other', ['1']));

        self::assertTrue($cards->canShare(self::scope(), 'vault_item', 'v1'));
        self::assertFalse($cards->canShare(self::scope(), 'vault_item', 'bad id'), 'a malformed id is never asked');
        self::assertFalse($cards->canShare(self::scope(), 'note', 'v1'));

        $provider->fail = true;
        self::assertFalse($cards->canShare(self::scope(), 'vault_item', 'v1'));
        self::assertSame([], $cards->render(self::scope(), 'vault_item', ['v1']));
        self::assertTrue($cards->isSecret('vault_item'));
        self::assertNull($cards->typeView('vault_item'));
        self::assertSame([], $cards->types());
        self::assertNotSame([], $logger->lines);
        self::assertStringNotContainsString('v1', json_encode($logger->lines, JSON_THROW_ON_ERROR), 'no id in the log');
    }

    public function testInvalidOrRepeatedCardKeysFailLoudly(): void
    {
        foreach ([[self::provider('Bad Key')], [self::provider('vault_item'), self::provider('vault_item')]] as $providers) {
            try {
                (new ChatCards($providers))->isEnabled();
                self::fail('a broken provider list must fail');
            } catch (LogicException $exception) {
                self::assertStringContainsString('is invalid or already taken', $exception->getMessage());
            }
        }
    }

    public function testComposerActionsAndMenuSlots(): void
    {
        $logger  = new RecordingLogger();
        $actions = self::actions([
            new ChatComposerAction('b', 'B', 'key', '/b', 'task', 2),
            new ChatComposerAction('a', 'A', 'key', '/a', 'secret', 1),
            new ChatComposerAction('c', 'C', 'key', '/c', 'secret', 3),
            new ChatComposerAction('a', 'Again', 'key', '/a2'),
        ]);
        $broken = new class implements ChatComposerActionProviderInterface {
            public function actions(ChatComposerScope $scope): iterable
            {
                // @phpstan-ignore generator.valueType (a wrong item, on purpose)
                yield 'not an action';
            }
        };
        $many  = self::actions(array_map(static fn (int $i): ChatComposerAction => new ChatComposerAction('m' . $i, 'M', 'key', '/m', null, 10 + $i), range(1, 10)));
        $cards = new ChatCards([], [$actions, $broken, $many], [], $logger);
        $view  = $cards->composerActions(self::composer(), ['task']);
        self::assertSame(['a', 'b', 'c', 'm1', 'm2', 'm3', 'm4', 'm5'], array_column($view, 'key'), 'by order, at most MAX_ACTIONS');
        self::assertSame(['secret', null, null], array_column(array_slice($view, 0, 3), 'slash'), 'a reserved or repeated slash is dropped');
        self::assertSame([], $cards->composerActions(new ChatComposerScope('u', 'c', 'channel', null, null, false)), 'nothing to a reader');
        self::assertNotSame([], $logger->lines);

        $slots = new class implements ChatMessageMenuSlotProviderInterface {
            public function slots(ChatComposerScope $scope): iterable
            {
                foreach (range(1, 10) as $i) {
                    yield new ChatMenuSlot('s' . $i, 'S', 'bad icon', ChatMenuSlotTarget::Message, '/s');
                }
                yield new ChatMenuSlot('s1', 'Again', 'key', ChatMenuSlotTarget::Message, '/s');
            }
        };
        $failing = new class implements ChatMessageMenuSlotProviderInterface {
            public function slots(ChatComposerScope $scope): iterable
            {
                throw new RuntimeException('boom');
            }
        };
        $cards = new ChatCards([], [], [$failing, $slots]);
        self::assertTrue($cards->hasMenuSlots());
        $menu = $cards->menuSlots(self::composer());
        self::assertCount(ChatCards::MAX_SLOTS, $menu);
        self::assertSame(['key' => 's1', 'label' => 'S', 'icon' => 'key', 'target' => 'message', 'cardTypes' => [], 'ownOnly' => false, 'dialogUrl' => '/s'], $menu[0]);
    }

    public function testScopes(): void
    {
        $scope = new ChatCardScope('u1', 'c1', 'channel', 'acme', 'ctx', true);
        self::assertSame("u1\nc1\nctx\n1", $scope->key());
        self::assertSame("u1\nc1\n\n0", (new ChatCardScope('u1', 'c1', 'direct'))->key());
    }

    private static function scope(): ChatCardScope
    {
        return new ChatCardScope('u1', 'c1', 'direct');
    }

    private static function composer(): ChatComposerScope
    {
        return new ChatComposerScope('u1', 'c1', 'direct');
    }

    /**
     * @template T of object
     *
     * @param list<T> $services
     *
     * @return iterable<T>
     */
    private static function lazy(array $services): iterable
    {
        yield from new ArrayIterator($services);
    }

    /**
     * @param list<ChatComposerAction> $actions
     */
    private static function actions(array $actions): ChatComposerActionProviderInterface
    {
        return new class($actions) implements ChatComposerActionProviderInterface {
            /**
             * @param list<ChatComposerAction> $list
             */
            public function __construct(private readonly array $list)
            {
            }

            public function actions(ChatComposerScope $scope): iterable
            {
                return $this->list;
            }
        };
    }

    private static function provider(string $key): FlakyCardProvider
    {
        return new FlakyCardProvider($key);
    }
}
