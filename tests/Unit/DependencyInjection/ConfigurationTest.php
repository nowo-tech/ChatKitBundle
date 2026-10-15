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
        $config = $this->process([]);

        self::assertSame('default', $config['default_profile']);
        self::assertSame(['default' => ['enabled' => true]], $config['profiles']);
    }

    public function testCustomProfilesAreKeptByName(): void
    {
        $config = $this->process([[
            'default_profile' => 'support',
            'profiles' => [
                'support' => ['enabled' => true],
                'member' => ['enabled' => false],
            ],
        ]]);

        self::assertSame('support', $config['default_profile']);
        self::assertSame(['enabled' => false], $config['profiles']['member']);
    }

    public function testUnknownDefaultProfileIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('default_profile must reference a key');

        $this->process([[
            'default_profile' => 'missing',
            'profiles' => ['default' => []],
        ]]);
    }

    /**
     * @param array<array<string, mixed>> $configs
     *
     * @return array{default_profile: string, profiles: array<string, array{enabled: bool}>}
     */
    private function process(array $configs): array
    {
        /** @var array{default_profile: string, profiles: array<string, array{enabled: bool}>} $processed */
        $processed = (new Processor())->processConfiguration(new Configuration(), $configs);

        return $processed;
    }
}
