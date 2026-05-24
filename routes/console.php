<?php

use App\Console\Commands\CleanupDeadlineSubscriptions;
use App\Models\AdminUser;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin:rotate-password {--username=admin : Admin username} {--password= : New admin password} {--deactivate-others : Delete all other admin users}', function () {
    $username = trim((string) $this->option('username'));
    $password = (string) $this->option('password');

    if ($username === '') {
        $this->error('Username cannot be empty.');
        return self::FAILURE;
    }

    if ($password === '') {
        $password = (string) $this->secret('Enter new admin password (min 12 chars)');
    }

    if (mb_strlen($password) < 12) {
        $this->error('Password must be at least 12 characters.');
        return self::FAILURE;
    }

    $admin = AdminUser::firstOrNew(['username' => $username]);
    $admin->password_hash = Hash::make($password);
    $admin->save();

    // Revoke all tokens for this admin so old sessions cannot continue.
    $admin->tokens()->delete();

    if ((bool) $this->option('deactivate-others')) {
        $removed = AdminUser::query()
            ->where('id', '!=', $admin->id)
            ->delete();

        if ($removed > 0) {
            $this->warn("Removed {$removed} other admin account(s).");
        }
    }

    $this->info("Admin credentials rotated for username '{$username}'.");
    $this->line('Login endpoint: /api/auth/login');

    return self::SUCCESS;
})->purpose('Create or rotate admin credentials for recovery');

// ── Scheduler ────────────────────────────────────────────────────────────────
// Run hourly: delete stale pending/failed subscriptions whose booking deadline
// has passed (submissions within 2 h of deadline are kept for admin review).
// Ensure crontab on production server contains:
//   * * * * * cd /path/to/almaxapi && php artisan schedule:run >> /dev/null 2>&1
Schedule::command(CleanupDeadlineSubscriptions::class)->hourly();
