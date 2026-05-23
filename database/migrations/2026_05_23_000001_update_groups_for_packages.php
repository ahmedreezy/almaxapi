<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the groups table into full alignment with the 5 required VIP packages:
 *
 *  1. Special Odds      — admin-set price + odds (is_special=true, hidden until priced)
 *  2. Daily Odd 5       — 15,000 UGX / daily
 *  3. Weekly Odd 5      — 60,000 UGX / weekly
 *  4. Big Staker Weekly Odd 2 — 50,000 UGX / weekly
 *  5. Monthly Odd 1.5   — 45,000 UGX / monthly
 *
 * Also adds schema columns required for admin-controlled special odds:
 *   is_special     — marks a group as the flexible admin-priced package
 *   is_active      — admin toggle: hide/show a group from public listing
 *   special_price  — today's admin-set price for the special odds group
 *   special_odds   — admin-set odds description (e.g. "3.5") for special group
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── 1. Add new columns (idempotent) ─────────────────────────────
        Schema::table('groups', function (Blueprint $table) {
            if (! Schema::hasColumn('groups', 'is_special')) {
                $table->boolean('is_special')->default(false)->after('betslip_code');
            }
            if (! Schema::hasColumn('groups', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_special');
            }
            if (! Schema::hasColumn('groups', 'special_price')) {
                $table->decimal('special_price', 12, 2)->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('groups', 'special_odds')) {
                $table->string('special_odds', 50)->nullable()->after('special_price');
            }
        });

        // ─── 2. Rename + correct existing groups ──────────────────────────
        // Daily Odd 5: exists correctly at 15,000 — just rename to canonical form
        DB::table('groups')
            ->where('odds_type', '5')->where('plan_type', 'daily')
            ->update(['name' => 'Daily Odd 5', 'is_active' => true]);

        // Weekly Odd 5: fix price 55,000 → 60,000
        DB::table('groups')
            ->where('odds_type', '5')->where('plan_type', 'weekly')
            ->update(['name' => 'Weekly Odd 5', 'price' => 60000, 'is_active' => true]);

        // Big Staker Weekly Odd 2: fix price 45,000 → 50,000
        DB::table('groups')
            ->where('odds_type', '2')->where('plan_type', 'weekly')
            ->update(['name' => 'Big Staker Weekly Odd 2', 'price' => 50000, 'is_active' => true]);

        // Monthly Odd 1.5: was seeded as weekly — change to monthly
        DB::table('groups')
            ->where('odds_type', '1.5')
            ->update(['name' => 'Monthly Odd 1.5', 'plan_type' => 'monthly', 'price' => 45000, 'is_active' => true]);

        // Odds 2 Daily: not in requirements — soft-hide (preserves FK references)
        DB::table('groups')
            ->where('odds_type', '2')->where('plan_type', 'daily')
            ->update(['is_active' => false]);

        // ─── 3. Insert Special Odds group if missing ──────────────────────
        $exists = DB::table('groups')->where('is_special', true)->exists();
        if (! $exists) {
            DB::table('groups')->insert([
                'name'          => 'Special Odds',
                'odds_type'     => 'special',
                'plan_type'     => 'special',
                'price'         => 0,
                'betslip_link'  => '',
                'betslip_code'  => '',
                'is_special'    => true,
                'is_active'     => false,   // hidden until admin sets a price
                'special_price' => null,
                'special_odds'  => null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Restore prices to original seeded values (best-effort rollback)
        DB::table('groups')
            ->where('odds_type', '5')->where('plan_type', 'daily')
            ->update(['name' => 'Odds 5 Daily']);

        DB::table('groups')
            ->where('odds_type', '5')->where('plan_type', 'weekly')
            ->update(['name' => 'Odds 5 Weekly', 'price' => 55000]);

        DB::table('groups')
            ->where('odds_type', '2')->where('plan_type', 'weekly')
            ->update(['name' => 'Odds 2 Weekly', 'price' => 45000]);

        DB::table('groups')
            ->where('odds_type', '1.5')
            ->update(['name' => 'Odds 1.5 Weekly', 'plan_type' => 'weekly']);

        DB::table('groups')
            ->where('odds_type', '2')->where('plan_type', 'daily')
            ->update(['is_active' => true]);

        // Remove Special Odds group
        DB::table('groups')->where('is_special', true)->delete();

        // Drop added columns
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumnIfExists('special_odds');
            $table->dropColumnIfExists('special_price');
            $table->dropColumnIfExists('is_active');
            $table->dropColumnIfExists('is_special');
        });
    }
};
