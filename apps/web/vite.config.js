import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  base: '/app/',
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    proxy: {
      '/backend': 'http://localhost'
    }
  },
  build: {
    outDir: '../api/public/app',
    emptyOutDir: true
  }
})
