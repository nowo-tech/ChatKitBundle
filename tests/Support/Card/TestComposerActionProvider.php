<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Card;

use Nowo\ChatKitBundle\Contract\ChatComposerActionProviderInterface;
use Nowo\ChatKitBundle\Model\ChatComposerAction;
use Nowo\ChatKitBundle\Model\ChatComposerScope;

/**
 * Two composer actions: "One-time secret" (`/secret`) and "Share a vault item" (its slash `task` collides with the
 * reference type and is dropped), plus a repeated key (the first one wins).
 */
final class TestComposerActionProvider implements ChatComposerActionProviderInterface
{
    public function actions(ChatComposerScope $scope): iterable
    {
        yield new ChatComposerAction('vault', 'Share a vault item', 'key', '/host/vault?x=1', 'task', 20);
        yield new ChatComposerAction('secret', 'One-time secret', 'lock', '/host/secret', 'secret', 10);
        yield new ChatComposerAction('secret', 'Duplicate', 'lock', '/host/other');
    }
}
