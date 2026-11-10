<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Nowo\ChatKitBundle\Contract\ChatSidebarSectionProviderInterface;
use Nowo\ChatKitBundle\Model\ChatSidebarSection;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.25: a second provider (default priority 0, so it runs after TestSidebarProvider), as a generator.
 */
final class TestSecondSidebarProvider implements ChatSidebarSectionProviderInterface
{
    /** @var list<ChatSidebarSection> */
    public array $sections = [];

    public function sections(ChatUserInterface $viewer): iterable
    {
        yield from $this->sections;
    }
}
