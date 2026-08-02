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

# Composer is not on the PATH on every Hostinger plan. Fall back to a local
# composer.phar, fetching it once if it is not there yet.
resolve_composer() {
  if command -v composer >/dev/null 2>&1; then
    COMPOSER=(composer)
  elif [ -f composer.phar ]; then
    COMPOSER=("$PHP_BIN" composer.phar)
  else
    log "Composer not found - installing composer.phar locally"
    curl -sS https://getcomposer.org/installer | "$PHP_BIN" -- --install-dir=. --filename=composer.phar
    COMPOSER=("$PHP_BIN" composer.phar)
  fi
}

maintenance_off() { "$PHP_BIN" artisan up >/dev/null 2>&1 || true; }

if [ "${DEPLOY_GIT_PULL:-0}" = "1" ]; then
  log "Pulling latest code"
  git pull --ff-only
fi

log "Deploying $(git rev-parse --short HEAD 2>/dev/null || echo 'unknown revision')"

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
