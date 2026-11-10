<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Nowo\ChatKitBundle\Contract\ChatSidebarSectionProviderInterface;
use Nowo\ChatKitBundle\Model\ChatSidebarSection;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * 1.25: the sections a test sets (the tests disable the browser's kernel reboot), priority 10.
 */
#[AsTaggedItem(priority: 10)]
final class TestSidebarProvider implements ChatSidebarSectionProviderInterface
{
    /** @var list<ChatSidebarSection> */
    public array $sections = [];

    /** @var list<string> Display names of the viewers asked for, in order. */
    public array $viewers = [];

    public function sections(ChatUserInterface $viewer): iterable
    {
        $this->viewers[] = $viewer->getChatDisplayName();

        return $this->sections;
    }
}
