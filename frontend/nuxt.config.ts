import tailwindcss from '@tailwindcss/vite'

export default defineNuxtConfig({
  compatibilityDate: '2024-11-01',
  devtools: { enabled: false },
  srcDir: 'src',
  css: ['~/assets/css/main.css'],

  vite: {
    plugins: [tailwindcss()],
  },

  runtimeConfig: {
    public: {
      apiUrl: process.env.NUXT_PUBLIC_API_URL ?? 'http://localhost:8000/api/v1',
      appUrl: process.env.NUXT_PUBLIC_APP_URL ?? 'http://localhost:3000',
    },
  },

  app: {
    head: {
      htmlAttrs: { lang: 'vi' },
      title: 'CaloEye — Trợ lý ăn uống cá nhân',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1, viewport-fit=cover' },
        { name: 'description', content: 'Ứng dụng nhận diện món ăn bằng AI, theo dõi calo và tư vấn dinh dưỡng cá nhân.' },
        { name: 'keywords', content: 'dinh dưỡng, calo, ăn uống, AI, sức khỏe, tập luyện' },

        /* PWA / theme */
        { name: 'theme-color', content: '#18A874' },
        { name: 'color-scheme', content: 'light' },
        { name: 'mobile-web-app-capable', content: 'yes' },

        /* Apple PWA */
        { name: 'apple-mobile-web-app-capable', content: 'yes' },
        { name: 'apple-mobile-web-app-status-bar-style', content: 'black-translucent' },
        { name: 'apple-mobile-web-app-title', content: 'CaloEye' },

        /* Open Graph */
        { property: 'og:type', content: 'website' },
        { property: 'og:title', content: 'CaloEye — Trợ lý ăn uống cá nhân' },
        { property: 'og:description', content: 'Nhận diện món ăn & theo dõi calo bằng AI' },
        { property: 'og:image', content: '/icons/icon-512x512.png' },
      ],
      link: [
        { rel: 'manifest', href: '/manifest.webmanifest' },

        /* Apple touch icons */
        { rel: 'apple-touch-icon', href: '/apple-touch-icon.png' },
        { rel: 'apple-touch-icon', sizes: '192x192', href: '/icons/icon-192x192.png' },
        { rel: 'apple-touch-icon', sizes: '512x512', href: '/icons/icon-512x512.png' },

        /* Favicon */
        { rel: 'icon', type: 'image/png', sizes: '192x192', href: '/icons/icon-192x192.png' },
        { rel: 'shortcut icon', href: '/favicon.ico' },
      ],
    },
  },

  modules: ['@vite-pwa/nuxt'],

  pwa: {
    registerType: 'autoUpdate',

    manifest: {
      name: 'CaloEye — Trợ lý ăn uống',
      short_name: 'CaloEye',
      description: 'Nhận diện món ăn & theo dõi calo bằng AI',
      lang: 'vi',
      start_url: '/',
      scope: '/',
      display: 'standalone',
      display_override: ['standalone', 'minimal-ui', 'browser'],
      orientation: 'portrait',
      background_color: '#ffffff',
      theme_color: '#18A874',
      categories: ['health', 'fitness', 'food'],
      icons: [
        {
          src: '/icons/icon-192x192.png',
          sizes: '192x192',
          type: 'image/png',
          purpose: 'maskable',
        },
        {
          src: '/icons/icon-192x192.png',
          sizes: '192x192',
          type: 'image/png',
          purpose: 'any',
        },
        {
          src: '/icons/icon-512x512.png',
          sizes: '512x512',
          type: 'image/png',
          purpose: 'maskable',
        },
        {
          src: '/icons/icon-512x512.png',
          sizes: '512x512',
          type: 'image/png',
          purpose: 'any',
        },
      ],
      shortcuts: [
        {
          name: 'Nhận diện món ăn',
          short_name: 'Chụp ảnh',
          description: 'Mở camera nhận diện món ăn ngay',
          url: '/scan',
          icons: [{ src: '/icons/icon-192x192.png', sizes: '192x192' }],
        },
        {
          name: 'Tư vấn AI',
          short_name: 'Chat AI',
          description: 'Hỏi AI về dinh dưỡng và sức khỏe',
          url: '/chat',
          icons: [{ src: '/icons/icon-192x192.png', sizes: '192x192' }],
        },
      ],
    },

    workbox: {
      navigateFallback: '/',
      navigateFallbackDenylist: [/^\/api\//],
      globPatterns: ['**/*.{js,css,html,png,svg,ico,woff,woff2}'],
      runtimeCaching: [
        {
          /* API responses: network-first with 5s timeout */
          urlPattern: /^https?:\/\/.*\/api\/.*/i,
          handler: 'NetworkFirst',
          options: {
            cacheName: 'api-cache',
            networkTimeoutSeconds: 5,
            expiration: { maxEntries: 100, maxAgeSeconds: 60 * 60 * 24 },
            cacheableResponse: { statuses: [0, 200] },
          },
        },
        {
          /* Images: cache-first */
          urlPattern: /\.(png|jpg|jpeg|webp|gif|svg|ico)$/i,
          handler: 'CacheFirst',
          options: {
            cacheName: 'image-cache',
            expiration: { maxEntries: 60, maxAgeSeconds: 60 * 60 * 24 * 30 },
            cacheableResponse: { statuses: [0, 200] },
          },
        },
        {
          /* Google Fonts & external assets: stale-while-revalidate */
          urlPattern: /^https:\/\/fonts\.(googleapis|gstatic)\.com\/.*/i,
          handler: 'StaleWhileRevalidate',
          options: {
            cacheName: 'font-cache',
            expiration: { maxEntries: 20, maxAgeSeconds: 60 * 60 * 24 * 365 },
            cacheableResponse: { statuses: [0, 200] },
          },
        },
      ],
    },

    client: {
      installPrompt: true,
      periodicSyncForUpdates: 3600,
    },

    devOptions: {
      enabled: true,
      type: 'module',
      navigateFallbackAllowlist: [/^\/$/],
    },
  },
})
