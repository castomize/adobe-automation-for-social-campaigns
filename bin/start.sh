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

php_bin=$(command -v php || true)
if [ -z "$php_bin" ]; then
  if [ "$(uname -s)" != Darwin ]; then
    echo 'PHP was not found. Install PHP 8.2+ with cURL, fileinfo, GD/FreeType and ZIP; see README.md for Linux/WSL commands.' >&2
    exit 1
  fi

  brew_bin=$(command -v brew || true)
  if [ -z "$brew_bin" ]; then
    for candidate in /opt/homebrew/bin/brew /usr/local/bin/brew; do
      if [ -x "$candidate" ]; then
        brew_bin=$candidate
        break
      fi
    done
  fi
  if [ -z "$brew_bin" ]; then
    echo 'PHP and Homebrew were not found. Starting the official Homebrew installer; follow its prompts (it may request your Mac password).'
    if ! installer=$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh); then
      echo 'Could not download Homebrew. Check your connection and rerun sh bin/start.sh.' >&2
      exit 1
    fi
    if ! /bin/bash -c "$installer"; then
      echo 'Homebrew setup did not complete. Resolve the installer message above, then rerun sh bin/start.sh.' >&2
      exit 1
    fi
    case "$(uname -m)" in
      arm64) brew_bin=/opt/homebrew/bin/brew ;;
      *) brew_bin=/usr/local/bin/brew ;;
    esac
  fi

  # Use the installed PHP directly, even when Homebrew is absent from PATH.
  if ! php_prefix=$("$brew_bin" --prefix php); then
    echo 'Could not locate Homebrew PHP. Check Homebrew setup, then rerun sh bin/start.sh.' >&2
    exit 1
  fi
  php_bin="$php_prefix/bin/php"
  if [ ! -x "$php_bin" ]; then
    echo 'Installing PHP with Homebrew. The first installation can take several minutes, especially on older Macs.'
    if ! "$brew_bin" install php; then
      echo 'PHP installation did not complete. Resolve the Homebrew message above, then rerun sh bin/start.sh.' >&2
      exit 1
    fi
  fi
fi

if ! "$php_bin" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);'; then
  echo 'PHP 8.2 or later is required. Install a current PHP CLI and rerun sh bin/start.sh.' >&2
  exit 1
fi
if ! "$php_bin" bin/studio.php check; then
  echo 'Startup checks failed. Install or enable the missing items listed above, then rerun sh bin/start.sh.' >&2
  exit 1
fi
echo "Open http://127.0.0.1:$STUDIO_PORT/ and select Load example. Leave this terminal open; Ctrl+C stops the app."
exec "$php_bin" -d upload_max_filesize=6M -d post_max_size=16M -d memory_limit=256M -d max_file_uploads=10 -d display_errors=0 -S "127.0.0.1:$STUDIO_PORT" -t public router.php
