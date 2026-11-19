FROM php:8.5-apache

ARG UID=1000
ARG GID=1000

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev shellcheck bats \
 && docker-php-ext-install -j"$(nproc)" intl pdo_mysql zip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN a2enmod rewrite headers \
 && sed -ri 's!/var/www/html!/app/public!g' /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf \
 && printf '<Directory /app/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-enabled/app.conf

RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g ${GID} www-data \
 && mkdir -p /var/www/.composer && chown -R www-data:www-data /var/www

COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app
