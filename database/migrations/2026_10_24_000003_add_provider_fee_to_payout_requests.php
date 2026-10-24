<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_requests', function (Blueprint $table) {
            $table->string('client_token', 100)->nullable()->unique()->after('reference');
            $table->decimal('provider_fee_actual', 12, 2)->nullable()->after('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('payout_requests', function (Blueprint $table) {
            $table->dropUnique(['client_token']);
            $table->dropColumn(['client_token', 'provider_fee_actual']);
        });
    }
};
