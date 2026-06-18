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

# Run the default apache2-foreground command
echo "Starting Apache on port $PORT..."
exec apache2-foreground
