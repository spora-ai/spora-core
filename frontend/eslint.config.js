import js from '@eslint/js'
import vuePlugin from 'eslint-plugin-vue'
import vueParser from 'vue-eslint-parser'
import tseslint from 'typescript-eslint'

export default tseslint.config(
  { ignores: ['dist/**', 'node_modules/**'] },
  js.configs.recommended,
  {
    files: ['**/*.vue'],
    plugins: {
      vue: vuePlugin,
    },
    languageOptions: {
      parser: vueParser,
      parserOptions: {
        parser: tseslint.parser,
        ecmaVersion: 2022,
        sourceType: 'module',
      },
    },
    rules: {
      ...vuePlugin.configs.recommended.rules,
      'no-undef': 'off',
      'vue/attributes-order': 'off',
      'vue/no-v-html': 'off',
    },
  },
  {
    rules: {
      'no-console': 'warn',
      'no-debugger': 'warn',
    },
  }
)