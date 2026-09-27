#!/bin/sh
# Prepares the TEST environment on container start: private storage, test config, database and test data.
set -e
mkdir -p /var/cityland/storage/uploads /var/cityland/storage/backups /var/cityland/storage/logs /var/cityland/storage/sessions
if [ "$1" = "apache2-foreground" ]; then
  php /var/www/html/tools/testenv.php setup
fi
chown -R www-data:www-data /var/cityland
exec "$@"
