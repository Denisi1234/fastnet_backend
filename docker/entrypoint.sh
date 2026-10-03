#!/bin/sh
# Backend container entrypoint: storage link + migrations, then supervisord.
#
# Why this exists instead of relying on the Docker build:
#  - public/storage in a repo checkout is a symlink to the developer's own
#    machine, which the image build copies as a dangling link. Uploaded files
#    then 404 even though the bytes are on disk. Re-linking at boot fixes it
#    on every platform (Railway included).
#  - Migrations must apply themselves on deploy: Railway has no "release
#    command" phase for Dockerfile deploys, so the first boot after a schema
#    change would otherwise serve new code against an old schema.
set -eu
cd /var/www/html

# A missing path AND a dangling symlink both fail -e. Only a real directory
# (e.g. a platform volume mounted there) is left alone — rm -f on a dangling
# link removes the link itself, never content.
if [ ! -e public/storage ]; then
    rm -f public/storage
    php artisan storage:link >/dev/null 2>&1 || true
fi

# Idempotent. Retried because on a cold platform start the database service
# can still be coming up when this container boots. The app boots regardless
# afterwards so a failed migration never takes the site down by itself.
tries=0
until php artisan migrate --force >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -ge 12 ]; then
        echo "entrypoint: migrate still failing after ~60s, continuing boot (check logs)" >&2
        break
    fi
    sleep 5
done

exec /usr/bin/supervisord -c /etc/supervisord.conf
