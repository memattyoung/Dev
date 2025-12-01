FROM php:8.2-apache

# Copy PHP app to Apache document root
COPY . /var/www/html/

# Enable mysqli extension for MySQL
RUN docker-php-ext-install mysqli pdo pdo_mysql
