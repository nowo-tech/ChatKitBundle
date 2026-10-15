import { resolve } from 'node:path';
import { defineConfig } from 'vite';

/**
 * Library IIFE for `assets:install` (REQ-ASSETS-004).
 * Output: `src/Resources/public/js/nowo-chat.js`, served through the `nowo_chat_kit`
 * asset package (`asset('js/nowo-chat.js', 'nowo_chat_kit')`).
 */
export default defineConfig({
  build: {
    outDir: 'src/Resources/public/js',
    emptyOutDir: false,
    lib: {
      entry: resolve(__dirname, 'src/Resources/assets/src/index.ts'),
      name: 'NowoChat',
      formats: ['iife'],
      fileName: () => 'nowo-chat.js',
    },
    rollupOptions: {
      output: {
        inlineDynamicImports: true,
      },
    },
    minify: true,
    sourcemap: false,
    target: 'es2022',
  },
});
