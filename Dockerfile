FROM php:8.2-apache

# Enable rewrite only
RUN a2enmod rewrite

# Install extensions if needed
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copy files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
