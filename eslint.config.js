import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import vueConfigPrettier from '@vue/eslint-config-prettier';
import { configureVueProject, defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import globals from 'globals';

configureVueProject({
    rootDir: 'resources/js',
});

export default defineConfigWithVueTs(
    js.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    vueTsConfigs.recommended,
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
            '@typescript-eslint/no-explicit-any': 'off',
        },
    },
    {
        // shadcn/vue generates components without explicit prop defaults by design
        // (props are meant to stay optional/nullable) — don't fight the generator.
        // Custom form-field wrappers under components/form follow the same convention.
        files: ['resources/js/components/ui/**/*.vue', 'resources/js/components/form/**/*.vue'],
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
            'resources/js/routes/**',
            'resources/js/actions/**',
            'resources/js/wayfinder/**',
        ],
    },
);
