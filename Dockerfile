FROM php:8.4-apache

RUN docker-php-ext-install pdo_mysql

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && a2enmod rewrite headers

WORKDIR /var/www/html

COPY . /var/www/html

RUN chmod -R a+rX /var/www/html \
    && ln -s /var/www/html/storage/uploads /var/www/html/public/uploads \
    && chown -R www-data:www-data /var/www/html/storage
