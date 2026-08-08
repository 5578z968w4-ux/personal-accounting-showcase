FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libsqlite3-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite \
    && php -m | grep -qx curl \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache/security.conf /etc/apache2/conf-available/personal-accounting-security.conf
COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini

RUN a2enmod rewrite headers \
    && a2enconf personal-accounting-security

WORKDIR /var/www/html
