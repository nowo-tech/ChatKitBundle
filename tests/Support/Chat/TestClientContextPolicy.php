<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Nowo\ChatKitBundle\Contract\ChatClientContextPolicyInterface;
use Nowo\ChatKitBundle\Model\ChatClientContext;
use Nowo\ChatKitBundle\Model\ChatClientConversationRef;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;

use function in_array;

/**
 * 2.4: the host's tenants of the client API tests. Everyone may use `personal`; members (set by the tests) may use
 * `org:acme`. The Acme channel belongs to `org:acme` only; direct and support conversations to every context, unless a
 * test hides one. Records what it was asked.
 */
final class TestClientContextPolicy implements ChatClientContextPolicyInterface
{
    /** @var array<string, true> public ids of the Acme members */
    public array $acmeMembers = [];

    /** @var list<string> conversation uuids no context includes */
    public array $hidden = [];

    /** @var list<ChatClientConversationRef> */
    public array $asked = [];

    public function contextsFor(ChatClientPrincipal $principal): array
    {
        $contexts = [new ChatClientContext('personal', "Personal\u{202E}")];
        if (isset($this->acmeMembers[$principal->user->getChatPublicId()])) {
            $contexts[] = new ChatClientContext('org:acme', 'Acme');
        }

        return $contexts;
    }

    public function allows(ChatClientPrincipal $principal, string $contextKey): bool
    {
        return $contextKey === 'personal' || ($contextKey === 'org:acme' && isset($this->acmeMembers[$principal->user->getChatPublicId()]));
    }

    public function includes(ChatClientPrincipal $principal, string $contextKey, ChatClientConversationRef $conversation): bool
    {
        $this->asked[] = $conversation;
        if (in_array($conversation->uuid, $this->hidden, true)) {
            return false;
        }

        return $conversation->kind !== 'channel' || ($contextKey === 'org:acme' && $conversation->channelKey === TestChannelProvider::KEY);
    }
}
