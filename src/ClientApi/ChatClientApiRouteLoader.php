<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\ClientApi;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

use function assert;

/**
 * 2.4 (client API v1): the routes of the client API, imported once by the host (routing type
 * {@see self::TYPE}) and mounted at `client_api.prefix` (default `/api`), so the API answers at `<prefix>/chat/v1/…`
 * (D-20261110-04). The routes are the controller's attribute routes (REQ-SF-004). With no principal resolver configured
 * the API is off and the import yields no route at all (deny by default).
 *
 *     # config/routes/nowo_chat_kit_client_api.yaml
 *     nowo_chat_kit_client_api:
 *         resource: .
 *         type: nowo_chat_kit_client_api
 */
final class ChatClientApiRouteLoader extends Loader
{
    /** The routing type of the import. */
    public const string TYPE = 'nowo_chat_kit_client_api';

    /** The path segment and version of the API under the prefix. */
    public const string SEGMENT = '/chat/v1';

    /**
     * @param bool $enabled Whether the API is on (a principal resolver is configured)
     * @param string $prefix `client_api.prefix` (a path such as `/api`, or `''` for the root)
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly string $prefix,
    ) {
        parent::__construct();
    }

    /**
     * The client API routes (none while the API is off).
     *
     * @param mixed $resource Ignored (`.`)
     * @param string|null $type {@see self::TYPE}
     *
     * @return RouteCollection The routes
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        if (!$this->enabled) {
            return $routes;
        }
        $imported = $this->import(ChatClientApiController::class, 'attribute');
        assert($imported instanceof RouteCollection);
        // @igor-ignore - a new RouteCollection of this import (built while the router cache is built), not shared state.
        $imported->addPrefix($this->prefix);
        $routes->addCollection($imported);

        return $routes;
    }

    /**
     * Whether the resource is this loader's.
     *
     * @param mixed $resource The resource
     * @param string|null $type The routing type
     *
     * @return bool True for {@see self::TYPE}
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
