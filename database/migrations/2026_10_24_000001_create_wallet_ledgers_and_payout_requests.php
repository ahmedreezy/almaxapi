<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('beneficiary', 20);
            $table->string('provider', 30);
            $table->string('currency', 3)->default('UGX');
            $table->timestampsTz();
            $table->unique(['beneficiary', 'provider', 'currency']);
        });

        Schema::create('payout_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 50)->unique();
            $table->foreignId('wallet_account_id')->constrained('wallet_accounts')->restrictOnDelete();
            $table->string('beneficiary', 20)->index();
            $table->string('provider', 30)->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('UGX');
            $table->string('destination_msisdn', 30);
            $table->string('status', 30)->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('provider_request_id', 200)->nullable();
            $table->string('transaction_id', 200)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_account_id')->constrained('wallet_accounts')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('payout_request_id')->nullable()->constrained('payout_requests')->nullOnDelete();
            $table->string('entry_type', 30);
            $table->decimal('amount', 12, 2);
            $table->string('idempotency_key', 180)->unique();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['wallet_account_id', 'created_at']);
        });

        $now = now();
        foreach (['owner', 'developer'] as $beneficiary) {
            foreach (['jpesa', 'relworx'] as $provider) {
                DB::table('wallet_accounts')->insert([
                    'beneficiary' => $beneficiary,
                    'provider' => $provider,
                    'currency' => 'UGX',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $accounts = DB::table('wallet_accounts')
            ->get()
            ->keyBy(fn ($row) => $row->beneficiary.'|'.$row->provider);

        DB::table('payments')
            ->where('status', 'confirmed')
            ->orderBy('id')
            ->chunkById(250, function ($payments) use ($accounts, $now) {
                foreach ($payments as $payment) {
                    $provider = strtolower((string) ($payment->provider ?: 'jpesa'));
                    if (! in_array($provider, ['jpesa', 'relworx'], true)) {
                        continue;
                    }

                    $commission = max(0, round((float) ($payment->agent_commission_amount ?? 0), 2));
                    $ownerAmount = max(0, round((float) $payment->amount - $commission, 2));
                    $owner = $accounts->get('owner|'.$provider);
                    if ($owner && $ownerAmount > 0) {
                        DB::table('wallet_ledger_entries')->insertOrIgnore([
                            'wallet_account_id' => $owner->id,
                            'payment_id' => $payment->id,
                            'entry_type' => 'payment_credit',
                            'amount' => $ownerAmount,
                            'idempotency_key' => 'payment:'.$payment->id.':owner',
                            'metadata' => json_encode(['backfilled' => true]),
                            'created_at' => $payment->confirmed_at ?? $payment->created_at ?? $now,
                        ]);
                    }

                    $commissionStatus = strtolower((string) ($payment->agent_commission_status ?? ''));
                    $alreadySettled = in_array($commissionStatus, ['sent', 'completed', 'processing'], true);
                    $developer = $accounts->get('developer|'.$provider);
                    if ($provider === 'relworx' && $developer && $commission > 0 && ! $alreadySettled) {
                        DB::table('wallet_ledger_entries')->insertOrIgnore([
                            'wallet_account_id' => $developer->id,
                            'payment_id' => $payment->id,
                            'entry_type' => 'commission_credit',
                            'amount' => $commission,
                            'idempotency_key' => 'payment:'.$payment->id.':developer',
                            'metadata' => json_encode(['backfilled' => true]),
                            'created_at' => $payment->confirmed_at ?? $payment->created_at ?? $now,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('payout_requests');
        Schema::dropIfExists('wallet_accounts');
    }
};
