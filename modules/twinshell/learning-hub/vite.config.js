import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';
/**
 * Build outputs to ../assets/learning-hub/{index.js,index.css}
 * (path consumed by Wave E mount in class-twin-shell-page.php).
 *
 * No code-splitting — single deterministic bundle, easier WP enqueue + cache busting.
 */
export default defineConfig({
    plugins: [react()],
    build: {
        outDir: resolve(__dirname, '../assets/learning-hub'),
        emptyOutDir: true,
        sourcemap: false,
        cssCodeSplit: false,
        target: 'es2019',
        rollupOptions: {
            input: resolve(__dirname, 'src/main.tsx'),
            output: {
                entryFileNames: 'index.js',
                chunkFileNames: 'chunks/[name]-[hash].js',
                assetFileNames: (info) => info.name && info.name.endsWith('.css') ? 'index.css' : 'assets/[name]-[hash][extname]',
                manualChunks: undefined,
            },
        },
    },
    server: {
        port: 5180,
    },
});
