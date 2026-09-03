import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath } from 'node:url'
export default defineConfig({
  root: fileURLToPath(new URL('.', import.meta.url)),
  base: './',
  plugins: [vue()],
  resolve: { alias: { '@inertiajs/vue3': fileURLToPath(new URL('./stub-inertia.js', import.meta.url)) } },
  build: { outDir: 'dist', emptyOutDir: true },
})
