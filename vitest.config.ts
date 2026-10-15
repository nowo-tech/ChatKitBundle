import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['src/Resources/assets/tests/**/*.test.ts'],
    setupFiles: ['src/Resources/assets/tests/setup.ts'],
    restoreMocks: true,
    clearMocks: true,
    coverage: {
      provider: 'v8',
      reporter: ['text', 'text-summary', 'html'],
      reportsDirectory: './coverage-ts',
      include: ['src/Resources/assets/src/**/*.ts'],
      exclude: ['**/*.test.ts', '**/node_modules/**', '**/types/**', '**/types.ts', '**/iife.ts'],
      thresholds: {
        lines: 90,
        functions: 90,
        statements: 90,
        branches: 70,
      },
    },
  },
});
