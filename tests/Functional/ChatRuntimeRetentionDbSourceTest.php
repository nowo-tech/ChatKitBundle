<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface;
use Nowo\ChatKitBundle\RuntimeSetting\RuntimeSettingRetentionPolicyProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * 1.32: `source: db` with an environment-variable id: the configured value is ignored, a missing setting gives the
 * bundle's default (365 days), and the setting is listed by the contract's commands.
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

    public function testAMissingSettingUsesTheBundleDefaultAndAStoredOneWins(): void
    {
        self::assertInstanceOf(RuntimeSettingRetentionPolicyProvider::class, $this->get(RetentionPolicyProviderInterface::class));
        $console = new Application($this->kernel);
        $console->setAutoExit(false);

        $output = new BufferedOutput();
        self::assertSame(0, $console->run(new ArrayInput(['command' => 'nowo:chat:purge']), $output));
        self::assertStringContainsString('older than 365 days', $output->fetch());

        self::assertSame(0, $console->run(new ArrayInput(['command' => 'nowo:runtime-setting:list']), $output));
        self::assertStringContainsString('CHAT_RETENTION_DAYS', $output->fetch());

        self::assertSame(0, $console->run(new ArrayInput(['command' => 'nowo:runtime-setting:set', 'id' => 'CHAT_RETENTION_DAYS', 'value' => '45']), $output));
        $output->fetch();
        self::assertSame(0, $console->run(new ArrayInput(['command' => 'nowo:chat:purge']), $output));
        self::assertStringContainsString('older than 45 days', $output->fetch());
    }
}
