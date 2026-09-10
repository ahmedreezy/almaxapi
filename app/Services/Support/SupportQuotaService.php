<?php

namespace App\Services\Support;

use App\Models\SupportContact;
use App\Models\SupportDailyUsage;
use Illuminate\Support\Facades\DB;

class SupportQuotaService
{
    public function reserve(SupportContact $contact): bool
    {
        $date = $this->usageDate();

        DB::table('support_daily_usages')->insertOrIgnore([
            'contact_id' => $contact->id,
            'usage_date' => $date,
            'replies_reserved' => 0,
            'replies_used' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'limit_notice_sent' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::transaction(function () use ($contact, $date) {
            $usage = SupportDailyUsage::where('contact_id', $contact->id)
                ->whereDate('usage_date', $date)
                ->lockForUpdate()
                ->firstOrFail();
            $limit = $contact->daily_limit_override ?? max(1, (int) config('support.daily_reply_limit', 10));

            if (($usage->replies_used + $usage->replies_reserved) >= $limit) {
                return false;
            }

            $usage->increment('replies_reserved');

            return true;
        });
    }

    public function consume(SupportContact $contact, int $inputTokens = 0, int $outputTokens = 0): void
    {
        $this->updateReservation($contact, function (SupportDailyUsage $usage) use ($inputTokens, $outputTokens) {
            $usage->replies_reserved = max(0, $usage->replies_reserved - 1);
            $usage->replies_used++;
            $usage->input_tokens += max(0, $inputTokens);
            $usage->output_tokens += max(0, $outputTokens);
        });
    }

    public function release(SupportContact $contact, int $inputTokens = 0, int $outputTokens = 0): void
    {
        $this->updateReservation($contact, function (SupportDailyUsage $usage) use ($inputTokens, $outputTokens) {
            $usage->replies_reserved = max(0, $usage->replies_reserved - 1);
            $usage->input_tokens += max(0, $inputTokens);
            $usage->output_tokens += max(0, $outputTokens);
        });
    }

    public function claimLimitNotice(SupportContact $contact): bool
    {
        return DB::transaction(function () use ($contact) {
            $usage = SupportDailyUsage::where('contact_id', $contact->id)
                ->whereDate('usage_date', $this->usageDate())
                ->lockForUpdate()
                ->first();

            if (! $usage || $usage->limit_notice_sent) {
                return false;
            }

            $usage->update(['limit_notice_sent' => true]);

            return true;
        });
    }

    private function updateReservation(SupportContact $contact, callable $callback): void
    {
        DB::transaction(function () use ($contact, $callback) {
            $usage = SupportDailyUsage::where('contact_id', $contact->id)
                ->whereDate('usage_date', $this->usageDate())
                ->lockForUpdate()
                ->firstOrFail();
            $callback($usage);
            $usage->save();
        });
    }

    private function usageDate(): string
    {
        return now(config('support.timezone', 'Africa/Kampala'))->toDateString();
    }
}
