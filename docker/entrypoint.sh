#!/bin/sh
set -e

# Listen on the port the platform gives us
sed -i "s/^Listen .*/Listen ${PORT:-80}/" /etc/apache2/ports.conf

# Create/upgrade tables and the first admin. Don't block start-up if the DB is down;
# the API will answer 503 until it's reachable.
php /var/www/html/bin/migrate.php || echo "[entrypoint] migration failed — check DB_* settings"

exec "$@"
