FROM php:8.4-fpm-alpine

# bcmath is here on purpose: decimal money arithmetic should never touch floats.
# pcntl is for queue workers later (week 3).
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install pdo_mysql bcmath opcache pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/99-casino-wallet.ini

WORKDIR /var/www/html
