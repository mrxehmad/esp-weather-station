#!/bin/bash
# Run on the server after deploying temp-station (requires sudo).
# Usage: sudo bash scripts/fix-permissions.sh

set -e

APP_DIR="${1:-/var/www/temp-station}"
WEB_USER="${WEB_USER:-www-data}"

if [[ ! -d "$APP_DIR/data" ]]; then
  echo "Error: $APP_DIR/data not found"
  exit 1
fi

echo "Fixing ownership for $APP_DIR (user: $WEB_USER)..."

chown -R "$WEB_USER:$WEB_USER" "$APP_DIR/data"
chmod 775 "$APP_DIR/data"

touch "$APP_DIR/data/sinric_state.json" 2>/dev/null || true

for f in temperature.db settings.json sinric_config.json sinric_state.json; do
  if [[ -f "$APP_DIR/data/$f" ]]; then
    chmod 664 "$APP_DIR/data/$f"
  fi
done

# SQLite WAL sidecar files (if present)
for f in "$APP_DIR/data"/temperature.db-wal "$APP_DIR/data"/temperature.db-shm; do
  if [[ -f "$f" ]]; then
    chown "$WEB_USER:$WEB_USER" "$f"
    chmod 664 "$f"
  fi
done

echo "Done. Verify:"
ls -lh "$APP_DIR/data/"
