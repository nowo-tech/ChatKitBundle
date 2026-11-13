<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * 1.27 (REQ-ARCH-001 rule 3): the page request as the framework-free port twins see it (ChatChannelSourceInterface,
 * ChatRealtimeTransportInterface) instead of the HttpFoundation `Request`: the method, the URL parts, the route and its
 * scalar parameters, the scalar query parameters and the locale. Never a header, a cookie, the session or the body, so
 * a host may log it. Built per call by the bundle and never stored (FrankenPHP worker mode, kernel reset false).
 */
final readonly class ChatRequestContext
{
    /** @var array<string, string|int|float|bool|null> */
    public array $routeParams;

    /** @var array<string, string> */
    public array $query;

    /**
     * @param string $method HTTP method (upper case)
     * @param string $scheme `http` or `https`
     * @param string $host Host name, without the port
     * @param int $port Port the request came in on
     * @param string $basePath Path the application is mounted on (`''` at the root)
     * @param string $path Path inside the application (the path info, starting with `/`)
     * @param string|null $route Matched route name; null when none
     * @param array<array-key, mixed> $routeParams Route parameters; only string keys with scalar or null values are kept
     * @param array<array-key, mixed> $query Query parameters; only string keys with string values are kept (no arrays)
     * @param string $locale Locale of the request
     */
    public function __construct(
        public string $method,
        public string $scheme,
        public string $host,
        public int $port,
        public string $basePath,
        public string $path,
        public ?string $route = null,
        array $routeParams = [],
        array $query = [],
        public string $locale = 'en',
    ) {
        $params = [];
        foreach ($routeParams as $name => $value) {
            if (is_string($name) && ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value))) {
                $params[$name] = $value;
            }
        }
        $this->routeParams = $params;
        $kept              = [];
        foreach ($query as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $kept[$name] = $value;
            }
        }
        $this->query = $kept;
    }

    /**
     * Scheme, host and (non-default) port, as `Request::getSchemeAndHttpHost()` builds them.
     *
     * @return string E.g. `https://example.com` or `http://localhost:8080`
     */
    public function origin(): string
    {
        $default = ($this->scheme === 'https' && $this->port === 443) || ($this->scheme === 'http' && $this->port === 80);

        return $this->scheme . '://' . $this->host . ($default ? '' : ':' . $this->port);
    }

    /**
     * A route parameter.
     *
     * @param string $name Parameter name
     *
     * @return string|int|float|bool|null The value; null when absent
     */
    public function routeParam(string $name): string|int|float|bool|null
    {
        return $this->routeParams[$name] ?? null;
    }
}
