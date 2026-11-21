<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * 2.0: a subclass of the single-table-inheritance host user root ({@see TestStiMember}).
 */
#[ORM\Entity]
class TestStiStaff extends TestStiMember
{
}
