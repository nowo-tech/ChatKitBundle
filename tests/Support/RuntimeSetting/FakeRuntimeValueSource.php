<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\RuntimeSetting;

use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingSourceFailure;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueSourceInterface;
use Nowo\RuntimeSettingContract\Contract\StoredRuntimeValue;

/**
 * 1.32: a host's own runtime-setting source (DDD host, no InstanceSettings, no Symfony bridge): raw stored strings by
 * id, a version bumped on every change, and a switch that makes every read fail (database down). Counts the reads.
 */
final class FakeRuntimeValueSource implements RuntimeValueSourceInterface
{
    public bool $failing = false;

    public int $reads = 0;

    /** @var array<string, string> raw stored values by id */
    private array $values = [];

    private int $version = 0;

    /**
     * Stores a raw value (no validation: the resolver must refuse what the bundle's validator refuses), or removes it.
     */
    public function store(string $id, ?string $raw): void
    {
        if ($raw === null) {
            unset($this->values[$id]);
        } else {
            $this->values[$id] = $raw;
        }
        ++$this->version;
    }

    public function namespaceVersion(string $namespace): string
    {
        if ($this->failing) {
            throw RuntimeSettingSourceFailure::because('The fake source is down.');
        }

        return 'v' . $this->version;
    }

    public function read(RuntimeSettingId $id): ?StoredRuntimeValue
    {
        ++$this->reads;
        if ($this->failing) {
            throw RuntimeSettingSourceFailure::because('The fake source is down.');
        }
        $raw = $this->values[$id->toString()] ?? null;

        return $raw === null ? null : new StoredRuntimeValue($raw);
    }
}
