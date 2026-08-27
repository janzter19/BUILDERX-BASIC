import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'

const javascriptChunkBudgetKb = 650

function vendorChunkName(id: string) {
  if (!id.includes('/node_modules/')) {
    return undefined
  }

  if (/\/node_modules\/(react|react-dom|scheduler)\//.test(id)) {
    return 'vendor-react'
  }

  if (id.includes('/node_modules/lucide-react/')) {
    return 'vendor-icons'
  }

  if (id.includes('/node_modules/@base-ui/')) {
    return 'vendor-base-ui'
  }

  if (/\/node_modules\/(@dnd-kit|@tanstack|recharts|d3-|decimal\.js-light|react-is)\//.test(id)) {
    return 'vendor-features'
  }

  return 'vendor'
}

// https://vite.dev/config/
export default defineConfig({
  base: './',
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  build: {
    manifest: true,
    chunkSizeWarningLimit: javascriptChunkBudgetKb,
    rolldownOptions: {
      output: {
        manualChunks: vendorChunkName,
      },
    },
  },
})
