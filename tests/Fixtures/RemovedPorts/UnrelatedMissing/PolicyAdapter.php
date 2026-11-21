<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Fixtures\RemovedPorts\UnrelatedMissing;

use Nowo\ChatKitBundle\Contract\ChatChannelProviderPolicyInterface;

/**
 * 2.0 W1-1 fixture (autodiscovered): an application class implementing an unrelated missing interface whose name only
 * starts like a removed one; it keeps Symfony's own lazy error and the build succeeds.
 */
final class PolicyAdapter implements ChatChannelProviderPolicyInterface
{
}
