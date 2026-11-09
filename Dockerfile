# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1-php8.3

RUN install-php-extensions pdo_sqlite intl zip opcache pcntl

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Nur HTTP auf Port 8080: TLS endet am Reverse Proxy (Traefik bzw. Caddy).
ENV SERVER_NAME=:8080 \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# Abhängigkeiten zuerst, damit Docker sie zwischenspeichern kann.
# Composer läuft im Laufzeit-Image und löst daher passend zu dessen PHP-Version auf.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && rm /usr/local/bin/composer

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-portal.ini
COPY docker/entrypoint.sh /usr/local/bin/portal-entrypoint

# Die Anwendung läuft ohne Root-Rechte.
RUN chmod +x /usr/local/bin/portal-entrypoint \
    && useradd --system --uid 1000 --create-home portal \
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p /app/database/data /app/storage/app/vault \
    && chown -R portal:portal /app/storage /app/bootstrap/cache /app/database/data /data/caddy /config/caddy

USER portal

EXPOSE 8080

ENTRYPOINT ["portal-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
