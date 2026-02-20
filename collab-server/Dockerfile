# Node.js 22 is required for --experimental-sqlite support in @tldraw/sync-core
FROM node:22-slim AS builder

WORKDIR /app

# Copy package files first for better caching
COPY package.json package-lock.json* ./
RUN npm install

# Copy source code
COPY tsconfig.json ./
COPY src ./src

# Build TypeScript to JavaScript
RUN npm run build

# --- Production Stage ---
FROM node:22-slim

WORKDIR /app

# Install production dependencies only
COPY package.json package-lock.json* ./
RUN npm install --omit=dev

# Copy built artifacts from builder
COPY --from=builder /app/dist ./dist

# Environment variables (defaults)
ENV NODE_ENV=production
ENV PORT=3000
# Enable SQLite support for sync-core
ENV NODE_OPTIONS="--experimental-sqlite"

# Expose the port
EXPOSE 3000

# Start the server
CMD ["node", "dist/server.js"]
