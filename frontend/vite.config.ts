import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
  plugins: [vue()],

  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },

  build: {
    outDir: '../public/dist',
    emptyOutDir: true,
  },

  server: {
    port: Number(process.env.VITE_PORT) || 5173,
    proxy: {
      '/api': {
        target: `http://localhost:${process.env.PHP_PORT || 8080}`,
        changeOrigin: true,
      },
    },
  },

  test: {
    environment: 'happy-dom',
    globals: true,
    include: ['tests/**/*.spec.{ts,tsx}'],
    exclude: ['tests/e2e/**'],
    setupFiles: ['tests/setup.ts'],
  },
})
