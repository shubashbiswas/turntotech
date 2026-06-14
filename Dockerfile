# Stage 1: Dependencies
FROM node:22-alpine AS deps
RUN apk add --no-cache libc6-compat
RUN corepack enable && corepack prepare pnpm@latest --activate
WORKDIR /app
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN pnpm install --frozen-lockfile

# Stage 2: Builder
FROM node:22-alpine AS builder
RUN apk add --no-cache libc6-compat
RUN corepack enable && corepack prepare pnpm@latest --activate
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .

# Build arguments for environment variables needed at build time
ARG WORDPRESS_URL
ARG WORDPRESS_HOSTNAME
ENV WORDPRESS_URL=$WORDPRESS_URL
ENV WORDPRESS_HOSTNAME=$WORDPRESS_HOSTNAME
ENV NEXT_TELEMETRY_DISABLED=1

RUN pnpm build

# Stage 3: Runner
FROM node:22-alpine AS runner
RUN apk add --no-cache libc6-compat
WORKDIR /app

ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1

# Runtime environment variables (also available at runtime, not just build time)
ARG WORDPRESS_URL
ARG WORDPRESS_HOSTNAME
ENV WORDPRESS_URL=$WORDPRESS_URL
ENV WORDPRESS_HOSTNAME=$WORDPRESS_HOSTNAME

# Install sharp for Next.js image optimization
RUN corepack enable && corepack prepare pnpm@latest --activate && \
    pnpm add sharp

# Copy public assets and standalone build output
COPY --from=builder /app/public ./public
COPY --from=builder /app/.next/standalone ./
COPY --from=builder /app/.next/static ./.next/static

# Create non-root user and set ownership
RUN addgroup --system --gid 1001 nodejs && \
    adduser --system --uid 1001 nextjs && \
    chown -R nextjs:nodejs /app

USER nextjs

EXPOSE 3000

ENV HOSTNAME="0.0.0.0"
ENV PORT=3000

CMD ["node", "server.js"]
