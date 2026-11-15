<?php

namespace Database\Seeders;

use App\Models\FootballTip;
use App\Models\Group;
use Illuminate\Database\Seeder;

class LocalCatalogDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Catalog demo data is restricted to local and testing environments.');
            return;
        }

        $packages = [
            'daily-five' => $this->upsertGroup(
                ['odds_type' => '5', 'plan_type' => 'daily'],
                ['name' => 'Daily Odd 5', 'price' => 15000, 'betslip_code' => 'DEMO-D5']
            ),
            'weekly-five' => $this->upsertGroup(
                ['odds_type' => '5', 'plan_type' => 'weekly'],
                ['name' => 'Weekly Odd 5', 'price' => 60000, 'betslip_code' => 'DEMO-W5']
            ),
            'big-staker' => $this->upsertGroup(
                ['odds_type' => '2', 'plan_type' => 'weekly'],
                ['name' => 'Big Staker Weekly Odd 2', 'price' => 50000, 'betslip_code' => 'DEMO-BS2']
            ),
            'monthly' => $this->upsertGroup(
                ['odds_type' => '1.5', 'plan_type' => 'monthly'],
                ['name' => 'Monthly Odd 1.5', 'price' => 45000, 'betslip_code' => 'DEMO-M15']
            ),
            'special' => $this->upsertGroup(
                ['odds_type' => 'special', 'plan_type' => 'special'],
                [
                    'name' => 'Special Odds',
                    'price' => 0,
                    'betslip_code' => 'DEMO-SPECIAL',
                    'is_special' => true,
                    'special_price' => 18000,
                    'special_odds' => '4.35',
                ]
            ),
        ];

        $tips = [
            ['key' => 'daily-five', 'home' => 'Arsenal', 'away' => 'Brentford', 'competition' => 'Premier League', 'kickoff' => '18:30', 'win_prob' => 78, 'accent' => '#38bdf8', 'caption' => 'Today’s carefully selected Daily Odd 5 accumulator'],
            ['key' => 'weekly-five', 'home' => 'Atalanta', 'away' => 'Lazio', 'competition' => 'Serie A', 'kickoff' => '21:45', 'win_prob' => 74, 'accent' => '#34d399', 'caption' => 'Weekly Odd 5 value selections for consistent players'],
            ['key' => 'big-staker', 'home' => 'Girona', 'away' => 'Getafe', 'competition' => 'La Liga', 'kickoff' => '20:00', 'win_prob' => 81, 'accent' => '#fb7185', 'caption' => 'Big Staker Weekly Odd 2 high-confidence selection'],
            ['key' => 'monthly', 'home' => 'Bayern Munich', 'away' => 'Mainz', 'competition' => 'Bundesliga', 'kickoff' => '19:30', 'win_prob' => 84, 'accent' => '#a78bfa', 'caption' => 'Monthly Odd 1.5 package preview'],
            ['key' => 'special', 'home' => 'Marseille', 'away' => 'Lille', 'competition' => 'Ligue 1', 'kickoff' => '22:00', 'win_prob' => 76, 'accent' => '#f59e0b', 'caption' => 'Today’s Special Odds package at 4.35 total odds'],
        ];

        foreach ($tips as $tip) {
            $package = $packages[$tip['key']];
            unset($tip['key']);

            FootballTip::updateOrCreate(
                ['home' => $tip['home'], 'away' => $tip['away'], 'competition' => $tip['competition']],
                array_merge($tip, [
                    'group_id' => $package->id,
                    'prediction' => $package->name,
                    'kit_color' => $tip['accent'],
                    'kit_number' => (string) $package->odds_type,
                    'image_url' => '',
                    'created_at' => now(),
                ])
            );
        }
    }

    private function upsertGroup(array $identity, array $attributes): Group
    {
        return Group::updateOrCreate($identity, array_merge([
            'betslip_link' => '',
            'is_special' => false,
            'is_active' => true,
            'special_price' => null,
            'special_odds' => null,
            'subscription_deadline' => null,
            'photo_url' => '',
        ], $attributes));
    }
}
