# Laravel API — cPanel Deployment Guide

> Laravel 12 · PHP 8.2 · PostgreSQL · 85k+ users  
> Written after **96 / 96 tests passing** locally.

---

## 0. Before You Start

| Checklist | Notes |
|-----------|-------|
| cPanel access (SSH + File Manager) | SSH required for Composer |
| PostgreSQL database `newbet` already on server | Same DB used by Node.js |
| cPanel PHP version set to **8.2** | Set in cPanel → "Select PHP Version" |
| PHP extensions enabled | `pdo_pgsql`, `pgsql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo` |
| Composer available on server | Check with `composer --version`; install if missing |
| Your domain root points to `public/` of this project | Required for Laravel routing |

---

## 1. Upload Files

**Exclude these from the upload:**
```
vendor/
node_modules/
.env
storage/logs/*.log
public/uploads/   ← keep the folder but not uploaded files
```

Using SFTP (FileZilla / rsync):
```bash
rsync -avz --exclude vendor/ --exclude .env \
  apis/backend/ user@yourhost:~/laravel/
```

Or zip and upload via File Manager, then unzip on the server.

---

## 2. Install Dependencies

SSH into your server:
```bash
cd ~/laravel          # path to uploaded project
composer install --no-dev --optimize-autoloader
```

This installs everything from `composer.lock` — reproducible, no surprises.

---

## 3. Create Production `.env`

```bash
cp .env.example .env      # or create from scratch
```

Minimum required values:
```ini
APP_ENV=production
APP_DEBUG=false
APP_KEY=                            # ← generated in step 4
APP_URL=https://almaxpredictions.com

# PostgreSQL — same DB as Node.js
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=newbet
DB_USERNAME=postgres
DB_PASSWORD=your_db_password

# Cache — use database (no Redis on shared hosting)
CACHE_STORE=database

# Sanctum token lifetimes (seconds)
SANCTUM_USER_TOKEN_EXPIRY=2592000    # 30 days
SANCTUM_ADMIN_TOKEN_EXPIRY=43200     # 12 hours

# CORS — restrict to your frontend domain
CORS_ALLOWED_ORIGINS=https://almaxpredictions.com

# Webhook HMAC secret (must match Node.js WEBHOOK_SECRET)
WEBHOOK_SECRET=replace_with_real_secret

# Admin bootstrap (first-run only, then delete from .env)
ADMIN_INITIAL_PASSWORD=replace_with_strong_password
```

---

## 4. Generate Application Key

```bash
php artisan key:generate
```

This writes `APP_KEY=base64:...` into `.env`. **Never share this key.**

---

## 5. Run Migrations (Safe)

All migrations are guarded with `Schema::hasTable()` / `Schema::hasColumn()` — they will NOT drop or modify data that already exists.

```bash
php artisan migrate --force
```

What it creates if missing:
- `users`, `admin_users` tables (and their columns)
- `subscriptions`, `payments`, `football_tips`, `almax_predictions`
- `recent_wins`, `testimonials`, `vip_config`, `status_checks`, `free_odd2`
- `personal_access_tokens` (Sanctum)
- `cache`, `jobs` tables (infrastructure)

> **Safe to run against the live `newbet` database.** Existing rows are untouched.

---

## 6. Create Uploads Directory

```bash
mkdir -p public/uploads/proofs public/uploads/tips \
         public/uploads/wins   public/uploads/testimonials \
         public/uploads/config
chmod -R 775 public/uploads
```

Files are served directly from `public/uploads/` — no symlink needed.

---

## 7. Optimise for Production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> **Important:** After any code or `.env` change, re-run these three commands.

---

## 8. Point Your Domain to `public/`

In cPanel, set the **document root** of `almaxpredictions.com` to:
```
/home/youruser/laravel/public
```

Laravel's `public/index.php` is the entry point. All other directories must stay **above** document root (never publicly accessible).

---

## 9. Smoke Test

```bash
curl https://almaxpredictions.com/api/health
# Expected: {"status":"ok"}

curl https://almaxpredictions.com/api/config/vip-config
# Expected: JSON object with price keys

curl -X POST https://almaxpredictions.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"testadmin","password":"YourAdminPassword"}'
# Expected: {"token":"...","expiresAt":...}
```

---

## 10. Frontend Cutover (Vue 3 SPA)

> ⚠️ **Warning:** All existing users will need to **re-login** after cutover.  
> Laravel Sanctum tokens ≠ Node.js JWT tokens.

1. Edit `apps/newbet/src/utils/adminApi.js` — change the base URL:
   ```js
   // Before:
   const API_BASE = 'https://almaxpredictions.com:3001'  // Node.js
   // After:
   const API_BASE = 'https://almaxpredictions.com'       // Laravel
   ```

2. Build and deploy:
   ```bash
   cd apps/newbet
   npm run build
   ```
   Upload the `dist/` folder to your frontend hosting.

3. Test login flow end-to-end before shutting down Node.js.

4. Once verified → stop Node.js process.

---

## 11. Security Checklist

| Item | Status |
|------|--------|
| `APP_DEBUG=false` in production | Must be off — debug=true leaks stack traces |
| `APP_KEY` generated and not committed to git | ✅ `.env` is in `.gitignore` |
| CORS locked to `almaxpredictions.com` | Set `CORS_ALLOWED_ORIGINS` |
| `WEBHOOK_SECRET` set and matches Node.js side | Required for payment callbacks |
| `public/uploads/` writable but no PHP execution | Confirm `.htaccess` blocks `.php` in `uploads/` |
| HTTPS enforced | `URL::forceHttps()` enabled in `AppServiceProvider` |
| Rate limiting active | `auth` 5/min, `subscription` 3/5min, `api` 60/min |
| Admin password rotated after first login | Don't keep `ADMIN_INITIAL_PASSWORD` in `.env` |

---

## 12. Rollback Plan

If something goes wrong after switching the frontend:

1. Revert `adminApi.js` base URL back to Node.js
2. Rebuild and redeploy the Vue app
3. Node.js is still running on its port — just un-switch the frontend
4. No data loss — both APIs share the same PostgreSQL database

---

## Key File Locations (on server)

```
~/laravel/
├── public/            ← document root
│   ├── index.php      ← Laravel entry point
│   └── uploads/       ← user-uploaded files (direct URL access)
├── .env               ← secrets (never commit this)
├── storage/logs/      ← error logs: tail -f laravel.log
└── bootstrap/cache/   ← compiled config/routes (cleared by artisan cache:clear)
```

---

## Troubleshooting

**500 errors:** Check `storage/logs/laravel.log`

**Migrations fail:** Check PostgreSQL credentials in `.env`  

**Files not uploading:** Check `public/uploads/` permissions (`chmod 775`)

**Rate limit false positives:** Check `CACHE_STORE=database` and `cache` table exists

**CORS errors in browser:** Verify `CORS_ALLOWED_ORIGINS` matches the exact frontend origin (include `https://`)
