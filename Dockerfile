# ── Stage 1: install dependencies ────────────────────────────────────────────
FROM composer:2 AS builder

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install \
      --no-dev \
      --no-scripts \
      --no-plugins \
      --no-interaction \
      --optimize-autoloader \
      --prefer-dist

COPY bin/ ./bin/
COPY src/ ./src/

# ── Stage 2: lean runtime image ───────────────────────────────────────────────
FROM php:8.3-cli-alpine AS runtime

LABEL org.opencontainers.image.title="composer-merge-driver"
LABEL org.opencontainers.image.description="Semantic git merge driver for Composer files"
LABEL org.opencontainers.image.source="https://github.com/spencermalone/composer-merge-driver"

# Install Composer so the driver can call `composer update` during re-resolution.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY --from=builder /app ./

RUN chmod +x /app/bin/composer-merge-driver

ENTRYPOINT ["/app/bin/composer-merge-driver"]
