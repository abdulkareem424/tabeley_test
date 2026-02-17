FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpq5 \
    libicu-dev \
    libicu72 \
    libpng-dev \
    libpng16-16 \
    libzip-dev \
    libzip4 \
    unzip \
    git \
    curl \
    zip

RUN docker-php-ext-install pdo_pgsql pgsql intl gd zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN chown -R www-data:www-data storage bootstrap/cache

RUN a2enmod rewrite

EXPOSE 80

CMD ["apache2-foreground"]
