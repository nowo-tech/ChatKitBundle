<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Closure;
use Nowo\ChatKitBundle\Contract\ChatSidebarSectionProviderInterface;
use Nowo\ChatKitBundle\Model\ChatSidebarSection;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Throwable;

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

    /** Thrown by sections() when set. */
    public ?Throwable $failure = null;

    /** @var (Closure(): iterable<mixed>)|null Builds the sections inside sections() when set (what a real provider does). */
    public ?Closure $build = null;

    public function sections(ChatUserInterface $viewer): iterable
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->viewers[] = $viewer->getChatDisplayName();
        if ($this->build !== null) {
            // @phpstan-ignore return.type (a broken provider, on purpose)
            return ($this->build)();
        }

        return $this->sections;
    }
}
