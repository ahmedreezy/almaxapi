<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a subscription_deadline column to groups.
 *
 * subscription_deadline TIME nullable:
 *   - null  → no deadline; package is always open for subscriptions
 *   - HH:MM → after this time (server local time) users cannot subscribe to
 *              this package for the rest of the day
 *
 * The deadline is enforced in SubscriptionController::store() and surfaced
 * to the frontend via GroupController::formatGroup() as `subscriptionDeadline`
 * + `isClosed` (computed from current server time).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->time('subscription_deadline')->nullable()->after('special_odds');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('subscription_deadline');
        });
    }
};
