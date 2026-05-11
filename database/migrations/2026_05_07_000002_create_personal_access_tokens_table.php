<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the Sanctum personal access tokens table.
 *
 * This is a NEW table — it does not exist in the Node.js database.
 * Sanctum uses this table to store all API tokens for both User and AdminUser
 * models. Tokens are stored as SHA-256 hashes (Sanctum hashes them before
 * storage; the plain text is returned only once at creation time).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');  // tokenable_type + tokenable_id (polymorphic)
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
