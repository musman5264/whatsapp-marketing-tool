#!/bin/sh
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "WARNING: APP_KEY is not set. Set it as a persistent environment" >&2
    echo "variable in EasyPanel (generate once with 'php artisan key:generate --show')." >&2
    echo "Do not auto-generate this on every boot -- it would invalidate all" >&2
    echo "existing sessions and encrypted data." >&2
fi

# Wait for the database to accept connections before migrating (EasyPanel
# starts the DB and app containers together, so the DB may not be ready yet).
if [ -n "$DB_HOST" ]; then
    echo "Waiting for database at ${DB_HOST}:${DB_PORT:-3306}..."
    for i in $(seq 1 30); do
        if php -r "exit(@fsockopen(getenv('DB_HOST'), getenv('DB_PORT') ?: 3306) ? 0 : 1);"; then
            break
        fi
        sleep 2
    done
fi

php artisan package:discover --ansi
php artisan storage:link --force || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force

exec "$@"
