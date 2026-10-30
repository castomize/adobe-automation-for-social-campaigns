#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
STUDIO_PORT=${STUDIO_PORT:-8787}
case "$STUDIO_PORT" in
  ''|*[!0-9]*) echo 'STUDIO_PORT must be an integer from 1 to 65535.' >&2; exit 2 ;;
esac
if [ "$STUDIO_PORT" -lt 1 ] || [ "$STUDIO_PORT" -gt 65535 ]; then
  echo 'STUDIO_PORT must be an integer from 1 to 65535.' >&2
  exit 2
fi
php bin/studio.php check
exec php -d upload_max_filesize=6M -d post_max_size=16M -d memory_limit=256M -d max_file_uploads=10 -d display_errors=0 -S "127.0.0.1:$STUDIO_PORT" -t public router.php
