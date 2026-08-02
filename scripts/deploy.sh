#!/usr/bin/env bash
#
# Post-deploy steps, run on the Hostinger server once the new commit is on disk.
# Invoked over SSH by .github/workflows/deploy.yml, and safe to run by hand:
#
#   cd ~/domains/example.com/chakra-portal && bash scripts/deploy.sh
#
# Optional environment overrides:
#   PHP_BIN=/usr/bin/php8.2   pick a specific PHP CLI binary
#   DEPLOY_GIT_PULL=1         pull the code here instead of via Hostinger's webhook
#   SKIP_MIGRATIONS=1         deploy code only, leave the schema alone
#
set -euo pipefail

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PHP_BIN="${PHP_BIN:-php}"
ASSET_BUNDLE="${ASSET_BUNDLE:-build-assets.tar.gz}"
export COMPOSER_MEMORY_LIMIT=-1

log() { printf '\n==> %s\n' "$1"; }

# Hostinger's default CLI php is often older than the version the site runs on,
# and Laravel 12 needs 8.2+. Find a good binary instead of failing deep inside
# composer with "your requirements could not be resolved".
php_is_supported() { "$1" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' >/dev/null 2>&1; }

resolve_php() {
  if php_is_supported "$PHP_BIN"; then
    return
  fi

  for candidate in php8.4 php8.3 php8.2 \
    /usr/bin/php8.4 /usr/bin/php8.3 /usr/bin/php8.2 \
    /opt/alt/php84/usr/bin/php /opt/alt/php83/usr/bin/php /opt/alt/php82/usr/bin/php; do
    if command -v "$candidate" >/dev/null 2>&1 && php_is_supported "$candidate"; then
      log "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || echo '?') is too old - using $candidate"
      PHP_BIN="$candidate"
      return
    fi
  done

  echo "ERROR: no PHP 8.2+ binary found (PHP_BIN=$PHP_BIN is $("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || echo 'missing'))." >&2
  echo "       Raise the PHP version in hPanel, or set PHP_BIN to a suitable binary." >&2
  exit 1
}

# Composer is not on the PATH on every Hostinger plan, and where it is, its
# shebang picks the default php - which is the too-old one we just worked
# around. Always drive it with the resolved binary instead.
resolve_composer() {
  local candidate
  for candidate in "$(command -v composer 2>/dev/null)" composer.phar; do
    [ -n "$candidate" ] && [ -f "$candidate" ] || continue
    if "$PHP_BIN" "$candidate" --version >/dev/null 2>&1; then
      COMPOSER=("$PHP_BIN" "$candidate")
      return
    fi
  done

  log "Composer not usable - installing composer.phar locally"
  curl -sS https://getcomposer.org/installer | "$PHP_BIN" -- --install-dir=. --filename=composer.phar
  COMPOSER=("$PHP_BIN" composer.phar)
}

maintenance_off() { "$PHP_BIN" artisan up >/dev/null 2>&1 || true; }

if [ "${DEPLOY_GIT_PULL:-0}" = "1" ]; then
  branch="${DEPLOY_BRANCH:-main}"
  log "Fetching origin/$branch"
  git fetch --prune origin "$branch"

  # This checkout is a deploy target, not somewhere to edit code, so make the
  # working tree match origin exactly. A merge would wedge the deploy on any
  # stray server-side change; a reset just replaces it. Untracked files
  # (.env, storage uploads, public/build) are left alone.
  git reset --hard FETCH_HEAD
fi

log "Deploying $(git rev-parse --short HEAD 2>/dev/null || echo 'unknown revision')"

resolve_php

# .env is untracked, so a fresh clone into the deploy directory arrives without
# one. Say so plainly rather than letting artisan fail on a missing APP_KEY.
if [ ! -f .env ]; then
  echo "ERROR: no .env in $(pwd). Copy .env.example, fill in the production" >&2
  echo "       values, then run: $PHP_BIN artisan key:generate" >&2
  exit 1
fi

# Vite output is not in git; the workflow scp's it up as a tarball.
if [ -f "$ASSET_BUNDLE" ]; then
  log "Unpacking compiled assets"
  rm -rf public/build
  tar -xzf "$ASSET_BUNDLE" -C public
  rm -f "$ASSET_BUNDLE"
elif [ -d public/build ]; then
  log "No new asset bundle - keeping the assets already on disk"
else
  echo "WARNING: public/build is missing, so Vite assets will 404." >&2
fi

resolve_composer

log "Enabling maintenance mode"
"$PHP_BIN" artisan down --retry=15 || true
trap maintenance_off EXIT

log "Installing PHP dependencies"
"${COMPOSER[@]}" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress

if [ "${SKIP_MIGRATIONS:-0}" = "1" ]; then
  log "Skipping migrations (SKIP_MIGRATIONS=1)"
else
  log "Running migrations"
  "$PHP_BIN" artisan migrate --force
fi

# Rebuild rather than clear: a cached config is what keeps the portal fast, and
# clearing without recaching leaves the next request doing the work.
log "Rebuilding caches"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan event:cache

log "Linking storage"
"$PHP_BIN" artisan storage:link || true

log "Restarting queue workers"
"$PHP_BIN" artisan queue:restart || true

log "Leaving maintenance mode"
maintenance_off
trap - EXIT

log "Deploy complete"
