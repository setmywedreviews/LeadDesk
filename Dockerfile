FROM php:8.3-cli

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update && apt-get install -y --no-install-recommends libgmp-dev libcurl4-openssl-dev && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install gmp mbstring curl pdo pdo_mysql

WORKDIR /app
COPY composer.json /app/composer.json
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

COPY . /app

EXPOSE 8080
CMD ["sh","-c","php -S 0.0.0.0:${PORT:-8080} -t /app"]
