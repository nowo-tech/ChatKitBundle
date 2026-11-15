<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Support;

use Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Works out the retention window of one purge run: the host's RetentionPolicyProviderInterface answer when it gives a
 * usable one, else the configured `retention_days`, never below MIN_DAYS nor above MAX_DAYS. Nothing is remembered: every call asks the
 * provider again (worker safe; the object only holds its readonly collaborators).
 */
final readonly class ChatRetention
{
    /** Smallest retention the bundle accepts, in days. */
    public const int MIN_DAYS = 30;

    /** Largest retention accepted, in days (100 years): a larger value would overflow date arithmetic and purge everything. */
    public const int MAX_DAYS = 36500;

    /**
     * @param int $configuredDays The `retention_days` configuration value
     * @param RetentionPolicyProviderInterface|null $provider The host's provider, when it has one
     * @param LoggerInterface $logger Receives a warning when a value is corrected (the values only, never secrets)
     */
    public function __construct(
        private int $configuredDays = 365,
        private ?RetentionPolicyProviderInterface $provider = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Retention in days for this run. Exceptions of the provider propagate (the run must stop, not fall back).
     * A configured value below 1 (while the provider gave nothing usable) throws: the run must not purge.
     *
     * @throws InvalidRetentionConfigurationException When the configured `retention_days` is 0 or negative
     *
     * @return int Days, between MIN_DAYS and MAX_DAYS
     */
    public function days(): int
    {
        $days   = $this->configuredDays;
        $source = 'configuration';
        $asked  = $this->provider?->retentionDays();
        if ($asked !== null) {
            if ($asked < 1) {
                $this->logger->warning('Chat retention provider returned an unusable value; the configured retention applies.', ['provider_days' => $asked, 'configured_days' => $this->configuredDays]);
            } else {
                $days   = $asked;
                $source = 'provider';
            }
        }
        if ($source === 'configuration' && $days < 1) {
            // 1.31.1: a configured value that is not a positive number is an operator error (an `%env(int:…)%` that coerced
            // to 0, a negative number): refuse instead of purging with the most aggressive window.
            throw new InvalidRetentionConfigurationException($days);
        }
        if ($days < self::MIN_DAYS) {
            $this->logger->warning('Chat retention below the minimum was raised.', ['requested_days' => $days, 'minimum_days' => self::MIN_DAYS, 'source' => $source]);

            return self::MIN_DAYS;
        }

        if ($days > self::MAX_DAYS) {
            $this->logger->warning('Chat retention above the maximum was lowered.', ['requested_days' => $days, 'maximum_days' => self::MAX_DAYS, 'source' => $source]);

            return self::MAX_DAYS;
        }

        return $days;
    }
}
