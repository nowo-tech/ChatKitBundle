import eslint from '@eslint/js';
import tseslint from 'typescript-eslint';

export default tseslint.config(
  {
    ignores: [
      'src/Resources/assets/dist/**',
      'src/Resources/public/**',
      'vendor/**',
      'node_modules/**',
      'coverage/**',
    ],
  },
  eslint.configs.recommended,
  ...tseslint.configs.recommended,
  {
    files: ['src/Resources/assets/src/**/*.ts', 'src/Resources/assets/tests/**/*.ts'],
    rules: {
      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
      ],
    },
  },
  {
    files: ['src/Resources/assets/src/**/*.ts'],
    rules: {
      // Message HTML is sanitised on the server; every other string goes through textContent.
      'no-restricted-syntax': [
        'error',
        {
          selector: 'AssignmentExpression[left.property.name=/^(innerHTML|outerHTML)$/]',
          message: 'Use textContent / DOM nodes. Only the server-sanitised message HTML and the fixed SVG icons may use innerHTML (eslint-disable-next-line with a reason).',
        },
        {
          selector: "CallExpression[callee.property.name='insertAdjacentHTML']",
          message: 'Build DOM nodes instead of HTML strings.',
        },
      ],
      'no-eval': 'error',
      'no-implied-eval': 'error',
    },
  },
);
