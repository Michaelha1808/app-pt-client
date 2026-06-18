FROM node:22-alpine AS deps
WORKDIR /app
COPY package*.json ./
RUN npm ci

FROM node:22-alpine AS builder
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .
RUN npm run build

FROM node:22-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production

RUN addgroup --system --gid 1001 nodejs && \
    adduser  --system --uid 1001 nuxtjs

COPY --from=builder --chown=nuxtjs:nodejs /app/.output ./

USER nuxtjs
EXPOSE 3000
ENV PORT=3000
ENV HOST=0.0.0.0
CMD ["node", "server/index.mjs"]
