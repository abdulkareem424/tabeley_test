FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libicu-dev \
    libpng-dev \
    unzip \
    git \
    curl

RUN docker-php-ext-install pdo_pgsql pgsql intl gd

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN composer install --no-dev --optimize-autoloader

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:80", "-t", "public"]
