<?php

use App\Models\AdminUser;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

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
