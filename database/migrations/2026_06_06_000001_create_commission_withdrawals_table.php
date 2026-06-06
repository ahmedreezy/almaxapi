<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commission_withdrawals')) {
            return;
        }

        Schema::create('commission_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 12, 2);
            $table->string('reference', 200)->nullable();
            $table->string('wallet_account', 200)->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('withdrawn_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('withdrawn_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_withdrawals');
    }
};
