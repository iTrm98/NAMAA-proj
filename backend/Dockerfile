# Back: PHP 8.5 with the extensions Laravel 13, MySQL and Reverb need.
FROM php:8.5-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libjpeg62-turbo-dev libpng-dev \
    # gd (JPEG + PNG): blurs people in camera photos before they are saved.
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo_mysql pcntl zip intl bcmath gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The project folder is mounted from the host, so its owner differs from the
# container user. Let Git and Composer work in it anyway.
RUN git config --global --add safe.directory '*'
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# The code is mounted as a volume in docker-compose, so it is not copied here.
EXPOSE 8000
