<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\RuntimeSetting\RuntimeSettingRetentionPolicyProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * 1.32: `source: db` with an environment-variable id: the configured value is ignored, a missing setting fails the
 * purge without deleting anything (audit RN-M1: never a default shorter than the host's retention), and the setting is
 * listed by the contract's commands.
 */
final class ChatRuntimeRetentionDbSourceTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['retention_days' => 120, 'runtime' => ['retention_days' => ['source' => 'db', 'key' => 'CHAT_RETENTION_DAYS']]]]];
    }

    protected function runtimeSettings(): bool
    {
        return true;
    }

    public function testAMissingSettingFailsThePurgeAndAStoredOneWins(): void
    {
        self::assertInstanceOf(RuntimeSettingRetentionPolicyProvider::class, $this->get(RetentionPolicyProviderInterface::class));
        $console = new Application($this->kernel);
        $console->setAutoExit(false);

        $this->messageAged(400);
        $output = new BufferedOutput();
        self::assertSame(1, $console->run(new ArrayInput(['command' => 'nowo:chat:purge']), $output));
        self::assertStringContainsString('CHAT_RETENTION_DAYS', $output->fetch());
        self::assertSame(1, $this->messageCount(), 'nothing deleted');

        self::assertSame(0, $console->run(new ArrayInput(['command' => 'nowo:runtime-setting:list']), $output));
        self::assertStringContainsString('CHAT_RETENTION_DAYS', $output->fetch());

        self::assertSame(0, $console->run(new ArrayInput(['command' => 'nowo:runtime-setting:set', 'id' => 'CHAT_RETENTION_DAYS', 'value' => '45']), $output));
        $output->fetch();
        self::assertSame(0, $console->run(new ArrayInput(['command' => 'nowo:chat:purge']), $output));
        self::assertStringContainsString('older than 45 days', $output->fetch());
        self::assertSame(0, $this->messageCount());

        // Unset again: back to failing, never to a default.
        self::assertSame(0, $console->run(new ArrayInput(['command' => 'nowo:runtime-setting:unset', 'id' => 'CHAT_RETENTION_DAYS']), $output));
        $output->fetch();
        self::assertSame(1, $console->run(new ArrayInput(['command' => 'nowo:chat:purge']), $output));
    }

    private function messageAged(int $days): void
    {
        $dm = ChatConversation::direct($this->user('Ana'), $luis = $this->user('Luis'));
        $this->em->persist($dm);
        $this->em->persist(new ChatMessage($dm, $luis, 'aged', '<p>aged</p>', new DateTimeImmutable('-' . $days . ' days')));
        $this->em->flush();
        $this->em->clear();
    }

    private function messageCount(): int
    {
        $this->em->clear();

        return $this->em->getRepository(ChatMessage::class)->count([]);
    }
}
