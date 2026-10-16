<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\DependencyInjection;

use Nowo\ChatKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

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
            'enabled'        => true,
            'user_class'     => null,
            'retention_days' => 365,
            'realtime'       => true,
            'push'           => false,
            'push_url'       => '/?chat={uuid}',
            'push_icon'      => null,
            'push_preview'   => false,
            'doctrine'       => ['table_prefix' => ''],
            'digest'         => ['enabled' => false, 'delay_minutes' => 15, 'interval_minutes' => 60],
            'page_layout'    => '@NowoChatKitBundle/page_layout.html.twig',
            'cache_pool'     => 'cache.app',
            'reactions'      => ['👍', '❤️', '😂', '😮', '😢', '🎉'],
            'icon_set'       => 'ux_icon',
            'icons'          => Configuration::DEFAULT_ICONS,
            'presence_ttl'   => 90,
            'limits'         => [
                'send_per_minute'       => 30,
                'actions_per_minute'    => 240,
                'typing_per_minute'     => 30,
                'reads_per_minute'      => 600,
                'buzz_interval_seconds' => 15,
                'buzz_per_minute'       => 10,
            ],
        ], $config['profiles']['default']);
    }

    public function testOmittedProfilesFollowTheDefaultProfileName(): void
    {
        $config = $this->process([['default_profile' => 'member']]);

        self::assertSame(['member'], array_keys($config['profiles']));
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

    public function testUnknownDefaultProfileIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('default_profile must reference a key');

        $this->process([[
            'default_profile' => 'missing',
            'profiles'        => ['default' => []],
        ]]);
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
