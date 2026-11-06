# Browser assets

TypeScript sources for the Nowo Chat Kit floating dock. The built IIFE lives in `src/Resources/public/js/nowo-chat.js` and is published by `assets:install` under the `nowo_chat_kit` asset package.

Applications with their own bundler import `src/index` (the dock) or `src/public` (preferences and sounds, without the dock); the other files are internal. See `docs/USAGE.md`, "Front end: IIFE or your bundler".

This is **not** a standalone npm package. From the repository root:

```bash
pnpm install
pnpm test
pnpm build
```
