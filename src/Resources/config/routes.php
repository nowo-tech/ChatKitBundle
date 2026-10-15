<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * Thin loader for the bundle's attribute routes (REQ-SF-004). Import it with a prefix, e.g.:
 *
 *     nowo_chat_kit:
 *         resource: '@NowoChatKitBundle/Resources/config/routes.php'
 *         prefix: /chat
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Controller/', 'attribute');
};
