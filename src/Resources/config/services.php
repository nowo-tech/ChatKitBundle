<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Service definitions for NowoChatKitBundle. Repositories are registered only when Doctrine is available.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    if (class_exists(ServiceEntityRepository::class)) {
        $services->load('Nowo\\ChatKitBundle\\Repository\\', '../../Repository/')
            ->tag('doctrine.repository_service');
    }
};
