FROM php:8.3-apache
RUN apt-get update && apt-get install -y unzip libzip-dev libpng-dev libonig-dev && docker-php-ext-install gd mbstring zip && a2enmod rewrite && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction && mkdir -p .form && chown -R www-data:www-data .form
EXPOSE 80
