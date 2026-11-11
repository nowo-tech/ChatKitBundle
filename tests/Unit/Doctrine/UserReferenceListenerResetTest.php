<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Doctrine;

use LogicException;
use Nowo\ChatKitBundle\Doctrine\UserReferenceListener;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use WeakMap;

/**
 * 1.26.3 (REQ-RUNTIME-002): reset() forgets which entities were resolved (it runs at every request boundary through
 * ChatRequestBoundaryListener, and on `kernel.reset`).
 */
final class UserReferenceListenerResetTest extends TestCase
{
    public function testResetForgetsTheResolvedEntities(): void
    {
        $listener = new UserReferenceListener(static fn () => throw new LogicException('not used'), 'REDACTED-TEST');
        $entity   = new stdClass();
        /** @var WeakMap<object, true> $resolved */
        $resolved          = (new ReflectionProperty(UserReferenceListener::class, 'resolved'))->getValue($listener);
        $resolved[$entity] = true;
        self::assertTrue($listener->isResolved($entity));

        $listener->reset();

        self::assertFalse($listener->isResolved($entity));
    }
}
