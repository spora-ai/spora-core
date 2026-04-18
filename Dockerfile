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

# Ensure storage directory exists and is writable
RUN mkdir -p /app/storage/logs /app/storage/framework/cache && \
    chmod -R 775 /app/storage

# ------------------------------------------------------------------
# Recommended: run as non-root for production security
# Uncomment the following and adjust UID/GID as needed:
# USER www-data:www-data
# ------------------------------------------------------------------

EXPOSE 80 443 443/udp

CMD ["frankenphp", "run", "--config", "frankenphp.conf.php"]