<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\DependencyInjection;

use Nowo\ChatKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Definition\PrototypedArrayNode;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConfigurationTest extends TestCase
{
    public function testDefaultsExposeDefaultProfile(): void
    {
        $config = $this->process([[]]);

        self::assertSame('default', $config['default_profile']);
        self::assertSame([
            'enabled'                   => true,
            'user_class'                => null,
            'retention_days'            => 365,
            'runtime'                   => [],
            'realtime'                  => true,
            'realtime_events'           => 'full',
            'push'                      => false,
            'push_url'                  => '/?chat={uuid}',
            'push_icon'                 => null,
            'push_preview'              => false,
            'doctrine'                  => ['table_name_prefix' => 'nowo_chat_', 'table_prefix' => '', 'user_reference' => 'relation', 'entity_manager' => 'default', 'private_entity_manager' => null],
            'digest'                    => ['enabled' => false, 'delay_minutes' => 15, 'interval_minutes' => 60],
            'web_ui'                    => ['layout_template' => null, 'assets' => true, 'channels_url' => null, 'message_context_menu' => null, 'page' => ['full_height' => false, 'open_recent' => false], 'dock' => ['sidebar_sections' => false, 'dialog_runtime' => false], 'templates' => ['page' => '@NowoChatKitBundle/page.html.twig']],
            'cache_pool'                => 'cache.app',
            'reactions'                 => ['👍', '❤️', '😂', '😮', '😢', '🎉'],
            'icon_set'                  => 'ux_icon',
            'icons'                     => Configuration::DEFAULT_ICONS,
            'presence_ttl'              => 90,
            'auto_reply_interval_hours' => 4,
            'max_pins'                  => 20,
            'auto_away_minutes'         => 10,
            'dock'                      => ['overlay' => 'hide', 'watch_aria_modal' => false],
            'links'                     => ['enabled' => true, 'max_pinned' => 20, 'allow_http' => false, 'channel_managers' => 'members', 'providers' => Configuration::DEFAULT_LINK_PROVIDERS],
            'client_api'                => ['principal_resolver' => null, 'context_policy' => null, 'token_revoker' => null, 'prefix' => '/api', 'context_header' => 'X-Chat-Context', 'limits' => ['per_ip_per_minute' => 300, 'per_token_per_minute' => 60]],
            'search'                    => ['enabled' => false, 'dock_entry' => true, 'conversation_entry' => true, 'fulltext' => true, 'postgresql_config' => 'simple', 'mysql_min_token_length' => 3, 'min_query_length' => 2, 'max_query_length' => 200, 'page_size' => 20],
            'search_kit'                => ['enabled' => false, 'priority' => 30, 'messages' => ['enabled' => true, 'max_candidates' => 100], 'channels' => ['enabled' => true, 'priority' => 35, 'max_candidates' => 100, 'direct_scan' => 200]],
            'message_menu'              => [
                'quick_reactions' => ['emojis' => Configuration::DEFAULT_QUICK_REACTIONS, 'picker' => true],
                'copy_text'       => true,
                'quote'           => ['enabled' => true, 'max_length' => 1000],
                'mark_unread'     => true,
                'save'            => ['enabled' => false, 'max_per_user' => 500, 'dock_entry' => true],
                'message_info'    => ['enabled' => false, 'read_times' => true, 'per_person_max_members' => 20, 'own_messages_only' => true],
                'forward'         => ['enabled' => false, 'max_targets' => 5, 'from_support' => false],
                'threads'         => ['enabled' => false],
                'edit_history'    => ['enabled' => false, 'max_revisions' => 20],
                'order'           => Configuration::MESSAGE_MENU_ITEMS,
            ],
            'status_presets' => Configuration::DEFAULT_STATUS_PRESETS,
            'support'        => [
                'ticket_prefix'      => 'SUP-',
                'ticket_digits'      => 6,
                'resume_after_days'  => 7,
                'close_after_days'   => 30,
                'emojis'             => true,
                'translation_domain' => 'NowoChatKitBundle',
                'topics'             => array_map(static fn (array $topic): array => $topic + ['label' => null, 'reply' => null, 'assignee' => null], Configuration::DEFAULT_SUPPORT_TOPICS),
            ],
            'limits' => [
                'send_per_minute'       => 30,
                'actions_per_minute'    => 240,
                'typing_per_minute'     => 30,
                'reads_per_minute'      => 600,
                'references_per_minute' => 120,
                'buzz_interval_seconds' => 15,
                'buzz_per_minute'       => 10,
                'searches_per_minute'   => 30,
                'mention_all_per_hour'  => 6,
            ],
        ], $config['profiles']['default']);
    }

    public function testSearchOptions(): void
    {
        $config = $this->process([['profiles' => ['default' => ['search' => ['enabled' => true, 'postgresql_config' => 'english', 'min_query_length' => 3, 'max_query_length' => 50, 'page_size' => 50], 'search_kit' => ['enabled' => true, 'priority' => 5]]]]]);
        self::assertSame('english', $config['profiles']['default']['search']['postgresql_config']);
        self::assertSame(['enabled' => true, 'priority' => 5, 'messages' => ['enabled' => true, 'max_candidates' => 100], 'channels' => ['enabled' => true, 'priority' => 35, 'max_candidates' => 100, 'direct_scan' => 200]], $config['profiles']['default']['search_kit']);
        // 1.38: the groups' own options.
        $config = $this->process([['profiles' => ['default' => ['search_kit' => ['messages' => ['enabled' => false, 'max_candidates' => 20], 'channels' => ['enabled' => false, 'priority' => 1, 'max_candidates' => 50, 'direct_scan' => 40]]]]]]);
        self::assertSame(['enabled' => false, 'max_candidates' => 20], $config['profiles']['default']['search_kit']['messages']);
        self::assertSame(['enabled' => false, 'priority' => 1, 'max_candidates' => 50, 'direct_scan' => 40], $config['profiles']['default']['search_kit']['channels']);
    }

    public function testClientApiDefaultsAndValidation(): void
    {
        $profile = $this->process([[]])['profiles']['default'];
        self::assertSame('full', $profile['realtime_events']);
        self::assertSame([
            'principal_resolver' => null,
            'context_policy'     => null,
            'token_revoker'      => null,
            'prefix'             => '/api',
            'context_header'     => 'X-Chat-Context',
            'limits'             => ['per_ip_per_minute' => 300, 'per_token_per_minute' => 60],
        ], $profile['client_api']);
        self::assertSame('', $this->process([['profiles' => ['default' => ['client_api' => ['prefix' => '']]]]])['profiles']['default']['client_api']['prefix']);

        foreach ([
            [['prefix' => '/api/'], 'client_api.prefix must be empty or a path'],
            [['prefix' => 'api'], 'client_api.prefix must be empty or a path'],
            [['context_header' => 'X Context'], 'client_api.context_header must be an HTTP header name'],
            [['context_policy' => 'app.contexts'], 'client_api.context_policy needs client_api.principal_resolver'],
            [['token_revoker' => 'app.revoker'], 'client_api.token_revoker needs client_api.principal_resolver'],
        ] as [$clientApi, $message]) {
            try {
                $this->process([['profiles' => ['default' => ['client_api' => $clientApi]]]]);
                self::fail('accepted ' . $message);
            } catch (InvalidConfigurationException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
        $this->expectException(InvalidConfigurationException::class);
        $this->process([['profiles' => ['default' => ['realtime_events' => 'bodies']]]]);
    }

    public function testSearchMinAboveMaxIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('search.min_query_length cannot be greater than search.max_query_length');
        $this->process([['profiles' => ['default' => ['search' => ['min_query_length' => 40, 'max_query_length' => 30]]]]]);
    }

    public function testSearchPostgresConfigMustBeAnIdentifier(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('search.postgresql_config takes the name of a text search configuration');
        $this->process([['profiles' => ['default' => ['search' => ['postgresql_config' => "simple'); drop table x; --"]]]]]);
    }

    public function testDockOverlayModesAndWatcher(): void
    {
        $config = $this->process([['profiles' => ['default' => ['dock' => ['overlay' => 'inert', 'watch_aria_modal' => true]]]]]);

        self::assertSame(['overlay' => 'inert', 'watch_aria_modal' => true], $config['profiles']['default']['dock']);
    }

    public function testUnknownDockOverlayModeIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['profiles' => ['default' => ['dock' => ['overlay' => 'visible']]]]]);
    }

    public function testOmittedProfilesFollowTheDefaultProfileName(): void
    {
        $config = $this->process([['default_profile' => 'member']]);

        self::assertSame(['member'], array_keys($config['profiles']));
    }

    public function testTableNamePrefixReplacesTheDefaultAndKeepsTheOptionalPrepend(): void
    {
        $config = $this->process([['profiles' => ['default' => ['doctrine' => ['table_name_prefix' => 'chat_', 'table_prefix' => 'app_']]]]]);

        self::assertSame(['table_name_prefix' => 'chat_', 'table_prefix' => 'app_', 'user_reference' => 'relation', 'entity_manager' => 'default', 'private_entity_manager' => null], $config['profiles']['default']['doctrine']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidDoctrinePrefixes(): iterable
    {
        yield 'empty table_name_prefix' => [['table_name_prefix' => '']];
        yield 'uppercase table_name_prefix' => [['table_name_prefix' => 'Chat_']];
        yield 'non-string table_name_prefix' => [['table_name_prefix' => 1]];
        yield 'dash in table_prefix' => [['table_prefix' => 'app-']];
        yield 'non-string table_prefix' => [['table_prefix' => 1]];
        yield 'trailing newline' => [['table_name_prefix' => "chat_\n"]];
    }

    public function testLinkProvidersReplaceTheDefaults(): void
    {
        $config = $this->process([['profiles' => ['default' => ['links' => ['channel_managers' => 'admins', 'allow_http' => true, 'providers' => [
            'whereby' => ['label' => 'Whereby', 'hosts' => ['whereby.com']],
        ]]]]]]);

        self::assertSame(['whereby' => ['label' => 'Whereby', 'hosts' => ['whereby.com'], 'icon' => null, 'fallback_icon' => 'video']], $config['profiles']['default']['links']['providers']);
        self::assertSame('admins', $config['profiles']['default']['links']['channel_managers']);
        self::assertTrue($config['profiles']['default']['links']['allow_http']);
    }

    public function testLinkProviderHostMustBeAHostname(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('lowercase hostname');

        $this->process([['profiles' => ['default' => ['links' => ['providers' => ['x' => ['label' => 'X', 'hosts' => ['https://x.com/']]]]]]]]);
    }

    public function testLinkProviderKeyOtherIsReserved(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"other"');

        $this->process([['profiles' => ['default' => ['links' => ['providers' => ['other' => ['label' => 'X', 'hosts' => ['x.com']]]]]]]]);
    }

    /**
     * @param array<string, mixed> $doctrine
     */
    #[DataProvider('invalidDoctrinePrefixes')]
    public function testInvalidDoctrinePrefixesAreRejected(array $doctrine): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['profiles' => ['default' => ['doctrine' => $doctrine]]]]);
    }

    public function testRetentionBelowThirtyDaysIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['profiles' => ['default' => ['retention_days' => 7]]]]);
    }

    public function testCustomProfilesAreKeptByName(): void
    {
        $config = $this->process([[
            'default_profile' => 'support',
            'profiles'        => [
                'support' => ['enabled' => true],
                'member'  => ['enabled' => false],
            ],
        ]]);

        self::assertSame('support', $config['default_profile']);
        self::assertFalse($config['profiles']['member']['enabled']);
        self::assertTrue($config['profiles']['support']['enabled']);
    }

    /** 2.0: `page_layout` (deprecated since 1.5) fails the build with a message naming its replacement, in any profile. */
    public function testPageLayoutIsRemoved(): void
    {
        $root = (new Configuration())->getConfigTreeBuilder()->buildTree();
        self::assertInstanceOf(ArrayNode::class, $root);
        $profiles = $root->getChildren()['profiles'];
        self::assertInstanceOf(PrototypedArrayNode::class, $profiles);
        $prototype = $profiles->getPrototype();
        self::assertInstanceOf(ArrayNode::class, $prototype);
        self::assertArrayNotHasKey('page_layout', $prototype->getChildren());
        $webUi = $prototype->getChildren()['web_ui'];
        self::assertInstanceOf(BaseNode::class, $webUi);
        self::assertFalse($webUi->isDeprecated());

        $config = $this->process([['profiles' => ['default' => ['web_ui' => ['layout_template' => 'layouts/chat.html.twig']]]]]);
        self::assertSame('layouts/chat.html.twig', $config['profiles']['default']['web_ui']['layout_template']);

        foreach ([['default' => ['page_layout' => 'base.html.twig']], ['default' => [], 'other' => ['page_layout' => null]]] as $profiles) {
            try {
                $this->process([['profiles' => $profiles]]);
                self::fail('page_layout must fail the build');
            } catch (InvalidConfigurationException $exception) {
                self::assertSame(Configuration::PAGE_LAYOUT_REMOVED, $exception->getMessage());
                self::assertStringContainsString('"web_ui.layout_template"', $exception->getMessage());
            }
        }
    }

    public function testStatusPresetsCanBeReplacedAndAreValidated(): void
    {
        $config = $this->process([['profiles' => ['default' => ['status_presets' => [
            'quick' => [['emoji' => '🚀', 'label' => 'Launching']],
            'more'  => [],
        ]]]]]);

        self::assertSame(['quick' => [['emoji' => '🚀', 'label' => 'Launching', 'clear_after' => 'never']], 'more' => []], $config['profiles']['default']['status_presets']);

        $this->expectException(InvalidConfigurationException::class);
        $this->process([['profiles' => ['default' => ['status_presets' => ['quick' => [['emoji' => '🚀', 'label' => 'x', 'clear_after' => 'fortnight']]]]]]]);
    }

    public function testUnknownDefaultProfileIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('default_profile must reference a key');

        $this->process([[
            'default_profile' => 'missing',
            'profiles'        => ['default' => []],
        ]]);
    }

    public function testMessageMenuValidation(): void
    {
        $config = $this->process([['profiles' => ['default' => ['message_menu' => ['order' => ['delete', 'reply'], 'quick_reactions' => ['emojis' => []]]]]]]);
        self::assertSame(['delete', 'reply'], $config['profiles']['default']['message_menu']['order']);
        self::assertSame([], $config['profiles']['default']['message_menu']['quick_reactions']['emojis']);
        foreach ([['order' => ['reply', 'reply']], ['order' => ['share']], ['quick_reactions' => ['emojis' => ['']]], ['quick_reactions' => ['emojis' => [str_repeat('x', 33)]]]] as $menu) {
            try {
                $this->process([['profiles' => ['default' => ['message_menu' => $menu]]]]);
                self::fail('Invalid message_menu accepted: ' . json_encode($menu));
            } catch (InvalidConfigurationException $exception) {
                self::assertStringContainsString('message_menu', $exception->getMessage());
            }
        }
    }

    /**
     * @param array<array<string, mixed>> $configs
     *
     * @return array{default_profile: string, profiles: array<string, array<string, mixed>>}
     */
    private function process(array $configs): array
    {
        /** @var array{default_profile: string, profiles: array<string, array<string, mixed>>} $processed */
        $processed = (new Processor())->processConfiguration(new Configuration(), $configs);

        return $processed;
    }
}
