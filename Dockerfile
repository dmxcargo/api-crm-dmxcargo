FROM composer:2@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 AS composer
FROM php:8.4.26-apache-bookworm@sha256:57ccfbd765e5ee5f85dee5864e47ca8db0b540b0c091c052d9b275b2498a5395

RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev libonig-dev unzip \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip pcntl bcmath mbstring opcache \
 && a2enmod rewrite headers \
 && sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf \
 && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername \
 && rm -rf /var/lib/apt/lists/*

COPY apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
ENV COMPOSER_HOME=/tmp/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction \
 && mkdir -p storage/app/private-exports storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && apachectl -t

EXPOSE 80
CMD ["apache2-foreground"]
