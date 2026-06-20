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
      workbox: {
        globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
        runtimeCaching: [
          {
            urlPattern: /^https:\/\/fonts\.(bunny|google)apis\.com\/.*/i,
            handler: 'CacheFirst',
            options: {
              cacheName: 'google-fonts-cache',
              expiration: { maxEntries: 10, maxAgeSeconds: 60 * 60 * 24 * 365 },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
          {
            urlPattern: ({ url }) => url.pathname.startsWith('/api/'),
            handler: 'NetworkFirst',
            options: {
              cacheName: 'api-cache',
              networkTimeoutSeconds: 5,
              expiration: { maxEntries: 100, maxAgeSeconds: 60 * 5 },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
        ],
      },
      devOptions: {
        enabled: false,
      },
    }),
  ],
  server: {
    watch: {
      ignored: ['**/storage/framework/views/**'],
    },
  },
})
