<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Query;

use Nowo\ChatKitBundle\Contract\ChatAccessInterface;
use Nowo\ChatKitBundle\Contract\ChatAccessPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatMessagingInterface;
use Nowo\ChatKitBundle\Contract\ChatQueryInterface;
use Nowo\ChatKitBundle\Contract\ChatSupportInboxInterface;
use Nowo\ChatKitBundle\Support\ChatSupportTickets;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestAccessPolicy;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function assert;

/**
 * 2.2 (FrankenPHP worker mode, kernel reset false; REQ-RUNTIME-003): the host query ports keep nothing between calls. One
 * kernel serves request after request without a services reset; when the application turns a user unreachable between
 * two requests (or between two calls outside a request: console, Messenger), the next answer follows at once, in both
 * directions, and the answers of the request before never carry over.
 */
final class ChatQueryPortsWorkerTest extends DatabaseTestCase
{
    public function testAnswersFollowThePolicyFromOneRequestToTheNext(): void
    {
        $ana       = $this->user('Ana');
        $luis      = $this->user('Luis');
        $agent     = $this->user('Agent Zoe');
        $messaging = $this->get(ChatMessagingInterface::class);
        $direct    = $messaging->openDirect($ana, $luis);
        $messaging->send($ana, $direct->uuid, 'hello');
        $tickets = $this->get(ChatSupportTickets::class);
        $tickets->chooseTopic($ana, $tickets->open($ana), 'billing');
        $access = $this->get(ChatAccessInterface::class);
        $query  = $this->get(ChatQueryInterface::class);
        $inbox  = $this->get(ChatSupportInboxInterface::class);
        $policy = $this->get(ChatAccessPolicyInterface::class);
        assert($policy instanceof TestAccessPolicy);
        $requests = $this->get(RequestStack::class);

        for ($round = 0; $round < 6; ++$round) {
            $blocked = $round % 2 === 1;
            if ($blocked) {
                $policy->unreachable[$luis->getChatPublicId()]  = true;
                $policy->unreachable[$agent->getChatPublicId()] = true;
            } else {
                $policy->unreachable = [];
            }
            // A worker request (answers memoized on it), then the same outside any request.
            $requests->push(Request::create('/round/' . $round));
            foreach ([0, 1] as $pass) {
                self::assertSame(!$blocked, $access->canView($luis->getChatPublicId(), $direct->uuid), 'round ' . $round . ' pass ' . $pass);
                self::assertSame($blocked ? [] : [$direct->uuid], $access->viewableConversationUuids($luis, [$direct->uuid]));
                self::assertCount($blocked ? 0 : 1, $query->conversationsFor($luis));
                self::assertCount($blocked ? 0 : 1, $query->messages($luis, $direct->uuid));
                self::assertSame($blocked ? 0 : 1, $inbox->countOpen($agent));
                self::assertCount($blocked ? 0 : 1, $inbox->tickets($agent->getChatPublicId()));
                if ($pass === 0) {
                    $requests->pop();
                }
            }
        }
    }
}
