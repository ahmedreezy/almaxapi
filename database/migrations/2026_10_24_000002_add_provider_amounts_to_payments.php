<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('customer_charge_amount', 12, 2)->nullable()->after('amount');
            $table->decimal('provider_fee_estimate', 12, 2)->nullable()->after('customer_charge_amount');
            $table->decimal('provider_fee_actual', 12, 2)->nullable()->after('provider_fee_estimate');
            $table->decimal('provider_net_amount', 12, 2)->nullable()->after('provider_fee_actual');
        });

        DB::table('payments')->whereNull('customer_charge_amount')->update([
            'customer_charge_amount' => DB::raw('amount'),
            'provider_fee_estimate' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'customer_charge_amount',
                'provider_fee_estimate',
                'provider_fee_actual',
                'provider_net_amount',
            ]);
        });
    }
};
