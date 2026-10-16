# Release

This checklist helps maintainers prepare and publish a release safely.

## Table of contents

- [Pre-release](#pre-release)
- [Security checklist (12.4.1)](#security-checklist-1241)
- [GitHub About (REQ-DOCS-018)](#github-about-req-docs-018)
- [Tag and publish](#tag-and-publish)
- [Post-release checks](#post-release-checks)
- [Coverage goals](#coverage-goals)
- [Release history](#release-history)

## Pre-release

Run the full release pipeline:

```bash
make release-check
```

Expected steps:

- `check-no-cursor-coauthor` (REQ-GIT-001)
- Asset build (`pnpm run build`)
- Composer validation and lock sync
- Code style checks
- Static analysis (Rector dry run + PHPStan)
- PHP and TypeScript test suites with coverage
- Demo verification (`demo/Makefile` `release-check`)

After creating the release commit and tag, run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001).

## Security checklist (12.4.1)

Before tagging, confirm each item in [SECURITY.md — Release security checklist](SECURITY.md#release-security-checklist-1241). Note confirmation in the release PR or tag message.

## GitHub About (REQ-DOCS-018)

GitHub repository **About** is not stored in git. Copy the values from [GITHUB.md](GITHUB.md). After creating or transferring `nowo-tech/ChatKitBundle`, set:

| Field | Value |
| --- | --- |
| **Description** | Floating LinkedIn-style chat for Symfony: direct messages, channels and a support inbox with realtime (Mercure), presence, read receipts, typing, Web Push, customizable Twig templates and theme tokens. |
| **Website** | https://packagist.org/packages/nowo-tech/chat-kit-bundle |
| **Topics** | `php`, `symfony`, `symfony-bundle`, `chat`, `mercure`, `realtime`, `web-push`, `frankenphp`, `typescript` |

## Tag and publish

1. Move `[Unreleased]` entries in `docs/CHANGELOG.md` to a new `## [X.Y.Z] - YYYY-MM-DD` section.
2. Update `docs/UPGRADING.md` if consumers must change code or configuration.
3. Create an **annotated** tag: `git tag -a vX.Y.Z -m "Release vX.Y.Z"`.
4. Push the tag: `git push origin vX.Y.Z`.
5. Confirm GitHub workflows `release.yml` and `sync-releases.yml` completed successfully.

## Post-release checks

- Verify Packagist metadata is updated.
- Confirm the GitHub release contains the tag message and changelog section.
- Validate installation in a clean Symfony app:

```bash
composer require nowo-tech/chat-kit-bundle
```

- Smoke-test the demo home page and the floating dock.

## Coverage goals

- **PHP**: **≥99%** line coverage (target **100%**; `make test-coverage`)
- **TypeScript**: **≥90%** of the minimum of statements / branches / functions / lines (`make test-ts`)

Update README **Tests and coverage** percentages after each release when coverage changes materially.

## Release history

| Version | Date | Notes |
| --- | --- | --- |
| 1.0.0 | 2026-10-16 | First release: floating chat (DMs, channels, support inbox), JSON API + full page, Mercure, Web Push, email digest, link previews, Spec Kit baseline, SEC-004 Pass (good). |
