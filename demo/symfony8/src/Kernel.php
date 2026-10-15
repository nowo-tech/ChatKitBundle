<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Demo application kernel for Chat Kit Bundle.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();

        // FrankenPHP worker reuses this kernel across requests. Symfony 8.1 only
        // binds the synthetic "kernel" service during container init, not on the
        // subsequent boot() that runs the services_resetter.
        $this->container?->set('kernel', $this);
    }
}
