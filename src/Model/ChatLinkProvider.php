<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use function in_array;
use function str_ends_with;

/**
 * 1.19: a known link provider (Microsoft Teams, Google Meet, Zoom …) from the `links.providers` option. Links whose
 * hostname is one of `hosts`, or a subdomain of one, show its icon and label. Detection only looks at the hostname.
 */
final readonly class ChatLinkProvider
{
    /**
     * Builds a provider.
     *
     * @param string $key Stable key (`teams`, `meet` …), also the `provider` filter value of the links panel
     * @param string $label Display label (a brand name, not translated)
     * @param list<string> $hosts Lowercase hostnames; subdomains match too
     * @param string $icon Dock icon name (`provider_<key>`, rendered from the configured UX icon)
     * @param string $fallbackIcon Built-in icon of the dock used when the application has no SVG for `$icon`
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $hosts,
        public string $icon,
        public string $fallbackIcon = 'video',
    ) {
    }

    /**
     * Whether the hostname belongs to the provider (equal to a configured host, or a subdomain of it).
     *
     * @param string $host Lowercase hostname
     *
     * @return bool True on a match
     */
    public function matches(string $host): bool
    {
        if (in_array($host, $this->hosts, true)) {
            return true;
        }
        foreach ($this->hosts as $known) {
            if (str_ends_with($host, '.' . $known)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The provider as the JSON API and the dock configuration show it.
     *
     * @return array{key: string, label: string, icon: string, fallbackIcon: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'icon' => $this->icon, 'fallbackIcon' => $this->fallbackIcon];
    }
}
