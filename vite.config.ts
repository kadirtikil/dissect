import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueJsx from '@vitejs/plugin-vue-jsx'
import vueDevTools from 'vite-plugin-vue-devtools'
import tailwindcss from '@tailwindcss/vite'
import { layoutPersistence, viewsPersistence } from './vite-plugin-persistence'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueJsx(),
    vueDevTools(),
    tailwindcss(),
    layoutPersistence(),
    viewsPersistence(),
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
        // The entry and the stylesheet keep stable names: the Blade view
        // references them directly and the package ships them committed, so
        // there is no manifest to read. The view busts them with a ?v= query
        // carrying a hash of the file.
        //
        // Chunks are hashed instead. The entry imports them by relative name
        // at runtime, so no query string can reach them — and they are served
        // `immutable`, so an unhashed name would keep a browser on the old
        // chunk after an update, against a new entry and new payloads.
        entryFileNames: 'dissect.js',
        chunkFileNames: 'dissect-[name]-[hash].js',
        assetFileNames: 'dissect.[ext]',
      },
    },
  },
})
