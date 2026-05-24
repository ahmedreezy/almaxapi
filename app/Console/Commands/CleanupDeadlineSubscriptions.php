<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled command: runs hourly via `php artisan schedule:run`.
 *
 * Rule:
 *   – When a group's subscription_deadline passes today, delete all PENDING and
 *     FAILED subscriptions for that group that were created MORE than 2 hours
 *     before the deadline (they are clearly abandoned).
 *   – Subscriptions submitted within 2 hours before the deadline are KEPT for
 *     admin review — those users likely paid but the system didn't auto-confirm.
 *
 * The same logic runs inline when an admin opens the subscriptions panel
 * (SubscriptionController::index calls cleanupDeadlineSubscriptions).
 */
class CleanupDeadlineSubscriptions extends Command
{
    protected $signature   = 'subscriptions:cleanup-deadline';
    protected $description = 'Delete stale pending/failed subscriptions past booking deadline (keeps last-2h window for admin review)';

    public function handle(): int
    {
        $groups  = Group::whereNotNull('subscription_deadline')->get();
        $deleted = 0;

        foreach ($groups as $group) {
            $deadlineToday = now()->startOfDay()->setTimeFromTimeString($group->subscription_deadline);

            // Skip groups whose deadline hasn't passed yet today
            if (now()->lt($deadlineToday)) {
                continue;
            }

            // Anything created before (deadline − 2h) is auto-deleted
            $cutoff = $deadlineToday->copy()->subHours(2);

            $subs = Subscription::with(['payment'])
                ->where('group_id', $group->id)
                ->whereIn('status', ['pending', 'failed'])
                ->where('created_at', '<', $cutoff)
                ->get();

            foreach ($subs as $sub) {
                DB::transaction(function () use ($sub) {
                    $sub->payment()->delete();
                    $sub->delete();
                });
                $deleted++;
            }
        }

        $msg = "subscriptions:cleanup-deadline: removed {$deleted} stale subscription(s).";
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }
}
