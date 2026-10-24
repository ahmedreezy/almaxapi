<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropForeign(['subscription_id']);
                $table->dropForeign(['user_id']);
            });
            Schema::table('payments', function (Blueprint $table) {
                $table->unsignedBigInteger('subscription_id')->nullable()->change();
                $table->unsignedBigInteger('user_id')->nullable()->change();
                $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
            Schema::table('payments', function (Blueprint $table) {
                $table->string('failure_category', 30)->nullable()->after('status');
                $table->text('failure_reason')->nullable()->after('failure_category');
                $table->timestampTz('failed_at')->nullable()->after('failure_reason');
                $table->timestampTz('failure_alerted_at')->nullable()->after('failed_at');
                $table->index(['status', 'failure_category', 'failure_alerted_at'], 'payments_failure_alert_idx');
            });

            return;
        }

        // The original Node schema used PostgreSQL's *_fkey names while fresh
        // Laravel installs use *_foreign. Support both production histories.
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_subscription_id_foreign');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_subscription_id_fkey');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_user_id_foreign');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_user_id_fkey');
        DB::statement('ALTER TABLE payments ALTER COLUMN subscription_id DROP NOT NULL');
        DB::statement('ALTER TABLE payments ALTER COLUMN user_id DROP NOT NULL');

        Schema::table('payments', function (Blueprint $table) {
            $table->string('failure_category', 30)->nullable()->after('status');
            $table->text('failure_reason')->nullable()->after('failure_category');
            $table->timestampTz('failed_at')->nullable()->after('failure_reason');
            $table->timestampTz('failure_alerted_at')->nullable()->after('failed_at');

            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'failure_category', 'failure_alerted_at'], 'payments_failure_alert_idx');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropIndex('payments_failure_alert_idx');
                $table->dropColumn(['failure_category', 'failure_reason', 'failed_at', 'failure_alerted_at']);
            });

            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_failure_alert_idx');
            $table->dropColumn(['failure_category', 'failure_reason', 'failed_at', 'failure_alerted_at']);
        });

        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_subscription_id_foreign');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_user_id_foreign');
        DB::statement('DELETE FROM payments WHERE subscription_id IS NULL OR user_id IS NULL');
        DB::statement('ALTER TABLE payments ALTER COLUMN subscription_id SET NOT NULL');
        DB::statement('ALTER TABLE payments ALTER COLUMN user_id SET NOT NULL');

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
