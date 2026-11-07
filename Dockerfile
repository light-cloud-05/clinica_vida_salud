FROM composer:2.8 AS dependencies

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader \
    --ignore-platform-req=ext-pdo_pgsql --ignore-platform-req=ext-pgsql

FROM php:8.3-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    PORT=10000

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libpq-dev libzip-dev unzip \
    && docker-php-ext-install bcmath curl mbstring pdo_pgsql pgsql \
    && php -r 'if (!extension_loaded("pdo_pgsql") || !extension_loaded("pgsql") || !defined("PGSQL_LIBPQ_VERSION") || (int)PGSQL_LIBPQ_VERSION < 14) { exit(1); }' \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY --from=dependencies /app/vendor ./vendor
COPY . .
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/clinica-entrypoint
RUN chmod 755 /usr/local/bin/clinica-entrypoint \
    && chown -R www-data:www-data /var/www/html

EXPOSE 10000
ENTRYPOINT ["clinica-entrypoint"]
