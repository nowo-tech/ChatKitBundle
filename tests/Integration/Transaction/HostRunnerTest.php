<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Transaction;

use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Support\ChatSupportTickets;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Transaction\HostEntityManagerRunner;
use Nowo\TransactionContract\TransactionRunnerInterface;

/**
 * 1.30 (Beacon spec 239, audits QD-M1 / QD-M3, ARCH-001): an application that binds TransactionRunnerInterface to its
 * own runner (a DDD host; DoctrineDeadlockRetryBundle's adapter) keeps it for its own code, and ChatKit never runs a
 * chat write through it: such a runner drives the application's manager.
 */
final class HostRunnerTest extends DatabaseTestCase
{
    protected function hostTransactionRunner(): bool
    {
        return true;
    }

    public function testTheApplicationsRunnerIsNeverUsedForAChatWrite(): void
    {
        $host = $this->get(HostEntityManagerRunner::class);
        self::assertSame($host, $this->get(TransactionRunnerInterface::class), 'the application\'s binding is left alone');

        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $dm   = $chat->openDirect($ana, $this->user('Luis'));
        $chat->send($ana, $dm, 'hi');
        $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $tickets = $this->get(ChatSupportTickets::class);
        $tickets->chooseTopic($ana, $tickets->open($ana), 'billing');

        self::assertSame(0, $host->runs);
    }
}
