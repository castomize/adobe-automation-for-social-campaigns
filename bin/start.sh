#!/bin/sh
set -eu
cd "$(dirname "$0")/.."

# Intel Macs need MacPorts because the current Homebrew installer rejects them.
install_macports() (
  macos_version=$(sw_vers -productVersion)
  case "$macos_version" in
    11.*) release=11-BigSur; checksum=eb9de14da8fccf92730b8fe3bb6a71e34ad19b72778c70b109532ed5bfd665b7 ;;
    12.*) release=12-Monterey; checksum=6d2b48713c671728ac68ea9edd5d2f1581aaaa292a5bad124c6ac191b1f0cec3 ;;
    13.*) release=13-Ventura; checksum=89c71950329c073e8be9e0ace89081b73d021a00deaa2b885ce0974ed06990fe ;;
    14.*) release=14-Sonoma; checksum=6e6e8a96f051035b34bf5e4c48f889370056f6139f05029dbc20bedfd1b65c4a ;;
    15.*) release=15-Sequoia; checksum=87b2e87ed7adc4aca947b42eb7ff991614cc0edfccb486237f2bbc75f28aae63 ;;
    26.*) release=26-Tahoe; checksum=588f14ccd3c6199559a070a80e6cddd6871721a08cc434775bf54378fd667910 ;;
    *) echo "Automatic MacPorts setup is unavailable for macOS $macos_version. Install PHP 8.2+ from https://www.macports.org/install.php, then rerun this command." >&2; exit 1 ;;
  esac
  setup_dir=$(mktemp -d)
  trap 'rm -rf "$setup_dir"' 0
  trap 'exit 1' HUP INT TERM
  package="$setup_dir/macports.pkg"
  echo "Installing MacPorts for macOS $macos_version. Your Mac password may be requested."
  if ! curl -fL --proto '=https' --tlsv1.2 -o "$package" "https://github.com/macports/macports-base/releases/download/v2.12.7/MacPorts-2.12.7-$release.pkg"; then
    echo 'Could not download MacPorts. Check your connection and rerun sh bin/start.sh.' >&2
    exit 1
  fi
  if ! printf '%s  %s\n' "$checksum" "$package" | shasum -a 256 -c -; then
    echo 'MacPorts checksum verification failed. Nothing was installed.' >&2
    exit 1
  fi
  if ! /usr/sbin/pkgutil --check-signature "$package"; then
    echo 'MacPorts signature verification failed. Nothing was installed.' >&2
    exit 1
  fi
  if ! sudo /usr/sbin/installer -pkg "$package" -target /; then
    echo 'MacPorts setup did not complete. Resolve the installer message above, then rerun sh bin/start.sh.' >&2
    exit 1
  fi
)

STUDIO_PORT=${STUDIO_PORT:-8787}
case "$STUDIO_PORT" in
  ''|*[!0-9]*) echo 'STUDIO_PORT must be an integer from 1 to 65535.' >&2; exit 2 ;;
esac
if [ "$STUDIO_PORT" -lt 1 ] || [ "$STUDIO_PORT" -gt 65535 ]; then
  echo 'STUDIO_PORT must be an integer from 1 to 65535.' >&2
  exit 2
fi

php_bin=$(command -v php || true)
if [ -z "$php_bin" ] && [ -x /opt/local/bin/php ]; then
  php_bin=/opt/local/bin/php
fi
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
  if [ -x /opt/local/bin/port ] || { [ -z "$brew_bin" ] && [ "$(uname -m)" = x86_64 ]; }; then
    if [ ! -x /opt/local/bin/port ]; then
      install_macports
    fi
    echo 'Installing PHP and its cURL, GD/FreeType and ZIP extensions with MacPorts. This may take several minutes.'
    if ! sudo /opt/local/bin/port install php84 php84-curl php84-gd php84-zip; then
      echo 'PHP installation did not complete. Resolve the MacPorts message above, then rerun sh bin/start.sh.' >&2
      exit 1
    fi
    sudo /opt/local/bin/port select --set php php84
    php_bin=/opt/local/bin/php84
  else
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
      brew_bin=/opt/homebrew/bin/brew
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
