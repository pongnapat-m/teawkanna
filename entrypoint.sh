#!/bin/bash
set -e

echo "=== Entrypoint script started ==="

# Disable all MPM modules to ensure a clean state at runtime
echo "Disabling mpm_event and mpm_worker..."
a2dismod mpm_event mpm_worker || true

echo "Enabling mpm_prefork..."
a2enmod mpm_prefork

# Run the default apache2-foreground command
echo "Starting Apache..."
exec apache2-foreground
