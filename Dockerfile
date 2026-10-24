# MoneyMentor API — PHP 8.3 + Apache. Works on Render and locally with docker compose.
FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql opcache \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-moneymentor.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/moneymentor-entrypoint

WORKDIR /var/www/html
COPY src ./src
COPY public ./public
COPY database ./database
COPY bin ./bin
RUN mkdir -p storage

RUN chmod +x /usr/local/bin/moneymentor-entrypoint \
    && chown -R www-data:www-data /var/www/html

# Render sets $PORT (usually 10000); locally it defaults to 80
ENV PORT=80
EXPOSE 80

ENTRYPOINT ["moneymentor-entrypoint"]
CMD ["apache2-foreground"]
