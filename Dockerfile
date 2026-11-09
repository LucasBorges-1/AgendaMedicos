FROM php:8.3-apache
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
COPY deploy/apache.conf /etc/apache2/conf-enabled/agenda-vidas.conf
COPY . /var/www/html/
WORKDIR /var/www/html
