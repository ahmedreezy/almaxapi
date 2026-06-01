<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'agent_commission_amount')) {
                $table->decimal('agent_commission_amount', 12, 2)->nullable()->after('transaction_id');
            }
            if (! Schema::hasColumn('payments', 'agent_commission_ratio')) {
                $table->decimal('agent_commission_ratio', 6, 4)->nullable()->after('agent_commission_amount');
            }
            if (! Schema::hasColumn('payments', 'agent_commission_status')) {
                $table->string('agent_commission_status', 30)->nullable()->after('agent_commission_ratio');
            }
            if (! Schema::hasColumn('payments', 'agent_commission_reference')) {
                $table->string('agent_commission_reference', 120)->nullable()->after('agent_commission_status');
            }
            if (! Schema::hasColumn('payments', 'agent_commission_transaction_id')) {
                $table->string('agent_commission_transaction_id', 200)->nullable()->after('agent_commission_reference');
            }
            if (! Schema::hasColumn('payments', 'agent_commission_recipient')) {
                $table->string('agent_commission_recipient', 200)->nullable()->after('agent_commission_transaction_id');
            }
            if (! Schema::hasColumn('payments', 'agent_commission_error')) {
                $table->text('agent_commission_error')->nullable()->after('agent_commission_recipient');
            }
            if (! Schema::hasColumn('payments', 'agent_commission_processed_at')) {
                $table->timestampTz('agent_commission_processed_at')->nullable()->after('agent_commission_error');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $columns = [
                'agent_commission_amount',
                'agent_commission_ratio',
                'agent_commission_status',
                'agent_commission_reference',
                'agent_commission_transaction_id',
                'agent_commission_recipient',
                'agent_commission_error',
                'agent_commission_processed_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
