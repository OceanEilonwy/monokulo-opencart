# OpenCart 4.1.0.4 on Apache, for this extension's tests and for trying it
# out. The PHP version is a build argument: CI builds 8.1, 8.4 and 8.5.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev unzip mariadb-client \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd zip mysqli \
 && (pecl install pcov && docker-php-ext-enable pcov || echo "pcov is not available for this PHP: no coverage")

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# OpenCart itself, checked against the release's published zip.
ARG OPENCART_VERSION=4.1.0.4
ARG OPENCART_SHA256=cdef537f5a0a7e1b4ddbe16f589dd2e80b10150a09bfbe93ce76ef2d5c3218c0
RUN curl -fsSL -o /tmp/opencart.zip "https://github.com/opencart/opencart/releases/download/${OPENCART_VERSION}/opencart-${OPENCART_VERSION}.zip" \
 && echo "${OPENCART_SHA256}  /tmp/opencart.zip" | sha256sum -c - \
 && unzip -q /tmp/opencart.zip 'upload/*' -d /tmp/opencart \
 && cp -a /tmp/opencart/upload/. /var/www/html/ \
 && rm -rf /tmp/opencart /tmp/opencart.zip \
 && cp /var/www/html/config-dist.php /var/www/html/config.php \
 && cp /var/www/html/admin/config-dist.php /var/www/html/admin/config.php \
 && chown -R www-data:www-data /var/www/html

# Apache on OC_PORT (the compose file runs on the host's network, so the
# shop's address is the same from the browser, from Monokulo and from the
# tests).
ARG OC_PORT=8090
RUN sed -i "s/^Listen 80$/Listen ${OC_PORT}/" /etc/apache2/ports.conf \
 && sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${OC_PORT}>/" /etc/apache2/sites-available/000-default.conf \
 && printf 'display_errors = Off\nlog_errors = On\nerror_reporting = E_ALL\nmemory_limit = 512M\npcov.directory = /repo/src\n' > /usr/local/etc/php/conf.d/opencart.ini

# Files Apache makes (template cache, logs) stay writable by the tests,
# which boot OpenCart in-process as another user.
RUN echo 'umask 000' >> /etc/apache2/envvars

COPY docker/oc-setup /usr/local/bin/oc-setup
RUN chmod +x /usr/local/bin/oc-setup
