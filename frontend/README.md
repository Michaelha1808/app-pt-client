# PT Client Frontend

Nuxt 3 + Vue frontend for the PT Client PWA.

## Getting Started

Install dependencies and run the development server:

```bash
npm run dev
```

Open [http://localhost:3000](http://localhost:3000) with your browser.

Set the API URL with:

```bash
NUXT_PUBLIC_API_URL=http://localhost:8000/api/v1
```

## Project Structure

- `src/app.vue` — application shell
- `src/components` — Vue UI components
- `src/composables` — reusable Vue composables
- `src/utils` — shared utility functions

## Scripts

- `npm run dev` — start Nuxt dev server
- `npm run build` — build production server
- `npm run start` — run built server
- `npm run generate` — generate static output
- `npm run preview` — preview production build

## PWA

PWA support is configured through `@vite-pwa/nuxt` in `nuxt.config.ts`.
