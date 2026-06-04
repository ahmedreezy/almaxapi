<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'scam_warning')) {
                $table->boolean('scam_warning')->default(false)->after('password_hash');
            }
            if (! Schema::hasColumn('users', 'blacklisted')) {
                $table->boolean('blacklisted')->default(false)->after('scam_warning');
            }
            if (! Schema::hasColumn('users', 'blacklisted_at')) {
                $table->timestampTz('blacklisted_at')->nullable()->after('blacklisted');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (['blacklisted_at', 'blacklisted', 'scam_warning'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
