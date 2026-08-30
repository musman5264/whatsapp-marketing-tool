import js from '@eslint/js';
import reactPlugin from 'eslint-plugin-react';
import reactHooksPlugin from 'eslint-plugin-react-hooks';
import prettierConfig from 'eslint-config-prettier';

export default [
    js.configs.recommended,
    {
        files: ['resources/js/**/*.{js,jsx}'],
        plugins: {
            react: reactPlugin,
            'react-hooks': reactHooksPlugin,
        },
        languageOptions: {
            globals: {
                window:    'readonly',
                document:  'readonly',
                navigator: 'readonly',
                console:   'readonly',
                setTimeout: 'readonly',
                clearTimeout: 'readonly',
                requestAnimationFrame: 'readonly',
                Event:     'readonly',
                DOMParser: 'readonly',
                fetch:     'readonly',
                AbortController: 'readonly',
                localStorage: 'readonly',
                Intl:      'readonly',
                route:     'readonly',
                confirm:   'readonly',
                FormData:  'readonly',
                Blob:      'readonly',
                URL:       'readonly',
            },
            parserOptions: {
                ecmaVersion: 2022,
                sourceType:  'module',
                ecmaFeatures: { jsx: true },
            },
        },
        settings: {
            react: { version: 'detect' },
        },
        rules: {
            ...reactPlugin.configs.recommended.rules,
            ...reactHooksPlugin.configs.recommended.rules,
            'react/prop-types': 'off',
            'react/react-in-jsx-scope': 'off',
            'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
            'no-console': 'warn',
        },
    },
    prettierConfig,
];
