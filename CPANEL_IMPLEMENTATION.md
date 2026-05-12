# cPanel Implementation Runbook (almaxpredictions.com)

This runbook assumes:
- Laravel API is served from the main domain root.
- Vue build files are published into Laravel public.
- SSH and Git Version Control are enabled in Namecheap cPanel.

## 1) Server folder layout

Using your cPanel base path:
- /home/almaxpredictions.com/almaxapi
- /home/almaxpredictions.com/newbet-side

## 2) Initial backend deploy (Laravel)

Run on server:

```bash
cd /home/almaxpredictions.com
# clone/update your backend repo into almaxapi
cd almaxapi
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

Edit .env with production values:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://almaxpredictions.com

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=newbet
DB_USERNAME=<db_user>
DB_PASSWORD=<db_password>

CACHE_STORE=database
CORS_ALLOWED_ORIGINS=https://almaxpredictions.com
WEBHOOK_SECRET=<shared_secret>
ADMIN_INITIAL_PASSWORD=<strong_temp_password>
```

Then run:

```bash
php artisan key:generate
bash scripts/deploy_cpanel.sh
```

## 3) Point domain document root

In cPanel Domains, set almaxpredictions.com document root to:

- /home/almaxpredictions.com/almaxapi/public

## 4) Initial frontend deploy (Vue)

Run on server:

```bash
cd /home/almaxpredictions.com
# clone/update your frontend repo into newbet-side
cd newbet-side
export LARAVEL_PUBLIC_DIR=/home/almaxpredictions.com/almaxapi/public
npm run deploy:cpanel
```

## 5) Smoke tests

```bash
curl -i https://almaxpredictions.com/api/health
curl -i https://almaxpredictions.com/api/config/vip-config
```

Open in browser:
- https://almaxpredictions.com
- https://almaxpredictions.com/#/admin/login

## 6) Ongoing release commands

Backend release:

```bash
cd /home/almaxpredictions.com/almaxapi
git pull origin main
bash scripts/deploy_cpanel.sh
```

Frontend release:

```bash
cd /home/almaxpredictions.com/newbet-side
git pull origin main
export LARAVEL_PUBLIC_DIR=/home/almaxpredictions.com/almaxapi/public
npm run deploy:cpanel
```

## 7) Rollback

Frontend quick rollback:
- Restore files from latest .frontend_backup_* directory inside Laravel public.

Backend rollback:
- checkout previous commit/tag and rerun bash scripts/deploy_cpanel.sh.
