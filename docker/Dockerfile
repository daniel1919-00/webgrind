FROM php:8.2-fpm-alpine3.17

RUN apk update
RUN apk add --no-cache $PHPIZE_DEPS linux-headers git nginx zlib-dev
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . /app
COPY docker/start.sh /start.sh
COPY docker/nginx.conf /etc/nginx/nginx.conf
RUN rm -rf /etc/nginx/http.d/*
COPY docker/webgrind.conf /etc/nginx/http.d/webgrind.conf

RUN mv /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
RUN sed -i "s/\;\?\\s\?error_reporting =.*/error_reporting = E_ALL/" /usr/local/etc/php/php.ini
RUN sed -i "s/\;\?\\s\?display_startup_errors =.*/display_startup_errors = On/" /usr/local/etc/php/php.ini
RUN sed -i "s/\;\?\\s\?display_errors =.*/display_errors = On/" /usr/local/etc/php/php.ini

RUN chown -R nginx:nginx /app
RUN chmod -R 755 /app
WORKDIR /app
RUN composer install

ENTRYPOINT ["sh", "/start.sh"]