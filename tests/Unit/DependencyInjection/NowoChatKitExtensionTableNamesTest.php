<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
use Nowo\ChatKitBundle\Doctrine\TablePrefixListener;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;

use function str_repeat;

/**
 * REQ-ORM-002: physical table-name parameters, prefix validation (X1) and the global `doctrine` options (P1).
 */
final class NowoChatKitExtensionTableNamesTest extends TestCase
{
    public function testDefaultTableNameParameters(): void
    {
        $container = $this->load([]);

        self::assertSame([
            'conversation'      => 'nowo_chat_conversation',
            'conversation_link' => 'nowo_chat_conversation_link',
            'message'           => 'nowo_chat_message',
            'message_link'      => 'nowo_chat_message_link',
            'message_mention'   => 'nowo_chat_message_mention',
            'participant'       => 'nowo_chat_participant',
            'quick_reply'       => 'nowo_chat_quick_reply',
            'reaction'          => 'nowo_chat_reaction',
            'user_settings'     => 'nowo_chat_user_settings',
        ], $container->getParameter('nowo_chat_kit.doctrine.tables'));
        self::assertSame('nowo_chat_message', $container->getParameter('nowo_chat_kit.doctrine.table.message'));
    }

    public function testRenamedTableNameParameters(): void
    {
        $chat = $this->load(['table_name_prefix' => 'chat_']);
        self::assertSame('chat_message', $chat->getParameter('nowo_chat_kit.doctrine.table.message'));
        self::assertSame('chat_user_settings', $chat->getParameter('nowo_chat_kit.doctrine.table.user_settings'));

        $both = $this->load(['table_name_prefix' => 'chat_', 'table_prefix' => 'app_']);
        self::assertSame('app_chat_participant', $both->getParameter('nowo_chat_kit.doctrine.table.participant'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPrefixes(): iterable
    {
        yield 'leading digit' => [['table_name_prefix' => '1chat_']];
        yield 'trailing newline' => [['table_name_prefix' => "chat_\n"]];
        yield 'uppercase table_prefix' => [['table_prefix' => 'App_']];
        yield 'leading underscore table_prefix' => [['table_prefix' => '_app']];
        yield 'too long' => [['table_name_prefix' => 'c' . str_repeat('x', 34), 'table_prefix' => 'app_']];
    }

    /**
     * @param array<string, mixed> $doctrine
     */
    #[DataProvider('invalidPrefixes')]
    public function testInvalidPrefixesAreRejectedAtCompileTime(array $doctrine): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load($doctrine);
    }

    public function testLongestAllowedPrefixFits(): void
    {
        // 25 characters left by uniq_nowo_chat_conversation_channel: 38 + 25 = 63.
        $container = $this->load(['table_name_prefix' => 'c' . str_repeat('x', 37)]);

        self::assertSame('c' . str_repeat('x', 37) . 'message', $container->getParameter('nowo_chat_kit.doctrine.table.message'));
    }

    public function testEnvPlaceholdersAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('do not support %env()%');

        $bag         = new EnvPlaceholderParameterBag();
        $container   = new ContainerBuilder($bag);
        $placeholder = $bag->get('env(CHAT_TABLE_PREFIX)');
        self::assertIsString($placeholder);

        // As MergeExtensionConfigurationPass does: the config tree recognises (and does not validate) placeholders.
        BaseNode::setPlaceholderUniquePrefix($bag->getEnvPlaceholderUniquePrefix());
        try {
            $this->load(['table_prefix' => $placeholder], $container);
        } finally {
            BaseNode::resetPlaceholders();
        }
    }

    public function testListenerRejectsResolvedEnvValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the limit is 63');

        new TablePrefixListener(str_repeat('a', 34), 'chat_');
    }

    public function testListenerRejectsMalformedPrefixes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TablePrefixListener('App_');
    }

    public function testListenerRejectsMalformedTableNamePrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid doctrine.table_name_prefix');

        new TablePrefixListener('', '9chat_');
    }

    public function testNonDefaultProfileMayRepeatTheDefaultDoctrineOptions(): void
    {
        $container = $this->loadConfigs([[
            'profiles' => [
                'default' => ['user_class' => TestUser::class, 'doctrine' => ['table_name_prefix' => 'chat_']],
                'support' => ['user_class' => TestUser::class, 'doctrine' => ['table_name_prefix' => 'chat_']],
                'plain'   => ['user_class' => TestUser::class],
            ],
        ]]);

        self::assertSame('chat_message', $container->getParameter('nowo_chat_kit.doctrine.table.message'));
    }

    public function testNonDefaultProfileWithOtherDoctrineOptionsIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_chat_kit.profiles.support.doctrine.table_name_prefix is ignored');

        $this->loadConfigs([[
            'profiles' => [
                'default' => ['user_class' => TestUser::class],
                'support' => ['user_class' => TestUser::class, 'doctrine' => ['table_name_prefix' => 'chat_']],
            ],
        ]]);
    }

    /**
     * @param array<string, mixed> $doctrine
     */
    private function load(array $doctrine, ?ContainerBuilder $container = null): ContainerBuilder
    {
        return $this->loadConfigs([['profiles' => ['default' => ['user_class' => TestUser::class, 'doctrine' => $doctrine]]]], $container);
    }

    /**
     * @param array<array<string, mixed>> $configs
     */
    private function loadConfigs(array $configs, ?ContainerBuilder $container = null): ContainerBuilder
    {
        $container ??= new ContainerBuilder();
        (new NowoChatKitExtension())->load($configs, $container);

        return $container;
    }
}
