<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('support_message_id')->constrained('support_messages')->cascadeOnDelete();
            $table->string('kind', 20)->index();
            $table->string('disk', 40)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('status', 30)->default('uploaded')->index();
            $table->longText('transcript')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();
        });

        Schema::create('package_releases', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('parent_release_id')->nullable()->constrained('package_releases')->nullOnDelete();
            $table->string('type', 20)->default('paid')->index();
            $table->string('betslip_link', 500)->default('');
            $table->string('betslip_code', 100)->default('');
            $table->timestampTz('published_at')->index();
            $table->timestampTz('cutoff_at')->index();
            $table->string('result', 20)->default('pending')->index();
            $table->timestampTz('result_marked_at')->nullable();
            $table->foreignId('result_marked_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique('parent_release_id');
        });

        Schema::create('package_release_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained('package_releases')->cascadeOnDelete();
            $table->foreignId('source_release_id')->constrained('package_releases')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('granted_at');
            $table->timestampTz('seen_at')->nullable();
            $table->timestampsTz();
            $table->unique(['release_id', 'user_id']);
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 50)->index();
            $table->string('title', 200);
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestampTz('read_at')->nullable()->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('package_release_entitlements');
        Schema::dropIfExists('package_releases');
        Schema::dropIfExists('support_message_attachments');
    }
};
