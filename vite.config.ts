import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  build: {
    // Output to the 'js' directory in the root of the Nextcloud app
    outDir: 'js',
    lib: {
      entry: 'src/main.tsx',
      name: 'TldrawApp',
      fileName: () => 'tldraw-main.js',
      formats: ['iife'], // IIFE for direct inclusion in the browser
    },
    rollupOptions: {
      // Ensure we don't code-split, so we get a single file
      output: {
        inlineDynamicImports: true,
        assetFileNames: (assetInfo) => {
          if (assetInfo.name === 'style.css') return 'style.css';
          return assetInfo.name;
        },
      },
    },
    emptyOutDir: true,
  },
  define: {
    'process.env.NODE_ENV': '"production"'
  }
})
