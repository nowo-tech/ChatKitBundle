<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface;

use function array_shift;

/**
 * Answers the scripted values in order (null once they run out) and counts the calls.
 */
final class ScriptedRetentionProvider implements RetentionPolicyProviderInterface
{
    public int $calls = 0;

    /**
     * @param list<int|null> $answers Answers in order
     */
    public function __construct(private array $answers)
    {
    }

    public function retentionDays(): ?int
    {
        ++$this->calls;

        return array_shift($this->answers);
    }
}
