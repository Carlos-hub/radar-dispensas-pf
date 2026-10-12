FROM php:8.3-apache

ARG UID=1000
ARG GID=1000

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip git \
 && docker-php-ext-install pdo_pgsql zip pcntl bcmath \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite \
 && groupmod -g ${GID} www-data && usermod -u ${UID} www-data

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Datas do PNCP vêm no horário de Brasília, sem fuso
RUN echo "date.timezone=America/Sao_Paulo" > /usr/local/etc/php/conf.d/timezone.ini

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    COMPOSER_HOME=/tmp/composer
RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
      /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && sed -ri "s/AllowOverride None/AllowOverride All/g" /etc/apache2/apache2.conf

COPY docker/entrypoint.sh /usr/local/bin/radar-entrypoint
RUN chmod +x /usr/local/bin/radar-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["radar-entrypoint"]
CMD ["apache2-foreground"]
