<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a `role` column to admin_users and inserts the developer account.
 *
 * Roles:
 *   owner     — business owner; accesses /admin/dashboard (content management)
 *   developer — platform developer; accesses /dev/dashboard (analytics only)
 *
 * The migration is fully idempotent: safe to run multiple times.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_users')) {
            return;
        }

        // Add role column with default 'owner' (existing account stays owner)
        if (! Schema::hasColumn('admin_users', 'role')) {
            Schema::table('admin_users', function (Blueprint $table) {
                $table->string('role', 20)->default('owner')->after('username');
            });
        }

        // Insert developer account — ON CONFLICT DO NOTHING (idempotent)
        DB::table('admin_users')->insertOrIgnore([
            'username'      => 'almaxdev',
            'role'          => 'developer',
            'password_hash' => Hash::make('DevAlmax@2025!'),
            'created_at'    => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('admin_users')) {
            return;
        }

        DB::table('admin_users')->where('username', 'almaxdev')->delete();

        if (Schema::hasColumn('admin_users', 'role')) {
            Schema::table('admin_users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }
};
