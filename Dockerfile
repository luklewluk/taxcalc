# Production image: nginx + PHP-FPM in one container, no build step. The MySQL
# database holding public NBP rates is a separate service (compose.yaml).
#
# The application never writes user data, so the filesystem can stay read-only
# except for the framework cache and the runtime sockets.

# ---------------------------------------------------------------- dependencies
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

# Install without running scripts: the application code is not there yet.
# --ignore-platform-reqs because this stage runs on the composer image's PHP,
# which is not necessarily the 8.5 runtime below; the runtime stage verifies
# the real platform with `composer check-platform-reqs`.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --ignore-platform-reqs \
        --optimize-autoloader

# ---------------------------------------------------------------------- runtime
FROM php:8.5-fpm-alpine AS runtime

# Runtime libraries, then build deps for the extensions, then drop the build deps.
#
# composer.json requires ext-ctype, ext-dom, ext-iconv, ext-json, ext-mbstring,
# ext-pdo and ext-pdo_mysql. On the official PHP images ctype, iconv, json and
# pdo are already built in, but dom (libxml), mbstring (oniguruma) and pdo_mysql
# are NOT - they have to be compiled here, the first two with their -dev
# headers. intl and opcache are added for correct collation and for production
# performance.
RUN set -eux; \
    apk add --no-cache \
        nginx \
        supervisor \
        icu-libs \
        libxml2 \
        oniguruma \
        libzip; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libxml2-dev \
        oniguruma-dev \
        linux-headers; \
    docker-php-ext-install -j"$(nproc)" \
        dom \
        mbstring \
        pdo_mysql \
        intl \
        opcache; \
    apk del --no-network .build-deps; \
    rm -rf /var/cache/apk/* /tmp/*

COPY --from=vendor /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1

COPY --from=vendor /app/vendor ./vendor
COPY bin bin
COPY config config
COPY public public
COPY src src
COPY migrations migrations
COPY templates templates
COPY composer.json composer.lock ./
# Use only the public template at build time. Runtime deployment values are
# supplied through environment variables and never become image layers.
COPY .env.example .env

# Fail the build loudly if any required extension is missing from the runtime,
# rather than at the first request.
RUN set -eux; \
    php -m; \
    composer check-platform-reqs --no-dev; \
    php -r 'foreach (["ctype","dom","iconv","json","mbstring","intl","pdo_mysql"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing ext: $e\n"); exit(1); } }'

# Warm the container cache at build time so the running container needs no
# writable application directory beyond var/.
RUN set -eux; \
    composer dump-autoload --no-dev --optimize --classmap-authoritative; \
    php bin/console cache:warmup --env=prod; \
    chown -R www-data:www-data var

COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/taxcalc-entrypoint

# nginx, supervisord, PHP sessions and upload temporaries all use /tmp, mounted
# as a www-data-owned tmpfs by compose. Keep fallback directories owned for
# users who run the image directly without compose.
RUN chown -R www-data:www-data /var/lib/nginx /var/log/nginx

EXPOSE 8080

USER www-data

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r '$c=@fsockopen("127.0.0.1",8080);exit($c?0:1);'

# Brings the schema up to date, then hands over to supervisord.
ENTRYPOINT ["taxcalc-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
