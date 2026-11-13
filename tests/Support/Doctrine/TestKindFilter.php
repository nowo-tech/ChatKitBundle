<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Nowo\ChatKitBundle\Entity\ChatConversation;

/**
 * 1.27: an application SQL filter (registered, disabled by default) the private entity manager must mirror.
 */
final class TestKindFilter extends SQLFilter
{
    /**
     * @param ClassMetadata<object> $targetEntity
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->getName() !== ChatConversation::class || !$this->hasParameter('kinds')) {
            return '';
        }

        return $targetTableAlias . '.kind IN (' . $this->getParameterList('kinds') . ')';
    }
}
