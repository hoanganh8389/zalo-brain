import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  base: './',
  build: {
    manifest: true,
    rollupOptions: {
      input: 'index.html',
      output: {
        manualChunks: {
          'vendor-react':     ['react', 'react-dom'],
          'vendor-graph':     ['react-force-graph-2d'],
          'vendor-query':     ['@tanstack/react-query', 'zustand'],
          'vendor-md':        ['react-markdown', 'remark-gfm'],
        },
      },
    },
    outDir: 'dist',
    emptyOutDir: true,
    chunkSizeWarningLimit: 600,
  },
  server: {
    port: 5180,
  },
})
