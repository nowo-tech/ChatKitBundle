<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Model;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Model\ChatSidebarAction;
use Nowo\ChatKitBundle\Model\ChatSidebarItem;
use Nowo\ChatKitBundle\Model\ChatSidebarSection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 1.25: the sidebar value objects refuse what the host gets structurally wrong (key, label, target, dialog size).
 */
final class ChatSidebarModelTest extends TestCase
{
    public function testASectionKeepsItsRowsAndActions(): void
    {
        $item    = new ChatSidebarItem('general', 'general', 'channel', '0f8fad5b-d9cb-469f-a165-70867728950e', null, -3, -1);
        $action  = new ChatSidebarAction('create', 'Create channel', 'plus', null, '/rooms/new');
        $section = new ChatSidebarSection('channels', 'Channels', 5, false, true, (static function () use ($item): iterable {
            yield $item;
        })(), [$action]);

        self::assertSame([$item], $section->items);
        self::assertSame([$action], $section->actions);
        self::assertSame(5, $section->position);
        self::assertFalse($section->collapsible);
        self::assertTrue($section->collapsedByDefault);
        self::assertSame(0, $item->unread, 'never negative');
        self::assertSame(0, $item->mentions);
        self::assertSame('md', $action->dialogSize);
    }

    /**
     * @return iterable<string, array{callable(): object, string}>
     */
    public static function invalid(): iterable
    {
        yield 'section key' => [static fn (): object => new ChatSidebarSection('Bad Key', 'x'), 'section key'];
        yield 'section label' => [static fn (): object => new ChatSidebarSection('ok', '  '), 'needs a label'];
        yield 'item key' => [static fn (): object => new ChatSidebarItem('', 'x', 'channel', null, '/x'), 'item key'];
        yield 'item label' => [static fn (): object => new ChatSidebarItem('ok', '', 'channel', null, '/x'), 'needs a label'];
        yield 'item without target' => [static fn (): object => new ChatSidebarItem('ok', 'x'), 'exactly one'];
        yield 'item with both targets' => [static fn (): object => new ChatSidebarItem('ok', 'x', 'channel', '0f8fad5b-d9cb-469f-a165-70867728950e', '/x'), 'exactly one'];
        yield 'item uuid' => [static fn (): object => new ChatSidebarItem('ok', 'x', 'channel', 'not-a-uuid'), 'lowercase uuid'];
        yield 'action key' => [static fn (): object => new ChatSidebarAction('-x', 'x', null, '/x'), 'action key'];
        yield 'action label' => [static fn (): object => new ChatSidebarAction('ok', ' ', null, '/x'), 'needs a label'];
        yield 'action without url' => [static fn (): object => new ChatSidebarAction('ok', 'x'), 'url or a dialogUrl'];
        // @phpstan-ignore argument.type (a wrong row, on purpose: audit KJ-I12)
        yield 'item type' => [static fn (): object => new ChatSidebarSection('ok', 'x', 0, true, false, ['nope']), 'must be ' . ChatSidebarItem::class . ' instances, got string'];
        // @phpstan-ignore argument.type (a wrong action, on purpose)
        yield 'action type' => [static fn (): object => new ChatSidebarSection('ok', 'x', 0, true, false, [], [null]), 'must be ' . ChatSidebarAction::class . ' instances, got null'];
        yield 'action size' => [static fn (): object => new ChatSidebarAction('ok', 'x', null, '/x', null, 'lg'), 'dialogSize'];
    }

    /**
     * @param callable(): object $build
     */
    #[DataProvider('invalid')]
    public function testInvalidValuesAreRefused(callable $build, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $build();
    }
}
