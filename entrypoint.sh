#!/bin/bash
set -e

# Disable conflicting MPMs at runtime to prevent "More than one MPM loaded" error
a2dismod mpm_event mpm_worker || true
a2enmod mpm_prefork || true

# Configure Apache to listen on the dynamic port provided by Railway ($PORT)
PORT="${PORT:-80}"
echo "Configuring Apache to listen on port $PORT..."
sed -i "s/Listen 80/Listen $PORT/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:$PORT>/g" /etc/apache2/sites-available/000-default.conf

# Inject AllowOverride All and fix canonical port redirects (to prevent appending :8080 on reverse proxy)
echo "Injecting AllowOverride All and fixing canonical port redirects..."
sed -i "s|<\/VirtualHost>|<Directory /var/www/html>\n    Options Indexes FollowSymLinks\n    AllowOverride All\n    Require all granted\n<\/Directory>\n    UseCanonicalName Off\n    UseCanonicalPhysicalPort Off\n<\/VirtualHost>|g" /etc/apache2/sites-available/000-default.conf

# Recreate upload directories (volume mount may have cleared them)
mkdir -p /var/www/html/tkn/handlers/uploads/slips
mkdir -p /var/www/html/tkn/handlers/uploads/shop_pics
mkdir -p /var/www/html/tkn/handlers/uploads/avatars
mkdir -p /var/www/html/tkn/handlers/uploads/activity_pics

# Ensure permissions
chown -R www-data:www-data /var/www/html/tkn/handlers/uploads
chmod -R 777 /var/www/html/tkn/handlers/uploads

# Start Apache
exec apache2-foreground
