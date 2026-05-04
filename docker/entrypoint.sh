#!/bin/sh
set -e

echo "Running Spora setup..."
php /app/bin/spora spora:setup
echo "Setup complete. Starting services..."

exec /usr/bin/supervisord -c /app/supervisord.conf