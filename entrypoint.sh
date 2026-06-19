#!/bin/bash
set -e

# Disable conflicting MPMs at runtime to prevent "More than one MPM loaded" error
a2dismod mpm_event mpm_worker || true
a2enmod mpm_prefork || true

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
