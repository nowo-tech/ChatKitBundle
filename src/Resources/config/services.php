<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Service definitions for NowoChatKitBundle.
 *
 * Intentionally empty in the skeleton: chat services (conversation store, realtime
 * publisher, presence, push, Twig extension, controllers) are registered here as
 * they are migrated into the bundle.
 */
return static function (ContainerConfigurator $container): void {
    $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();
};
