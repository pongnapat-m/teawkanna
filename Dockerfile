FROM php:8.2-apache

# Disable conflicting MPMs and ensure only prefork is enabled
RUN a2dismod mpm_event mpm_worker || true
RUN a2enmod mpm_prefork

# Enable mod_rewrite and mod_headers
RUN a2enmod rewrite headers

# Configure Apache to allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Install mysqli extension
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# Create a subdirectory to maintain the /tkn subpath structure
RUN mkdir -p /var/www/html/tkn

# Copy project files into /var/www/html/tkn/
COPY . /var/www/html/tkn/

# Add a redirect from / to /tkn/
RUN echo '<?php header("Location: /tkn/"); exit;' > /var/www/html/index.php

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html
