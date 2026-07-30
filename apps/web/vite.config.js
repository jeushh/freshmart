import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const apiTarget = (env.VITE_API_URL || 'http://127.0.0.1:8000').replace(/\/+$/, '')
  const apiProxy = {
    target: apiTarget,
    changeOrigin: true,
    secure: false
  }

  return {
    plugins: [vue()],
    base: './',
    server: {
      port: 5173,
      proxy: {
        '/api': apiProxy,
        '/sanctum': apiProxy,
        '/backend': 'http://localhost'
      }
    },
    build: {
      outDir: '../api/public/app',
      emptyOutDir: true
    }
  }
})
