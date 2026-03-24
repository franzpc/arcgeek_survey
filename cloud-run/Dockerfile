FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
        libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && a2enmod rewrite

# Cloud Run requires port 8080
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf

EXPOSE 8080

CMD ["apache2-foreground"]
