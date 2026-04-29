# Use official PHP with Apache
FROM php:8.2-apache

# Enable Apache rewrite (optional but recommended)
RUN a2enmod rewrite

# Install common PHP extensions (edit if needed)
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Copy project files into container
COPY . /var/www/html/

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html

# Railway requires this
EXPOSE 80
