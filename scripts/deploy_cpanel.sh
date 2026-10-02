#!/usr/bin/env bash
set -euo pipefail

report_failure() {
  status=$?
  command="$BASH_COMMAND"
  line="$1"
  trap - ERR
  printf '::error title=Laravel deployment failed::Line %s exited with code %s while running: %s\n' \
    "$line" "$status" "$command"
  exit "$status"
}
trap 'report_failure "$LINENO"' ERR

echo ""
echo "=========================================="
echo " Laravel cPanel deploy: $(date)"
echo "=========================================="

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

if command -v composer >/dev/null 2>&1; then
  COMPOSER_BIN="$(command -v composer)"
elif [[ -x "$HOME/bin/composer" ]]; then
  COMPOSER_BIN="$HOME/bin/composer"
else
  echo "[error] Composer was not found in PATH or at $HOME/bin/composer"
  exit 1
fi

if [[ ! -f ".env" ]]; then
  echo "[error] .env not found in $APP_DIR"
  echo "Create .env first, then rerun."
  exit 1
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  echo "[init] APP_KEY missing, generating..."
  php artisan key:generate --force
fi

echo "[1/6] Installing PHP dependencies..."
composer_log="$(mktemp "${TMPDIR:-/tmp}/almaxapi-composer.XXXXXX")"
if ! "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction \
  2>&1 | tee "$composer_log"; then
  composer_failure="unclassified Composer error"
  if grep -Eqi 'permission denied|could not delete|cannot create cache' "$composer_log"; then
    composer_failure="filesystem permissions"
  elif grep -Eqi 'lock file.*not compatible|platform requirements|requires php|missing.*extension' "$composer_log"; then
    composer_failure="PHP or extension platform requirements"
  elif grep -Eqi 'composer\.lock.*not up to date|lock file.*not up to date' "$composer_log"; then
    composer_failure="composer.json and composer.lock mismatch"
  elif grep -Eqi 'allowed memory size|out of memory' "$composer_log"; then
    composer_failure="PHP memory limit"
  elif grep -Eqi 'could not resolve|connection timed out|SSL operation failed|curl error' "$composer_log"; then
    composer_failure="network connectivity"
  elif grep -Eqi 'script .* returned with error code|artisan package:discover' "$composer_log"; then
    composer_failure="Laravel Composer script"
  fi
  printf '::error title=Composer install failed::Failure category: %s. Review the authenticated workflow log for details.\n' \
    "$composer_failure"
  rm -f "$composer_log"
  exit 1
fi
rm -f "$composer_log"

echo "[2/6] Running migrations..."
php artisan migrate --force

echo "[3/6] Ensuring uploads directories exist..."
mkdir -p public/uploads/proofs public/uploads/tips public/uploads/wins public/uploads/testimonials public/uploads/config
chmod -R 775 public/uploads || true

echo "[4/6] Clearing caches..."
php artisan optimize:clear

echo "[5/6] Rebuilding production caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[6/6] Verifying health endpoint routing..."
php artisan route:list | grep -E "api/health|config/vip-config" || true

php artisan queue:restart

echo ""
echo "Deploy complete."
echo "=========================================="
