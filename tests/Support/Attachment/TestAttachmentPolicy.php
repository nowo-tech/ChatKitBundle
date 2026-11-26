<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Attachment;

use Nowo\ChatKitBundle\Contract\ChatAttachmentPolicyInterface;
use Nowo\ChatKitBundle\Model\ChatComposerScope;
use RuntimeException;

/**
 * 2.7 (FR-010): the test attach policy. Deny unless the person's public id is in `$allowed`; `$failing` throws.
 * Records every scope it was asked about.
 */
final class TestAttachmentPolicy implements ChatAttachmentPolicyInterface
{
    /** @var array<string, true> */
    public array $allowed = [];

    public bool $failing = false;

    /** @var list<ChatComposerScope> */
    public array $asked = [];

    public function mayAttach(ChatComposerScope $scope): bool
    {
        $this->asked[] = $scope;
        if ($this->failing) {
            throw new RuntimeException('permission service down');
        }

        return isset($this->allowed[$scope->viewerPublicId]);
    }
}
