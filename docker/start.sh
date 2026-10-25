#!/usr/bin/env bash
# Production web server (item 77): nginx and a php-fpm pool, both in the Nixpacks image.
# Coolify start command: bash /app/docker/start.sh
# Replaces php artisan serve, whose php -S answers one request at a time.
set -euo pipefail

APP_DIR=$(cd "$(dirname "$0")/.." && pwd)
PORT=${PORT:-80}

# PHP settings: the upload limits of docker/php/uploads.ini, and a time limit for web requests
# (artisan serve had none); php-fpm's request_terminate_timeout and nginx's fastcgi_read_timeout are above it
php_flags=(-d max_execution_time=120)
while IFS='=' read -r key value; do
    php_flags+=(-d "$(echo "$key" | xargs)=$(echo "$value" | xargs)")
done < <(grep -E '^[a-z_.]+[[:space:]]*=' "$APP_DIR/docker/php/uploads.ini")
body_size=$(grep -E '^post_max_size' "$APP_DIR/docker/php/uploads.ini" | cut -d= -f2 | xargs)

# mime.types and fastcgi_params sit in nginx's compiled-in conf directory: <prefix>/conf in Nix,
# the directory of --conf-path in distribution packages
nginx_v=$(nginx -V 2>&1)
conf_path=$(grep -o -- '--conf-path=[^ ]*' <<<"$nginx_v" | cut -d= -f2 || true)
if [ -n "$conf_path" ]; then
    conf_dir=$(dirname "$conf_path")
else
    conf_dir="$(grep -o -- '--prefix=[^ ]*' <<<"$nginx_v" | cut -d= -f2)/conf"
fi

sed -e "s|@PORT@|$PORT|g" \
    -e "s|@ROOT@|$APP_DIR|g" \
    -e "s|@MIME_TYPES@|$conf_dir/mime.types|g" \
    -e "s|@FASTCGI_PARAMS@|$conf_dir/fastcgi_params|g" \
    -e "s|@BODY_SIZE@|$body_size|g" \
    "$APP_DIR/docker/nginx.conf" > /tmp/nginx.conf

# public/storage -> storage/app/public (the uploads volume), so nginx serves uploads and facsimile pages itself
[ -e "$APP_DIR/public/storage" ] || php "$APP_DIR/artisan" storage:link --quiet

php-fpm -F -R -y "$APP_DIR/docker/php-fpm.conf" "${php_flags[@]}" &
nginx -e stderr -c /tmp/nginx.conf &

# Stop both on a stop signal (TERM from Docker, QUIT from images that set STOPSIGNAL SIGQUIT);
# if either exits on its own, exit too, so Coolify restarts the container
set +e
trap 'kill -TERM $(jobs -p) 2>/dev/null; wait' TERM INT QUIT
wait -n
status=$?
kill -TERM $(jobs -p) 2>/dev/null
wait
exit "$status"
