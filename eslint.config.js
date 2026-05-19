import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import vueConfigPrettier from '@vue/eslint-config-prettier';
import globals from 'globals';

export default [
    js.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    vueConfigPrettier,
    {
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
                ...globals.node,
            },
        },
    },
    {
        rules: {
            'vue/multi-word-component-names': 'off',
        },
    },
    {
        // shadcn/vue generates components without explicit prop defaults by design
        // (props are meant to stay optional/nullable) — don't fight the generator.
        files: ['resources/js/components/ui/**/*.vue'],
        rules: {
            'vue/require-default-prop': 'off',
        },
    },
    {
        ignores: [
            'vendor/**',
            'node_modules/**',
            'public/build/**',
            'storage/**',
            'bootstrap/cache/**',
            'old_project/**',
        ],
    },
];
