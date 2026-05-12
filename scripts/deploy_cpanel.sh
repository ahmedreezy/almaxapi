#!/usr/bin/env bash
set -euo pipefail

echo ""
echo "=========================================="
echo " Laravel cPanel deploy: $(date)"
echo "=========================================="

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

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
composer install --no-dev --optimize-autoloader --no-interaction

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

echo ""
echo "Deploy complete."
echo "=========================================="
