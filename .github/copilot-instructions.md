## AI contribution guidelines — Chat Kit Bundle

Follow these rules when contributing PHP, TypeScript, Twig, and documentation to this repository.

---

## Project scope

- **Type:** Standalone Symfony bundle (`nowo-tech/chat-kit-bundle`), floating chat (direct messages, channels, support inbox).
- **PHP:** `>=8.3 <8.6` with `declare(strict_types=1);` in every PHP file.
- **Symfony:** **7.4**, **8.0**, and **8.1+** (`^7.4 || ^8.0` on `symfony/*` constraints). Symfony 8 requires PHP 8.4+.
- **Code:** `src/` (`Nowo\ChatKitBundle\`).
- **Frontend:** TypeScript + Vite + **pnpm** in `src/Resources/assets/`; published IIFE `src/Resources/public/js/nowo-chat.js`. The Symfony 8 demo compiles the same sources with **Pentatrion Vite** (`vite-plugin-symfony`).
- **Language:** PHPDoc, inline comments, and user-facing docs in **English** only.

---

## PHP standards

- PSR-12 + Symfony coding standards; run `make cs-check` before finishing.
- Use **strict comparisons** (`===`) and constructor injection.
- Prefer `final` classes; keep BC on public config keys, route names, and Twig template paths.
- Wire services in `src/Resources/config/services.php`.
- Preserve the DI extension alias: `nowo_chat_kit` (`default_profile` + `profiles`).
- Controllers use `#[Route]` attributes; route names are prefixed `nowo_chat_kit_`.

---

## Bundle-specific conventions

- Twig namespace: `@NowoChatKitBundle`; translation domain: `NowoChatKitBundle` (REQ-TWIG-002 / REQ-I18N-003); `nowo_chat_kit` is only the config root key.
- Asset package: `nowo_chat_kit` (`base_path: /bundles/nowochatkit`); load `asset('js/nowo-chat.js', 'nowo_chat_kit')`.
- CSS classes `.nowo-chat-*`, CSS custom properties `--nowo-chat-*`, JS / Stimulus identifiers `nowo-chat*`.
- Users and permissions come from host provider interfaces; never trust a user id sent by the browser.
- Never log message bodies; never render user content as HTML.
- No per-request state in services (FrankenPHP worker mode).

---

## Tests and quality

- PHPUnit target: **100% line coverage** on `src/` (gate ≥99%); justify exclusions in `docs/COVERAGE.md`.
- TypeScript tests via Vitest: `make test-ts` (≥90% lines).
- Full gate: `make release-check` (style, static analysis, PHP + TS coverage, demos).

---

## Documentation

- Keep `README.md` badges and the **Documentation** section aligned with `docs/`.
- Update `docs/CHANGELOG.md` and `docs/UPGRADING.md` for user-visible changes.
- Flex recipe lives in `.symfony/recipe/nowo-tech/chat-kit-bundle/1.0/`; document Flex steps in `docs/INSTALLATION.md`.

---

## Do not

- Commit secrets, `.env` files, VAPID private keys, or demo `var/` caches.
- Add Spanish PHPDoc or comments in `src/`.
- Publish a separate npm package for the browser assets.
- Touch unrelated files when fixing a focused issue.
