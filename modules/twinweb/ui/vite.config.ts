import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

const INLINE_ALL_ASSETS_LIMIT = 100 * 1024 * 1024
const SCOPED_SCROLLBAR_FIX = '.bizcity-twin-embed .tw-hide-scrollbar::-webkit-scrollbar{display:none}'

function repairGeneratedScrollbarSelector() {
  return {
    name: 'repair-generated-scrollbar-selector',
    generateBundle(_options: unknown, bundle: Record<string, { type: string; code?: string }>) {
      for (const asset of Object.values(bundle)) {
        if (asset.type !== 'chunk' || !asset.code) {
          continue
        }

        asset.code = asset.code.replace(
          '[scrollbar-width:none] [&::-webkit-scrollbar]:hidden',
          'tw-hide-scrollbar',
        ).replace(
          /\.bizcity-twin-embed \.[^{}"]*-webkit-scrollbar[^{}"]*::-webkit-scrollbar\{display:none\}/g,
          SCOPED_SCROLLBAR_FIX,
        )
      }
    },
  }
}

export default defineConfig({
  plugins: [react(), repairGeneratedScrollbarSelector()],
  // [2026-09-27] PHASE-0.80 doc 26 OB-3 — packages/zalo-connection-ui lives outside this app; use THIS app's React.
  resolve: { dedupe: ['react', 'react-dom'] },
  base: './',
  build: {
    manifest: true,
    outDir: 'dist',
    emptyOutDir: true,
    // [2026-07-15 Johnny Chu] PHASE-TWINWEB U3 — single-bundle output:
    // - disable chunk split (inlineDynamicImports)
    // - inline all fonts/assets (KaTeX fonts no longer emitted as separate files)
    // - keep one entry JS + one CSS file for manifest-based WP enqueue.
    cssCodeSplit: false,
    assetsInlineLimit: INLINE_ALL_ASSETS_LIMIT,
    chunkSizeWarningLimit: 10000,
    rollupOptions: {
      input: 'index.html',
      output: {
        inlineDynamicImports: true,
        entryFileNames: 'assets/index-[hash].js',
        chunkFileNames: 'assets/index-[hash].js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name?.endsWith('.css')) {
            return 'assets/index-[hash][extname]'
          }
          return 'assets/[name]-[hash][extname]'
        },
      },
    },
  },
  server: {
    port: 5181,
  },
})
