FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

FROM php:8.4-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache \
    bash \
    fcgi \
    icu-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    libzip-dev \
    oniguruma-dev \
    postgresql-dev \
    tzdata \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
      bcmath \
      exif \
      gd \
      intl \
      pdo_pgsql \
      zip \
    && apk del --no-cache \
      icu-dev \
      libpng-dev \
      libjpeg-turbo-dev \
      libwebp-dev \
      freetype-dev \
      libzip-dev \
      oniguruma-dev \
      postgresql-dev

COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN cp .env.example .env || true \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["sh", "-c", "php artisan serve --host=0.0.0.0 --port=${PORT:-8000}"]
