<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Public UUID (v7) used in URLs, the API and realtime payloads; numeric ids never leave the server.
 */
trait PublicIdTrait
{
    #[ORM\Column(length: 36)]
    private string $uuid;

    public function getUuid(): string
    {
        return $this->uuid;
    }

    private function initUuid(): void
    {
        $this->uuid = Uuid::v7()->toRfc4122();
    }
}
