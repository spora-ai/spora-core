# syntax=docker/dockerfile:1

# ── Stage 1: Build frontend ────────────────────────────────────────────────────
FROM node:22-alpine AS frontend-builder

WORKDIR /app

COPY frontend/package*.json ./
RUN npm ci

COPY frontend/ ./
RUN npm run build

# ── Stage 2: PHP dependencies via Composer ───────────────────────────────────
FROM composer:2.8 AS deps-builder

WORKDIR /app

# Install the extensions composer.json declares (pdo_mysql, etc.) so the
# platform check in `composer install` doesn't fail. The runtime stage
# also installs pdo_mysql; this stage only needs them present to satisfy
# the platform check while resolving deps.
RUN docker-php-ext-install pdo_mysql

# Install PHP deps (composer image has ext-zip baked in)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# ── Stage 3: Production runtime ──────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.5-bookworm

WORKDIR /app

# Runtime deps only (no build tools)
RUN apt-get update && apt-get install -y --no-install-recommends supervisor && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo_mysql

# Copy application
COPY . .

# Copy PHP deps from deps-builder
COPY --from=deps-builder /app/vendor /app/vendor

# Copy built frontend assets
COPY --from=frontend-builder /public/dist ./public/dist

# Copy supervisord config (located in docker/ but needed at app root)
COPY docker/supervisord.conf /app/supervisord.conf
COPY docker/frankenphp.conf /app/frankenphp.conf

# Copy and make entrypoint executable
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

RUN mkdir -p /app/storage/logs && chmod -R 775 /app/storage

EXPOSE 80 443 443/udp

ENTRYPOINT ["/entrypoint.sh"]
CMD []
