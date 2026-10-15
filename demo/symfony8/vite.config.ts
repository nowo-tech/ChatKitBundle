import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, type Plugin } from 'vite';
import symfonyPlugin from 'vite-plugin-symfony';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const dockerBundleAssets = '/var/chat-kit-bundle/src/Resources/assets';
const bundleAssets = fs.existsSync(dockerBundleAssets)
  ? dockerBundleAssets
  : path.resolve(__dirname, '../../src/Resources/assets');

/**
 * Copy `public/build/.vite/entrypoints.json` to `public/build/entrypoints.json`.
 * Pentatrion looks in both places; FrankenPHP workers can miss a nested `.vite/` file
 * after a rebuild if only the hashed assets changed.
 */
function copyEntrypointsJson(): Plugin {
  return {
    name: 'copy-entrypoints-json',
    closeBundle() {
      const src = path.resolve(__dirname, 'public/build/.vite/entrypoints.json');
      const dest = path.resolve(__dirname, 'public/build/entrypoints.json');
      if (fs.existsSync(src)) {
        fs.copyFileSync(src, dest);
      }
    },
  };
}

/**
 * Pentatrion Vite (`vite-plugin-symfony` + `pentatrion/vite-bundle`).
 * Twig: `vite_entry_link_tags` / `vite_entry_script_tags`.
 * The chat browser entry is compiled from the mounted bundle sources via `@bundle`.
 */
export default defineConfig({
  plugins: [symfonyPlugin(), copyEntrypointsJson()],
  resolve: {
    alias: {
      '@bundle': bundleAssets,
    },
    extensions: ['.ts', '.js'],
  },
  build: {
    emptyOutDir: true,
    rollupOptions: {
      input: {
        app: './assets/app.ts',
      },
    },
  },
  server: {
    fs: {
      allow: [bundleAssets, __dirname],
    },
  },
});
