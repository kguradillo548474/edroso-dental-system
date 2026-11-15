#!/bin/sh
set -eu

UPLOAD_DIR="/var/www/html/assets/uploads"
SEED_DIR="/opt/edroso-seed-uploads"
mkdir -p "$UPLOAD_DIR"

# A Railway volume mounted at assets/uploads starts empty. Seed it once with
# the dentist photos and existing QR images shipped with the application.
if [ -d "$SEED_DIR" ] && [ -z "$(find "$UPLOAD_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
    cp -R "$SEED_DIR"/. "$UPLOAD_DIR"/
fi

chown -R www-data:www-data "$UPLOAD_DIR"
chmod -R u+rwX,g+rwX "$UPLOAD_DIR"

exec "$@"
