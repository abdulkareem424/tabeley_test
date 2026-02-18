FROM php:8.3-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    libicu-dev \
    libpng-dev \
    libzip-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    unzip \
    git \
    curl \
    zip \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl gd zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader

COPY . .

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rw storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

ENTRYPOINT ["docker/entrypoint.sh"]

EXPOSE 9000

CMD ["php-fpm"]
