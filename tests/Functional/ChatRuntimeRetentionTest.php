<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriteContext;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\InMemory\InMemoryRuntimeSettingStore;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function str_repeat;

/**
 * 1.32: `runtime.retention_days` in a Symfony host with nowo-tech/runtime-setting-contract's bridge over its in-memory
 * reference store. One kernel kept alive for every command (FrankenPHP worker, kernel reset off): a value changed with
 * `nowo:runtime-setting:set` reaches the next purge without a restart; an invalid value fails the purge and deletes
 * nothing; an unset value falls back to the configured `retention_days`.
 */
final class ChatRuntimeRetentionTest extends DatabaseTestCase
{
    private Application $app;

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['retention_days' => 120, 'runtime' => ['retention_days' => 'chat.retention_days']]]];
    }

    protected function runtimeSettings(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Application($this->kernel);
        $this->app->setAutoExit(false);
    }

    public function testTheRetentionFollowsTheRuntimeSettingWithoutARestart(): void
    {
        $this->messagesAged([200, 100, 10]);

        [$code, $display] = $this->console('nowo:chat:purge');
        self::assertSame(0, $code, $display);
        self::assertStringContainsString('older than 120 days', $display, 'nothing stored: the configured retention_days');
        self::assertSame(2, $this->messageCount());

        [$code, $display] = $this->console('nowo:runtime-setting:set', ['id' => 'chat.retention_days', 'value' => '90']);
        self::assertSame(0, $code, $display);
        [$code, $display] = $this->console('nowo:chat:purge');
        self::assertSame(0, $code, $display);
        self::assertStringContainsString('older than 90 days', $display);
        self::assertSame(1, $this->messageCount());

        // The bundle's bounds refuse a write below the minimum: nothing is stored, the retention stays 90.
        self::assertSame(1, $this->console('nowo:runtime-setting:set', ['id' => 'chat.retention_days', 'value' => '5'])[0]);
        self::assertStringContainsString('older than 90 days', $this->console('nowo:chat:purge')[1]);

        self::assertSame(0, $this->console('nowo:runtime-setting:unset', ['id' => 'chat.retention_days'])[0]);
        self::assertStringContainsString('older than 120 days', $this->console('nowo:chat:purge')[1]);
    }

    public function testAnInvalidStoredValueFailsThePurgeAndDeletesNothing(): void
    {
        $this->messagesAged([400, 10]);
        // Written behind the updater's back (a hand-edited row): the resolver must refuse it on read.
        $this->store()->set($this->definition(), '7', new RuntimeSettingWriteContext('cli:test', new RuntimeValueFingerprinter(str_repeat('REDACTED-TEST-', 2))));

        [$code, $display] = $this->console('nowo:chat:purge');

        self::assertSame(1, $code);
        self::assertStringContainsString('chat.retention_days', $display);
        self::assertSame(2, $this->messageCount(), 'no fallback to the configured value, a default or "keep forever"');

        $this->store()->set($this->definition(), 'ninety', new RuntimeSettingWriteContext('cli:test', new RuntimeValueFingerprinter(str_repeat('REDACTED-TEST-', 2))));
        self::assertSame(1, $this->console('nowo:chat:purge')[0]);
        self::assertSame(2, $this->messageCount());
    }

    public function testAKeptAliveKernelNeverServesAPreviousValue(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            $days = $i % 2 === 0 ? 60 : 90;
            self::assertSame(0, $this->console('nowo:runtime-setting:set', ['id' => 'chat.retention_days', 'value' => (string) $days])[0]);
            [$code, $display] = $this->console('nowo:chat:purge');
            self::assertSame(0, $code, $display);
            self::assertStringContainsString('older than ' . $days . ' days', $display, 'run ' . $i);
        }
    }

    /**
     * @param array<string, string> $arguments
     *
     * @return array{int, string}
     */
    private function console(string $command, array $arguments = []): array
    {
        $output = new BufferedOutput();
        $code   = $this->app->run(new ArrayInput(['command' => $command, '--no-interaction' => true] + $arguments), $output);

        return [$code, $output->fetch()];
    }

    /**
     * @param list<int> $ages Ages in days
     */
    private function messagesAged(array $ages): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = ChatConversation::direct($ana, $luis);
        $this->em->persist($dm);
        foreach ($ages as $age) {
            $this->em->persist(new ChatMessage($dm, $luis, 'aged ' . $age, '<p>aged</p>', new DateTimeImmutable('-' . $age . ' days')));
        }
        $this->em->flush();
        $this->em->clear();
    }

    private function messageCount(): int
    {
        $this->em->clear();

        return $this->em->getRepository(ChatMessage::class)->count([]);
    }

    private function store(): InMemoryRuntimeSettingStore
    {
        return $this->get(InMemoryRuntimeSettingStore::class);
    }

    private function definition(): RuntimeSettingDefinition
    {
        return new RuntimeSettingDefinition('chat.retention_days', RuntimeSettingType::Int, RuntimeSettingSource::DbThenEnv, validator: static fn (int|string|float|bool $v): int|string|float|bool => $v);
    }
}
