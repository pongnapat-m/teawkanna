#!/bin/bash
set -e

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
