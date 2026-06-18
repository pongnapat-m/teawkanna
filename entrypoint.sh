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

# Inject AllowOverride All for /var/www/html inside the VirtualHost config to enable .htaccess
echo "Injecting AllowOverride All for /var/www/html..."
sed -i "s|<\/VirtualHost>|<Directory /var/www/html>\n    Options Indexes FollowSymLinks\n    AllowOverride All\n    Require all granted\n<\/Directory>\n<\/VirtualHost>|g" /etc/apache2/sites-available/000-default.conf

# Debug: Print the virtual host configuration file
echo "=== 000-default.conf content ==="
cat /etc/apache2/sites-available/000-default.conf
echo "=== End of 000-default.conf ==="

# Debug: Print all files inside the tkn directory to verify if .htaccess exists
echo "=== /var/www/html/tkn/ files ==="
ls -la /var/www/html/tkn/
echo "=== End of tkn files ==="

# Debug: Print all enabled Apache modules
echo "=== Apache enabled modules ==="
ls -la /etc/apache2/mods-enabled/
echo "=== End of enabled modules ==="

# Run the default apache2-foreground command
echo "Starting Apache on port $PORT..."
exec apache2-foreground
