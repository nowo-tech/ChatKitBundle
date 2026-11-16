<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\RuntimeSetting;

use Closure;
use Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface;
use Nowo\ChatKitBundle\Support\RetentionUnavailableException;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingExceptionInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;

use function is_int;

/**
 * 1.32 (optional, needs nowo-tech/runtime-setting-contract ^1.0): the retention port answered by a runtime setting
 * (`runtime.retention_days`). The bundle's code only sees {@see RetentionPolicyProviderInterface}; the contract's types
 * stay in this adapter (REQ-ARCH-001 rule 3).
 *
 * Every call asks the resolver, which keeps one value per unit of work only (its memo is cleared at every request,
 * command and message boundary): a value changed with `nowo:runtime-setting:set` reaches a long-running worker within
 * the contract's `version_ttl`, with no restart (FrankenPHP worker mode, kernel reset off). This class holds no state.
 *
 * Fail closed: an invalid stored or configured value (outside 30..36500 days, not an integer), a setting without value
 * (`source: db` with nothing stored) or a source failure throws {@see RetentionUnavailableException}; the purge then deletes nothing.
 */
final readonly class RuntimeSettingRetentionPolicyProvider implements RetentionPolicyProviderInterface
{
    /**
     * @param RuntimeSettingResolver $resolver The contract's resolver (per-request memo, versioned cache)
     * @param Closure(): RuntimeSettingDefinition|RuntimeSettingDefinition $definition The registered definition, or a
     *                                                                                 closure building it on first use
     * @param string $setting The setting id, for the error message when the definition cannot even be built
     */
    public function __construct(
        private RuntimeSettingResolver $resolver,
        private RuntimeSettingDefinition|Closure $definition,
        private string $setting = 'chat.retention_days',
    ) {
    }

    /**
     * The retention in days from the runtime setting (stored, configured or default, per the setting's `source`).
     *
     * @throws RetentionUnavailableException When the value is invalid or cannot be read
     *
     * @return int Days (30..36500); never null: the runtime path never falls back to the configured value by itself
     */
    public function retentionDays(): int
    {
        $setting = $this->setting;
        try {
            $definition = $this->definition instanceof Closure ? ($this->definition)() : $this->definition;
            $setting    = $definition->idString();
            $days       = $this->resolver->get($definition);
        } catch (RuntimeSettingExceptionInterface $exception) {
            throw new RetentionUnavailableException($setting, $exception->getMessage(), $exception);
        }
        if ($days === null) {
            // Audit RN-L1: no value at all is a failure of this path, never "use the configured retention_days".
            throw new RetentionUnavailableException($setting, 'the setting has no value');
        }
        if (!is_int($days)) {
            // Only a misregistered definition (another type) can get here: refuse it, never guess.
            throw new RetentionUnavailableException($setting, 'the setting is not an integer');
        }

        return $days;
    }
}
