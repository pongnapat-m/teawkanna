#!/bin/bash
set -e

echo "=== Entrypoint script started ==="

# Disable all MPM modules to ensure a clean state at runtime
echo "Disabling mpm_event and mpm_worker..."
a2dismod mpm_event mpm_worker || true

echo "Enabling mpm_prefork..."
a2enmod mpm_prefork

# Configure Apache to listen on the dynamic port provided by Railway ($PORT)
PORT="${PORT:-80}"
echo "Configuring Apache to listen on port $PORT..."
sed -i "s/Listen 80/Listen $PORT/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:$PORT>/g" /etc/apache2/sites-available/000-default.conf

# Inject AllowOverride All and fix canonical port redirects (to prevent appending :8080 on reverse proxy)
echo "Injecting AllowOverride All and fixing canonical port redirects..."
sed -i "s|<\/VirtualHost>|<Directory /var/www/html>\n    Options Indexes FollowSymLinks\n    AllowOverride All\n    Require all granted\n<\/Directory>\n    UseCanonicalName Off\n    UseCanonicalPhysicalPort Off\n<\/VirtualHost>|g" /etc/apache2/sites-available/000-default.conf

# Configure runtime folders and permissions for the mounted Railway Volume
# (When a volume is mounted at /var/www/html/tkn/handlers/uploads, it overrides the Dockerfile directories and is owned by root)
echo "Setting up volume directories and permissions..."
mkdir -p /var/www/html/tkn/handlers/uploads/slips \
         /var/www/html/tkn/handlers/uploads/shop_pics \
         /var/www/html/tkn/handlers/uploads/avatars \
         /var/www/html/tkn/handlers/uploads/activity_pics

chown -R www-data:www-data /var/www/html/tkn/handlers/uploads
chmod -R 777 /var/www/html/tkn/handlers/uploads

# Run the default apache2-foreground command
echo "Starting Apache on port $PORT..."
exec apache2-foreground
