<?php

namespace Database\Seeders;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class OwnerDashboardDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Owner dashboard demo data is restricted to local and testing environments.');
            return;
        }

        $names = ['Amina Kato', 'Daniel Okello', 'Sarah Namusoke', 'Brian Mugisha', 'Ruth Atim', 'Joel Ssemanda', 'Patricia Nakiwala', 'Isaac Ouma', 'Lydia Akello', 'Moses Kiggundu'];
        $plans = ['daily', 'weekly', 'monthly', 'special'];
        $statuses = ['confirmed', 'confirmed', 'pending', 'confirmed', 'failed', 'confirmed'];
        $timezone = (string) config('app.timezone', 'Africa/Kampala');

        foreach (range(0, 35) as $index) {
            $phone = '0709'.str_pad((string) (810000 + $index), 6, '0', STR_PAD_LEFT);
            $user = User::updateOrCreate(['phone' => $phone], [
                'username' => $names[$index % count($names)].' '.($index + 1),
                'password_hash' => Hash::make('DemoPass123'),
                'scam_warning' => $index === 8,
                'blacklisted' => $index === 17,
            ]);
            $status = $statuses[$index % count($statuses)];
            $createdAt = Carbon::now($timezone)->subDays(intdiv($index, 12))->setTime(8 + ($index % 10), ($index * 7) % 60);
            $plan = $plans[$index % count($plans)];
            Payment::updateOrCreate(['payment_reference' => 'DEMO-ALX-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT)], [
                'user_id' => $user->id,
                'amount' => [10000, 25000, 50000, 18000][$index % 4],
                'plan_type' => $plan,
                'payment_method' => $index % 2 ? 'airtel' : 'mtn',
                'provider' => $index % 4 === 0 ? 'relworx' : 'jpesa',
                'phone' => $phone,
                'status' => $status,
                'transaction_id' => $status === 'confirmed' ? 'DEMO-TX-'.(104000 + $index) : null,
                'confirmed_at' => $status === 'confirmed' ? $createdAt : null,
                'failed_at' => $status === 'failed' ? $createdAt : null,
                'failure_reason' => $status === 'failed' ? 'The mobile money provider declined the collection attempt.' : null,
                'created_at' => $createdAt,
            ]);
        }
    }
}
