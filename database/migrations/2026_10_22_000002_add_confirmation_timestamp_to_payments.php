<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'confirmed_at')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->timestampTz('confirmed_at')->nullable();
                $table->index('confirmed_at');
            });
        }

        DB::table('payments')
            ->where('status', 'confirmed')
            ->whereNull('confirmed_at')
            ->update([
                'confirmed_at' => DB::raw('COALESCE(agent_commission_processed_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'confirmed_at')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropIndex(['confirmed_at']);
                $table->dropColumn('confirmed_at');
            });
        }
    }
};
