# The Scheduler app for local use without PHP or Node on the machine:
# PHP (with the extensions the app needs), Composer and Node in one image.
# The code is mounted from the checkout; see compose.yaml (profile "app").
FROM php:8.4-cli-bookworm

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql zip gd intl bcmath pcntl sockets \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip mariadb-client \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY entrypoint.sh /usr/local/bin/scheduler-entrypoint
RUN chmod +x /usr/local/bin/scheduler-entrypoint

WORKDIR /app
ENTRYPOINT ["scheduler-entrypoint"]
# PHP's server directly, not `artisan serve`: that passes only a few
# environment variables on to its workers, dropping compose's DB_HOST.
# Laravel's router script runs from public/, as `serve` starts it.
CMD ["sh", "-c", "cd public && exec php -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"]
