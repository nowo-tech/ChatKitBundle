# Upgrading

This guide lists the changes integrators must apply when upgrading between versions.

## 1.1.0 (2026-10-16)

No breaking change. If you set `--nowo-chat-bottom-offset` only to clear a footer, mark the footer with
`data-nowo-chat-avoid` instead (the dock then follows it when it scrolls into view); the Symfony toolbar is now
measured, so overrides of the old `body:has(.sf-toolbar-opened) .nowo-chat` rule can go.

## 1.0.0 (2026-10-16)

First release — nothing to upgrade from. Follow [INSTALLATION.md](INSTALLATION.md). The configuration shape
(`default_profile` + `profiles`), the contracts in `Nowo\ChatKitBundle\Contract`, the template `data-part` hooks and the
`--nowo-chat-*` tokens are the public API and stay stable for 1.x.
