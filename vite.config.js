import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import AutoImport from 'unplugin-auto-import/vite'
import { VitePWA } from 'vite-plugin-pwa'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
    },
  },
  plugins: [
    laravel({
      input: ['resources/js/app.ts'],
      refresh: true,
    }),
    vue({
      template: {
        compilerOptions: {
          // treat NuxtLink as a custom element (resolved via global component alias)
        },
      },
    }),
    tailwindcss(),
    AutoImport({
      // Auto-import APIs from these packages
      imports: [
        'vue',
        'vue-router',
        'pinia',
        {
          'virtual:pwa-register/vue': ['useRegisterSW'],
        },
      ],
      // Auto-import from local dirs
      dirs: [
        'resources/js/composables/**',
        'resources/js/utils/**',
        'resources/js/stores/**',
      ],
      // Generate TS declaration file
      dts: 'resources/js/auto-imports.d.ts',
      // Needed for Vue template auto-import
      vueTemplate: true,
    }),
    VitePWA({
      registerType: 'autoUpdate',
      strategies: 'injectManifest',
      srcDir: 'resources/js',
      filename: 'sw.ts',
      scope: '/',
      includeAssets: ['favicon.ico', 'logo/caloreye_icon*.png'],
      manifest: {
        name: 'CaloEye – AI Nutrition Tracker',
        short_name: 'CaloEye',
        description: 'Track calories and get AI-powered nutrition advice',
        theme_color: '#18A874',
        background_color: '#ffffff',
        display: 'standalone',
        orientation: 'portrait',
        scope: '/',
        start_url: '/',
        // required for FCM on Android Chrome
        gcm_sender_id: process.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
        icons: [
          {
            src: '/logo/caloreye_icon_192.png',
            sizes: '192x192',
            type: 'image/png',
          },
          {
            src: '/logo/caloreye_icon_512.png',
            sizes: '512x512',
            type: 'image/png',
          },
          {
            src: '/logo/caloreye_icon_1024.png',
            sizes: '1024x1024',
            type: 'image/png',
            purpose: 'any maskable',
          },
        ],
      },
      injectManifest: {
        globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
      },
      devOptions: {
        enabled: true,
        type: 'module',
      },
    }),
  ],
  server: {
    watch: {
      ignored: ['**/storage/framework/views/**'],
    },
  },
})
