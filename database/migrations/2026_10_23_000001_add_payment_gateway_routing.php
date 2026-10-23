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
            $table->string('provider', 30)->default('jpesa')->index();
            $table->string('provider_request_id', 200)->nullable();
            $table->string('agent_commission_provider', 30)->nullable();
            $table->string('agent_commission_provider_request_id', 200)->nullable();
        });

        DB::table('payments')->whereNull('provider')->update(['provider' => 'jpesa']);

        Schema::create('payment_gateway_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('active_provider', 30)->default('jpesa');
            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('payment_gateway_switches', function (Blueprint $table) {
            $table->id();
            $table->string('from_provider', 30);
            $table->string('to_provider', 30);
            $table->foreignId('changed_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::table('payment_gateway_settings')->insert([
            'id' => 1,
            'active_provider' => strtolower((string) config('services.payment_gateway.default', 'jpesa')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_switches');
        Schema::dropIfExists('payment_gateway_settings');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['provider']);
            $table->dropColumn([
                'provider',
                'provider_request_id',
                'agent_commission_provider',
                'agent_commission_provider_request_id',
            ]);
        });
    }
};
