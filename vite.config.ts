import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueJsx from '@vitejs/plugin-vue-jsx'
import vueDevTools from 'vite-plugin-vue-devtools'
import tailwindcss from '@tailwindcss/vite'
import { layoutPersistence, viewsPersistence } from './vite-plugin-persistence'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), vueJsx(), vueDevTools(), tailwindcss(), layoutPersistence(), viewsPersistence()],
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

  experimental: {
    // The bundle is served from wherever the package mounts its assets
    // (/dissect/assets/ by default), so what a file loads, the stylesheet
    // its fonts and the entry its chunks, is named relative to that file,
    // not to the site's root.
    renderBuiltUrl(_filename, { hostType }) {
      return hostType === 'css' || hostType === 'js' ? { relative: true } : undefined
    },
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
        // there is no manifest to read. They are served `no-cache` with an
        // ETag, so a browser revalidates them on every load. (Not a ?v= query:
        // the chunks import the entry back as `./dissect.js`, and a second URL
        // for it would evaluate it a second time as a separate module.)
        //
        // Chunks are hashed instead, and served `immutable`. The entry imports
        // them by relative name, so a hash in the name is the only thing that
        // can move a browser off the old chunk after an update.
        entryFileNames: 'dissect.js',
        chunkFileNames: 'dissect-[name]-[hash].js',
        // The stylesheet is named by the view, like the entry. Anything else
        // it pulls in, which is the font files, is hashed like a chunk: a
        // fixed name would give every font file the same one.
        assetFileNames: (asset) =>
          asset.names.some((name) => name.endsWith('.css'))
            ? 'dissect.[ext]'
            : 'dissect-[name]-[hash][extname]',
      },
    },
  },
})
