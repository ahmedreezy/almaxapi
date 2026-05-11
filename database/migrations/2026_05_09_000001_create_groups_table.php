<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Creates the `groups` table which maps payment packages to betslip access.
 * Each group represents a combination of odds_type + plan_type at a given price.
 * When a subscription is confirmed (via payment webhook), the user is assigned
 * the betslip_link and betslip_code from their matching group.
 *
 * Also adds group_id and payment_reference columns to subscriptions,
 * and payment_reference + transaction_id to payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Groups table ─────────────────────────────────────────────────
        if (! Schema::hasTable('groups')) {
            Schema::create('groups', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('odds_type', 20);   // '1.5' | '2' | '5'
                $table->string('plan_type', 20);   // 'daily' | 'weekly'
                $table->decimal('price', 12, 2);
                $table->string('betslip_link', 500)->default('');
                $table->string('betslip_code', 100)->default('');
                $table->timestamps();
            });

            // Seed the 5 default groups
            DB::table('groups')->insert([
                ['name' => 'Odds 1.5 Weekly', 'odds_type' => '1.5', 'plan_type' => 'weekly', 'price' => 45000, 'betslip_link' => '', 'betslip_code' => '', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Odds 2 Daily',    'odds_type' => '2',   'plan_type' => 'daily',  'price' => 10000, 'betslip_link' => '', 'betslip_code' => '', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Odds 2 Weekly',   'odds_type' => '2',   'plan_type' => 'weekly', 'price' => 45000, 'betslip_link' => '', 'betslip_code' => '', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Odds 5 Daily',    'odds_type' => '5',   'plan_type' => 'daily',  'price' => 15000, 'betslip_link' => '', 'betslip_code' => '', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Odds 5 Weekly',   'odds_type' => '5',   'plan_type' => 'weekly', 'price' => 55000, 'betslip_link' => '', 'betslip_code' => '', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        // ─── Add group_id + payment_reference to subscriptions ───────────
        if (Schema::hasTable('subscriptions')) {
            if (! Schema::hasColumn('subscriptions', 'group_id')) {
                Schema::table('subscriptions', function (Blueprint $table) {
                    $table->foreignId('group_id')
                          ->nullable()
                          ->constrained('groups')
                          ->nullOnDelete()
                          ->after('user_id');
                });
            }
            if (! Schema::hasColumn('subscriptions', 'payment_reference')) {
                Schema::table('subscriptions', function (Blueprint $table) {
                    $table->string('payment_reference', 100)->nullable()->unique()->after('status');
                });
            }
        }

        // ─── Add payment_reference + transaction_id to payments ──────────
        if (Schema::hasTable('payments')) {
            if (! Schema::hasColumn('payments', 'payment_reference')) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->string('payment_reference', 100)->nullable()->after('status');
                });
            }
            if (! Schema::hasColumn('payments', 'transaction_id')) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->string('transaction_id', 200)->nullable()->after('payment_reference');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'transaction_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('transaction_id');
            });
        }
        if (Schema::hasColumn('payments', 'payment_reference')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('payment_reference');
            });
        }
        if (Schema::hasColumn('subscriptions', 'payment_reference')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn('payment_reference');
            });
        }
        if (Schema::hasColumn('subscriptions', 'group_id')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropForeign(['group_id']);
                $table->dropColumn('group_id');
            });
        }
        Schema::dropIfExists('groups');
    }
};
