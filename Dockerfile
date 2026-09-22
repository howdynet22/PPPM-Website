FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2dismod mpm_event mpm_worker \
    && a2enmod mpm_prefork headers rewrite

COPY . /var/www/html
COPY docker-entrypoint.sh /usr/local/bin/pppm-entrypoint

RUN chmod +x /usr/local/bin/pppm-entrypoint \
    && chown -R www-data:www-data /var/www/html

ENV PORT=8080 \
    PPPM_AUTO_SEED_DEMO=true \
    PPPM_APP_DEBUG=false

EXPOSE 8080

ENTRYPOINT ["pppm-entrypoint"]
CMD ["apache2-foreground"]
