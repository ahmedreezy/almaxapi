<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 30)->default('whatsapp');
            $table->string('external_id', 80);
            $table->string('phone', 30)->nullable()->index();
            $table->string('display_name', 200)->nullable();
            $table->string('locale', 10)->nullable();
            $table->unsignedSmallInteger('daily_limit_override')->nullable();
            $table->boolean('blocked')->default(false);
            $table->timestampsTz();
            $table->unique(['channel', 'external_id']);
        });

        Schema::create('support_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('contact_id')->constrained('support_contacts')->cascadeOnDelete();
            $table->string('status', 30)->default('open')->index();
            $table->string('mode', 30)->default('ai')->index();
            $table->string('category', 50)->default('other')->index();
            $table->string('sentiment', 30)->default('neutral');
            $table->string('priority', 20)->default('normal')->index();
            $table->text('summary')->nullable();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('last_message_at')->nullable()->index();
            $table->timestampTz('human_requested_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $table->string('direction', 20);
            $table->string('sender_type', 20);
            $table->longText('body');
            $table->string('provider_message_id', 100)->nullable()->unique();
            $table->string('delivery_status', 30)->default('received')->index();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->string('model', 100)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('support_daily_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('support_contacts')->cascadeOnDelete();
            $table->date('usage_date');
            $table->unsignedSmallInteger('replies_reserved')->default(0);
            $table->unsignedSmallInteger('replies_used')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->boolean('limit_notice_sent')->default(false);
            $table->timestampsTz();
            $table->unique(['contact_id', 'usage_date']);
        });

        Schema::create('support_knowledge_articles', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('title', 200);
            $table->text('question');
            $table->longText('answer');
            $table->json('keywords')->nullable();
            $table->string('locale', 10)->default('en')->index();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('support_tool_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('support_messages')->nullOnDelete();
            $table->string('tool_name', 100);
            $table->json('arguments')->nullable();
            $table->json('result')->nullable();
            $table->boolean('successful')->default(true);
            $table->timestampsTz();
        });

        if (! Schema::hasColumn('payments', 'receipt_number')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('receipt_number', 40)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'receipt_number')) {
            Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('receipt_number'));
        }

        Schema::dropIfExists('support_tool_audits');
        Schema::dropIfExists('support_knowledge_articles');
        Schema::dropIfExists('support_daily_usages');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_conversations');
        Schema::dropIfExists('support_contacts');
    }
};
