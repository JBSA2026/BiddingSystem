# TEST ENVIRONMENT image: PHP 8.3 + Apache, similar to a typical cPanel server (Apache + .htaccess).
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev libzip-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip \
 && a2enmod headers expires deflate rewrite \
 && rm -rf /var/lib/apt/lists/*

# Honour the project's .htaccess files (as cPanel does) and pass CL_CONFIG through to PHP.
RUN printf '<Directory /var/www/html>\n  AllowOverride All\n  Require all granted\n</Directory>\nPassEnv CL_CONFIG\nServerName localhost\n' \
      > /etc/apache2/conf-available/cityland.conf \
 && a2enconf cityland

COPY php.ini /usr/local/etc/php/conf.d/cityland.ini
COPY entrypoint.sh /usr/local/bin/cityland-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/cityland-entrypoint && chmod +x /usr/local/bin/cityland-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["cityland-entrypoint"]
CMD ["apache2-foreground"]
