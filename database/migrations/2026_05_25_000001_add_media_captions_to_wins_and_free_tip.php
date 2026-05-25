<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('recent_wins') && ! Schema::hasColumn('recent_wins', 'caption')) {
            Schema::table('recent_wins', function (Blueprint $table) {
                $table->text('caption')->default('');
            });
        }

        if (Schema::hasTable('free_odd2') && ! Schema::hasColumn('free_odd2', 'caption')) {
            Schema::table('free_odd2', function (Blueprint $table) {
                $table->text('caption')->default('');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('recent_wins') && Schema::hasColumn('recent_wins', 'caption')) {
            Schema::table('recent_wins', function (Blueprint $table) {
                $table->dropColumn('caption');
            });
        }

        if (Schema::hasTable('free_odd2') && Schema::hasColumn('free_odd2', 'caption')) {
            Schema::table('free_odd2', function (Blueprint $table) {
                $table->dropColumn('caption');
            });
        }
    }
};
