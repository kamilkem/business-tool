# syntax=docker/dockerfile:1
FROM dunglas/frankenphp:1-php8.4 AS base

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions apcu intl opcache pcntl pdo_pgsql zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 docker/frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

FROM base AS dev
ENV APP_ENV=dev
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch"]

# Both SPAs, built for one stage (adr/0035 §7). Needs the frontend workspace as a named build
# context: `docker build --target prod --build-context frontend=frontend api` (`make prod-image`).
# Node is the major CI uses (.github/workflows/ci.yaml); pnpm comes from `packageManager`.
FROM node:22-alpine AS spa
WORKDIR /frontend
RUN corepack enable
COPY --from=frontend package.json pnpm-lock.yaml pnpm-workspace.yaml ./
COPY --from=frontend admin/package.json admin/
COPY --from=frontend timeclock/package.json timeclock/
COPY --from=frontend packages/api-client/package.json packages/api-client/
COPY --from=frontend packages/ui/package.json packages/ui/
RUN pnpm install --frozen-lockfile
# Local node_modules and dist folders never enter the image (frontend/.dockerignore says the same).
COPY --from=frontend --exclude=**/node_modules --exclude=**/dist . .
# The admin links employees to the time clock, so its address is baked into the admin bundle
# (adr/0021): one image per stage.
ARG VITE_TIMECLOCK_URL
RUN test -n "$VITE_TIMECLOCK_URL" || { echo 'Build argument VITE_TIMECLOCK_URL is required' >&2; exit 1; } \
    && pnpm --filter admin build \
    && pnpm --filter timeclock build

# Production (adr/0035): the API and both SPAs behind FrankenPHP on plain HTTP; the edge Caddy
# terminates TLS. Dependencies, cache and assets are baked in; keys, secrets and migrations are not.
FROM base AS prod
ENV APP_ENV=prod
# Links the image in GitHub Container Registry to the repository (deploy workflow, #36).
LABEL org.opencontainers.image.source="https://github.com/kamilkem/business-tool"
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/frankenphp/conf.d/app.prod.ini "$PHP_INI_DIR/conf.d/zz-app.prod.ini"
COPY docker/frankenphp/Caddyfile.prod /etc/frankenphp/Caddyfile
COPY --chmod=755 docker/frankenphp/docker-entrypoint.prod.sh /usr/local/bin/docker-entrypoint
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && composer run-script --no-dev post-install-cmd \
    && chmod +x bin/console
COPY --from=spa /frontend/admin/dist /app/spa/admin
COPY --from=spa /frontend/timeclock/dist /app/spa/timeclock
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
