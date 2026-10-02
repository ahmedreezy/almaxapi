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

echo "[init] Preparing writable Laravel runtime directories..."
mkdir -p \
  bootstrap/cache \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/testing \
  storage/framework/views \
  storage/app \
  storage/logs
chmod -R u+rwX bootstrap/cache storage

git rev-parse --short=12 HEAD > storage/app/release

echo "[1/7] Installing PHP dependencies without application scripts..."
composer_log="$(mktemp "${TMPDIR:-/tmp}/almaxapi-composer.XXXXXX")"
if ! "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction --no-scripts \
  2>&1 | tee "$composer_log"; then
  composer_failure="unclassified Composer error"
  if grep -Eqi 'permission denied|could not delete|cannot create cache|must be present and writable|file_put_contents.*failed|failed to open stream' "$composer_log"; then
    composer_failure="filesystem permissions"
  elif grep -Eqi 'failed to parse.*env|dotenv|unexpected whitespace|reserved character' "$composer_log"; then
    composer_failure="malformed production .env syntax"
  elif grep -Eqi 'unsupported cipher|incorrect key length|no application encryption key' "$composer_log"; then
    composer_failure="invalid or missing APP_KEY"
  elif grep -Eqi 'SQLSTATE|could not find driver|connection refused|database.*does not exist' "$composer_log"; then
    composer_failure="database connection during application bootstrap"
  elif grep -Eqi 'class .* not found|target class .* does not exist|trait .* not found|call to undefined function' "$composer_log"; then
    composer_failure="missing PHP class, package, or extension"
  elif grep -Eqi 'parseerror|syntax error|typeerror|undefined (variable|property|array key)' "$composer_log"; then
    composer_failure="PHP application error during bootstrap"
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

echo "[2/7] Validating production environment..."
php scripts/validate_env.php .env

if ! grep -q '^APP_KEY=base64:' .env; then
  echo "[init] APP_KEY missing, generating..."
  php artisan key:generate --force
fi

echo "[3/7] Running Laravel Composer scripts..."
"$COMPOSER_BIN" run-script post-autoload-dump --no-interaction

echo "[4/7] Running migrations..."
php artisan migrate --force

echo "[5/7] Ensuring uploads directories exist..."
mkdir -p public/uploads/proofs public/uploads/tips public/uploads/wins public/uploads/testimonials public/uploads/config
chmod -R 775 public/uploads || true

echo "[6/7] Clearing caches..."
php artisan optimize:clear

echo "[7/7] Rebuilding production caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "[verify] Checking health endpoint routing..."
php artisan route:list | grep -E "api/health|config/vip-config" || true

echo "[verify] Checking platform AI support configuration and live model access..."
php artisan support:doctor --channel=platform --probe-openai

php artisan queue:restart

echo "[verify] Checking support queue worker supervision..."
worker_running=false
worker_scheduled=false
if command -v pgrep >/dev/null 2>&1 && pgrep -af 'artisan queue:(work|listen).*support' >/dev/null; then
  worker_running=true
fi
if command -v crontab >/dev/null 2>&1 && \
   crontab -l 2>/dev/null | grep -Eq 'artisan queue:(work|listen).*(--queue[= ]support|support,default)'; then
  worker_scheduled=true
fi
if [[ "$worker_running" != true && "$worker_scheduled" != true ]]; then
  echo "[error] No running or cron-supervised worker was found for the support queue."
  echo "Configure Supervisor/cPanel Process Manager, or add the support queue cron from SUPPORT_SETUP.md."
  exit 1
fi

echo ""
echo "Deploy complete."
echo "=========================================="
