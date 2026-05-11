<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates all core business tables for the Almax Predictions platform.
 *
 * Every table creation is wrapped in Schema::hasTable() so this migration
 * is completely safe to run against the live production database that was
 * previously managed by the Node.js server. If a table already exists,
 * its creation is skipped silently.
 *
 * Tables covered:
 *   admin_users, users, subscriptions, payments, football_tips,
 *   almax_predictions, recent_wins, testimonials, vip_config,
 *   status_checks, free_odd2
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Admin users ─────────────────────────────────────────────────
        if (! Schema::hasTable('admin_users')) {
            Schema::create('admin_users', function (Blueprint $table) {
                $table->id();
                $table->string('username', 100)->unique();
                $table->string('password_hash', 255);
                $table->timestampTz('created_at')->useCurrent();
            });
        }

        // ─── Regular users (VIP subscribers) ────────────────────────────
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('username', 200);
                $table->string('phone', 30)->unique();
                $table->string('password_hash', 255)->nullable();
                $table->timestampTz('created_at')->useCurrent();
            });
        }

        // ─── Subscriptions ───────────────────────────────────────────────
        if (! Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('plan_type', 20);   // 'daily' | 'weekly'
                $table->string('odds_type', 20)->default('2');  // '1.5' | '2' | '5'
                $table->string('payment_method', 20);  // 'mtn' | 'airtel'
                $table->string('phone', 30)->default('');
                $table->decimal('amount', 12, 2);
                $table->string('status', 20)->default('pending');
                $table->string('proof_url', 500)->nullable();
                $table->text('rejection_reason')->nullable();
                $table->string('betslip_link', 500)->default('');
                $table->string('betslip_code', 100)->default('');
                $table->string('secret_code_hash', 255)->default('');
                $table->timestampTz('started_at')->nullable();
                $table->timestampTz('expires_at')->nullable();
                $table->timestampTz('created_at')->useCurrent();
            });
        }

        // ─── Payments ────────────────────────────────────────────────────
        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->decimal('amount', 12, 2);
                $table->string('plan_type', 20)->nullable();
                $table->string('payment_method', 20)->nullable();
                $table->string('phone', 30)->default('');
                $table->string('status', 20)->default('pending');
                $table->timestampTz('created_at')->useCurrent();
            });
        }

        // ─── Football tips ────────────────────────────────────────────────
        if (! Schema::hasTable('football_tips')) {
            Schema::create('football_tips', function (Blueprint $table) {
                $table->id();
                $table->string('home', 200);
                $table->string('away', 200);
                $table->string('competition', 200);
                $table->string('kickoff', 50);
                $table->integer('win_prob')->default(75);
                $table->string('kit_color', 20)->default('#FFD700');
                $table->string('kit_number', 10)->default('10');
                $table->text('prediction')->default('');
                $table->string('accent', 20)->default('#FFD700');
                $table->string('image_url', 500)->default('');
                $table->text('caption')->default('');
                $table->timestampTz('created_at')->useCurrent();
            });
        }

        // ─── Almax predictions ────────────────────────────────────────────
        if (! Schema::hasTable('almax_predictions')) {
            Schema::create('almax_predictions', function (Blueprint $table) {
                $table->id();
                $table->string('home', 200);
                $table->string('away', 200);
                $table->string('competition', 200);
                $table->string('kickoff', 50);
                $table->string('tip', 200);
                $table->string('odds', 50)->default('');
                $table->string('result', 50)->default('pending');
                $table->timestampTz('created_at')->useCurrent();
            });
        }

        // ─── Recent wins ──────────────────────────────────────────────────
        if (! Schema::hasTable('recent_wins')) {
            Schema::create('recent_wins', function (Blueprint $table) {
                $table->id();
                $table->string('bet_type', 100);
                $table->string('date', 50);
                $table->string('staked', 50);
                $table->string('returned', 50);
                $table->string('odds', 50);
                $table->string('member_name', 200)->default('');
                $table->string('image_url', 500)->default('');
                $table->timestampTz('created_at')->useCurrent();
            });
        }

        // ─── Testimonials ─────────────────────────────────────────────────
        if (! Schema::hasTable('testimonials')) {
            Schema::create('testimonials', function (Blueprint $table) {
                $table->id();
                $table->text('caption')->default('');
                $table->string('member_name', 200)->default('');
                $table->string('image_url', 500)->default('');
                $table->timestampTz('created_at')->useCurrent();
            });
        }

        // ─── VIP config (key-value) ───────────────────────────────────────
        if (! Schema::hasTable('vip_config')) {
            Schema::create('vip_config', function (Blueprint $table) {
                $table->string('key', 100)->primary();
                $table->text('value')->default('');
            });
        }

        // ─── Status checks (notification log) ────────────────────────────
        if (! Schema::hasTable('status_checks')) {
            Schema::create('status_checks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->string('phone', 30)->nullable();
                $table->string('username', 200)->nullable();
                $table->string('plan_type', 20)->nullable();
                $table->string('sub_status', 20)->nullable();
                $table->boolean('is_read')->default(false);
                $table->timestampTz('created_at')->useCurrent();
            });
        }

        // ─── Free weekly odd (single-row config) ─────────────────────────
        if (! Schema::hasTable('free_odd2')) {
            Schema::create('free_odd2', function (Blueprint $table) {
                $table->integer('id')->primary()->default(1);
                $table->string('team_a', 200)->default('Team A');
                $table->string('team_b', 200)->default('Team B');
                $table->string('pick', 200)->default('Over 2.5 Goals');
                $table->string('odd', 20)->default('2.00');
                $table->string('time', 20)->default('20:45');
                $table->string('competition', 200)->default('Premier League');
                $table->string('image_url', 500)->default('');
                $table->timestampTz('updated_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        // Drop in reverse order of foreign key dependencies
        Schema::dropIfExists('status_checks');
        Schema::dropIfExists('free_odd2');
        Schema::dropIfExists('vip_config');
        Schema::dropIfExists('testimonials');
        Schema::dropIfExists('recent_wins');
        Schema::dropIfExists('almax_predictions');
        Schema::dropIfExists('football_tips');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('users');
        Schema::dropIfExists('admin_users');
    }
};
