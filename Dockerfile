FROM dunglas/frankenphp:1-bookworm

# Install system deps
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy composer files first for caching layer
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy the rest
COPY . .

# Ensure storage is writable
RUN mkdir -p storage/logs

EXPOSE 8080 443

CMD ["frankenphp", "run", "--config", "frankenphp.conf.php"]