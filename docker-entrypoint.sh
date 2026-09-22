#!/bin/sh
set -eu

case "${PORT:-8080}" in
  *[!0-9]*|'') echo "PORT must be a number" >&2; exit 1 ;;
esac

sed -ri "s/^Listen [0-9]+$/Listen ${PORT:-8080}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT:-8080}>/" /etc/apache2/sites-available/000-default.conf

php /var/www/html/scripts/bootstrap.php

case "${PPPM_AUTO_SEED_DEMO:-true}" in
  1|true|TRUE|yes|YES) php /var/www/html/scripts/demo.php seed ;;
esac

exec "$@"
