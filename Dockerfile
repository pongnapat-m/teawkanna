FROM php:8.2-apache

# Disable conflicting MPMs and ensure only prefork is enabled
RUN a2dismod mpm_event mpm_worker || true
RUN a2enmod mpm_prefork

# Enable mod_rewrite and mod_headers
RUN a2enmod rewrite headers

# Configure Apache to allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Install mysqli and pdo_mysql extensions
RUN docker-php-ext-install mysqli pdo_mysql && docker-php-ext-enable mysqli pdo_mysql

# Create a subdirectory to maintain the /tkn subpath structure
RUN mkdir -p /var/www/html/tkn

# Copy project files into /var/www/html/tkn/
COPY . /var/www/html/tkn/

# Convert Windows CRLF line endings to Unix LF (critical for .htaccess)
RUN find /var/www/html/tkn -type f \( -name "*.php" -o -name "*.css" -o -name "*.js" -o -name "*.html" -o -name ".htaccess" -o -name "*.conf" \) -exec sed -i 's/\r$//' {} \;

# Add a redirect from / to /tkn/
RUN echo '<?php header("Location: /tkn/"); exit;' > /var/www/html/index.php

# Add index.php to /tkn/ so DirectoryIndex resolves; chdir to pages/ so relative includes work
RUN echo '<?php chdir(__DIR__ . "/pages"); require __DIR__ . "/pages/home.php";' > /var/www/html/tkn/index.php

# Pre-create all upload directories so PHP never needs to mkdir() at runtime
# (runtime mkdir() fails because www-data cannot create dirs in a root-owned tree)
RUN mkdir -p /var/www/html/tkn/handlers/uploads/slips \
            /var/www/html/tkn/handlers/uploads/shop_pics \
            /var/www/html/tkn/handlers/uploads/avatars \
            /var/www/html/tkn/handlers/uploads/activity_pics

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html
RUN find /var/www/html -type d -exec chmod 755 {} \;
RUN find /var/www/html -type f -exec chmod 644 {} \;

# Make upload directories writable by www-data (Apache process)
RUN chmod -R 775 /var/www/html/tkn/handlers/uploads

# Setup entrypoint script to prevent MPM conflicts at runtime
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Expose port 8080 (Railway sets PORT=8080 by default)
EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
