# syntax=docker/dockerfile:1
# FrankenPHP includes most PHP extensions and system libs

FROM dunglas/frankenphp:1-php8.5-bookworm

WORKDIR /app

# Copy composer files first for caching layer
COPY composer.json composer.lock* ./

# Install dependencies (production)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy application
COPY . .

# Install supervisord for process supervision
RUN apt-get update && apt-get install -y --no-install-recommends supervisord && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /app/storage/logs /app/storage/framework/cache && \
    chmod -R 775 /app/storage

EXPOSE 80 443 443/udp

# Run supervisord which manages both frankenphp (web) and the worker daemon
CMD ["/usr/bin/supervisord", "-c", "/app/supervisord.conf"]