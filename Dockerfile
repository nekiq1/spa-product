FROM php:8.2-apache

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions curl

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
