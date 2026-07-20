import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueJsx from '@vitejs/plugin-vue-jsx'
import vueDevTools from 'vite-plugin-vue-devtools'
import tailwindcss from '@tailwindcss/vite'
import { layoutPersistence } from './vite-plugin-layout'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueJsx(),
    vueDevTools(),
    tailwindcss(),
    layoutPersistence(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
    },
  },

  server: {
    // The package's Blade view can load modules from this dev server (see
    // config('dissect.dev_server')), which is a cross-origin request:
    // Laravel serves the page, Vite serves the scripts.
    cors: true,
  },

  build: {
    outDir: 'dist',
    emptyOutDir: true,
    // public/ holds dev fixtures — a real application's schema.json and a
    // layout.json — which must never be copied into the shipped package.
    // They stay served by the dev server; they just are not built.
    copyPublicDir: false,
    rollupOptions: {
      output: {
        // Stable, unhashed names: the Blade view references these directly and
        // the package ships them committed, so there is no manifest to read.
        // Cache-busting is done with a ?v= query carrying the package version.
        entryFileNames: 'dissect.js',
        chunkFileNames: 'dissect-[name].js',
        assetFileNames: 'dissect.[ext]',
      },
    },
  },
})
