<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\SupportConversation;
use App\Services\MobileMoneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Developer-only analytics dashboard data.
 *
 * Protected by EnsureDevToken middleware (role:developer ability required).
 * The owner (role:admin) cannot access this endpoint.
 *
 * GET /api/analytics/developer
 */
class DeveloperAnalyticsController extends Controller
{
    private const SUPPORT_CATEGORIES = [
        'payment' => 'Payments',
        'subscription' => 'Subscriptions',
        'account' => 'Accounts',
        'prediction_content' => 'Prediction content',
        'technical' => 'Technical issues',
        'complaint' => 'Complaints',
        'suggestion' => 'Suggestions',
        'other' => 'Other',
    ];

    private function trackedCommissionBase()
    {
        return DB::table('payments')
            ->where('status', 'confirmed')
            ->whereNotNull('agent_commission_amount')
            ->where('agent_commission_amount', '>', 0);
    }

    private function normaliseCommissionStatus(?string $status): string
    {
        return match (strtolower(trim((string) $status))) {
            'sent', 'completed' => 'completed',
            'processing' => 'processing',
            'failed', 'failure', 'error' => 'failed',
            default => 'pending',
        };
    }

    private function commissionWalletAccount(): string
    {
        $commission = config('services.mobile_money.agent_commission', []);
        $recipientType = strtolower((string) ($commission['recipient_type'] ?? 'business'));
        $email = trim((string) ($commission['recipient_email'] ?? ''));
        $mobile = trim((string) ($commission['recipient_mobile'] ?? ''));

        return $recipientType === 'mobile'
            ? ($mobile ?: $email)
            : ($email ?: $mobile);
    }

    private function commissionWalletReceived(): float
    {
        return (float) $this->trackedCommissionBase()
            ->get(['agent_commission_amount', 'agent_commission_status'])
            ->filter(fn ($row) => $this->normaliseCommissionStatus($row->agent_commission_status) === 'completed')
            ->sum('agent_commission_amount');
    }

    private function applyConfirmationWindow($query, Carbon $start, ?Carbon $end = null)
    {
        return $query->where(function ($window) use ($start, $end) {
            $window->where(function ($confirmed) use ($start, $end) {
                $confirmed->whereNotNull('confirmed_at')
                    ->where('confirmed_at', '>=', $start);
                if ($end) {
                    $confirmed->where('confirmed_at', '<', $end);
                }
            })->orWhere(function ($legacy) use ($start, $end) {
                $legacy->whereNull('confirmed_at')
                    ->where('created_at', '>=', $start);
                if ($end) {
                    $legacy->where('created_at', '<', $end);
                }
            });
        });
    }

    private function commissionWithdrawals()
    {
        if (! Schema::hasTable('commission_withdrawals')) {
            return collect();
        }

        return DB::table('commission_withdrawals')
            ->orderByDesc('withdrawn_at')
            ->orderByDesc('id')
            ->get(['id', 'amount', 'reference', 'wallet_account', 'note', 'withdrawn_at', 'created_at']);
    }

    private function serialiseCommissionWithdrawal(object $withdrawal): array
    {
        return [
            'id' => $withdrawal->id,
            'amount' => (float) $withdrawal->amount,
            'reference' => $withdrawal->reference,
            'wallet_account' => $withdrawal->wallet_account,
            'note' => $withdrawal->note,
            'withdrawn_at' => $withdrawal->withdrawn_at,
            'created_at' => $withdrawal->created_at,
        ];
    }

    public function index(): JsonResponse
    {
        $timezone = (string) config('app.timezone', 'Africa/Kampala');
        $now = now($timezone);
        $todayStart = $now->copy()->startOfDay();
        $tomorrowStart = $todayStart->copy()->addDay();
        $weekStart = $now->copy()->startOfWeek();
        $monthStart = $now->copy()->startOfMonth();
        $last30 = $now->copy()->subDays(29)->startOfDay();

        // ── Finance ────────────────────────────────────────────────────────
        $paymentsByStatus = DB::table('payments')
            ->select('status', DB::raw('COUNT(*) as cnt'), DB::raw('COALESCE(SUM(amount), 0) as total'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $paymentsByStatus = collect([
            'confirmed' => (object) ['cnt' => 0, 'total' => 0],
            'pending' => (object) ['cnt' => 0, 'total' => 0],
            'failed' => (object) ['cnt' => 0, 'total' => 0],
        ])->merge($paymentsByStatus);

        $confirmedRow = $paymentsByStatus->get('confirmed');
        $attemptedCount = (int) $paymentsByStatus->sum(fn ($row) => (int) $row->cnt);
        $attemptedAmount = (float) $paymentsByStatus->sum(fn ($row) => (float) $row->total);
        $knownStatuses = ['confirmed', 'pending', 'failed'];
        $unclassifiedRows = $paymentsByStatus->reject(fn ($row, $status) => in_array($status, $knownStatuses, true));
        $accountedCount = (int) $paymentsByStatus->sum(fn ($row) => (int) $row->cnt);
        $accountedAmount = (float) $paymentsByStatus->sum(fn ($row) => (float) $row->total);

        $ledgerTotals = DB::table('payments')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total')
            ->first();

        $todayAttemptsByStatus = DB::table('payments')
            ->select('status', DB::raw('COUNT(*) as cnt'), DB::raw('COALESCE(SUM(amount), 0) as total'))
            ->where('created_at', '>=', $todayStart)
            ->where('created_at', '<', $tomorrowStart)
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $todayAttemptCount = (int) $todayAttemptsByStatus->sum(fn ($row) => (int) $row->cnt);
        $todayAttemptAmount = (float) $todayAttemptsByStatus->sum(fn ($row) => (float) $row->total);

        $failuresByCategory = DB::table('payments')
            ->select('failure_category', DB::raw('COUNT(*) as cnt'), DB::raw('COALESCE(SUM(amount), 0) as total'))
            ->where('status', 'failed')
            ->whereNotNull('failure_category')
            ->groupBy('failure_category')
            ->get()
            ->keyBy('failure_category')
            ->map(fn ($row) => [
                'count' => (int) $row->cnt,
                'amount' => (float) $row->total,
            ]);

        $todayConfirmedScope = DB::table('payments')->where('status', 'confirmed');
        $this->applyConfirmationWindow($todayConfirmedScope, $todayStart, $tomorrowStart);
        $revenueToday = (float) (clone $todayConfirmedScope)->sum('amount');
        $confirmedTodayCount = (int) (clone $todayConfirmedScope)->count();

        $weekConfirmedScope = DB::table('payments')->where('status', 'confirmed');
        $this->applyConfirmationWindow($weekConfirmedScope, $weekStart, $tomorrowStart);
        $revenueWeek = (float) $weekConfirmedScope->sum('amount');

        $monthConfirmedScope = DB::table('payments')->where('status', 'confirmed');
        $this->applyConfirmationWindow($monthConfirmedScope, $monthStart, $tomorrowStart);
        $revenueMonth = (float) $monthConfirmedScope->sum('amount');

        $revenueByPlan = DB::table('payments')
            ->select('plan_type', DB::raw('COALESCE(SUM(amount), 0) as total'))
            ->where('status', 'confirmed')
            ->whereNotNull('plan_type')
            ->groupBy('plan_type')
            ->pluck('total', 'plan_type');

        $revenueByMethod = DB::table('payments')
            ->select('payment_method', DB::raw('COALESCE(SUM(amount), 0) as total'))
            ->where('status', 'confirmed')
            ->whereNotNull('payment_method')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        // ── Commission ─────────────────────────────────────────────────────
        $trackedCommissionRows = $this->trackedCommissionBase()
            ->orderByDesc('created_at')
            ->get([
                'id', 'amount', 'plan_type', 'payment_method',
                'agent_commission_amount', 'agent_commission_status',
                'agent_commission_reference', 'agent_commission_processed_at',
                'agent_commission_ratio', 'agent_commission_error', 'created_at',
            ]);

        $confirmedCommissionScope = DB::table('payments')->where('status', 'confirmed');
        $confirmedPaymentsCount = (int) (clone $confirmedCommissionScope)->count();
        $confirmedPaymentsAmount = (float) (clone $confirmedCommissionScope)->sum('amount');
        $untrackedCommissionScope = (clone $confirmedCommissionScope)
            ->where(function ($query) {
                $query->whereNull('agent_commission_amount')
                    ->orWhere('agent_commission_amount', '<=', 0);
            });
        $untrackedCommissionCount = (int) (clone $untrackedCommissionScope)->count();
        $untrackedCommissionRevenue = (float) $untrackedCommissionScope->sum('amount');

        $totalEarned = (float) $trackedCommissionRows->sum('agent_commission_amount');

        $totalPaid = (float) $trackedCommissionRows
            ->filter(fn ($row) => $this->normaliseCommissionStatus($row->agent_commission_status) === 'completed')
            ->sum('agent_commission_amount');

        $commissionWithdrawals = $this->commissionWithdrawals();
        $totalWithdrawn = (float) $commissionWithdrawals->sum('amount');
        $availableCommission = max(0, $totalPaid - $totalWithdrawn);

        $commByStatus = $trackedCommissionRows
            ->groupBy(fn ($row) => $this->normaliseCommissionStatus($row->agent_commission_status))
            ->map(fn ($rows) => [
                'count' => $rows->count(),
                'amount' => (float) $rows->sum('agent_commission_amount'),
            ]);

        $commByStatus = collect([
            'completed' => ['count' => 0, 'amount' => 0.0],
            'processing' => ['count' => 0, 'amount' => 0.0],
            'pending' => ['count' => 0, 'amount' => 0.0],
            'failed' => ['count' => 0, 'amount' => 0.0],
        ])->merge($commByStatus);

        $commByPlan = $trackedCommissionRows
            ->filter(fn ($row) => ! empty($row->plan_type))
            ->groupBy('plan_type')
            ->map(fn ($rows) => (float) $rows->sum('agent_commission_amount'));

        $commByMethod = $trackedCommissionRows
            ->filter(fn ($row) => ! empty($row->payment_method))
            ->groupBy('payment_method')
            ->map(fn ($rows) => (float) $rows->sum('agent_commission_amount'));

        // Report the current configured rate; stored row values remain historical.
        $commRatio = (float) config('services.mobile_money.agent_commission.ratio', 0.2);

        $recentComm = $trackedCommissionRows
            ->take(25)
            ->map(function ($row) {
                $row->agent_commission_status = $this->normaliseCommissionStatus($row->agent_commission_status);

                return $row;
            })
            ->values();

        // ── Users ──────────────────────────────────────────────────────────
        $totalUsers = (int) DB::table('users')->count();
        $newToday = (int) DB::table('users')->where('created_at', '>=', $todayStart)->count();
        $newWeek = (int) DB::table('users')->where('created_at', '>=', $weekStart)->count();
        $newMonth = (int) DB::table('users')->where('created_at', '>=', $monthStart)->count();

        // ── Subscriptions ──────────────────────────────────────────────────
        $subsByStatus = DB::table('subscriptions')
            ->select('status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $activeByPlan = DB::table('subscriptions')
            ->select('plan_type', DB::raw('COUNT(*) as cnt'))
            ->where('status', 'active')
            ->groupBy('plan_type')
            ->pluck('cnt', 'plan_type');

        // ── Ledger audit ──────────────────────────────────────────────────
        $confirmedMissingTimestamp = DB::table('payments')
            ->where('status', 'confirmed')
            ->whereNull('confirmed_at');
        $confirmedMissingTransaction = DB::table('payments')
            ->where('status', 'confirmed')
            ->where(function ($query) {
                $query->whereNull('transaction_id')->orWhere('transaction_id', '');
            });
        $orphanedSubscriptions = DB::table('payments')->whereNull('subscription_id');
        $orphanedUsers = DB::table('payments')->whereNull('user_id');

        $recentPayments = DB::table('payments')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get([
                'id', 'amount', 'plan_type', 'payment_method', 'phone', 'status',
                'payment_reference', 'transaction_id', 'confirmed_at', 'failed_at',
                'failure_category', 'failure_reason', 'subscription_id', 'user_id', 'created_at',
            ])
            ->map(function ($payment) {
                $phone = (string) $payment->phone;
                $payment->phone = strlen($phone) > 4
                    ? str_repeat('*', strlen($phone) - 4).substr($phone, -4)
                    : $phone;

                return $payment;
            });

        // ── Charts (last 30 days) ──────────────────────────────────────────
        $sqlTimezone = str_replace("'", "''", $timezone);
        $confirmationDateSql = "(COALESCE(confirmed_at, created_at) AT TIME ZONE '{$sqlTimezone}')::date";
        $signupDateSql = "(created_at AT TIME ZONE '{$sqlTimezone}')::date";
        $revenueChart = DB::table('payments')
            ->select(
                DB::raw("{$confirmationDateSql} as date"),
                DB::raw('COALESCE(SUM(amount), 0) as amount')
            )
            ->where('status', 'confirmed')
            ->where(function ($query) use ($last30) {
                $query->where('confirmed_at', '>=', $last30)
                    ->orWhere(function ($legacy) use ($last30) {
                        $legacy->whereNull('confirmed_at')->where('created_at', '>=', $last30);
                    });
            })
            ->groupBy(DB::raw($confirmationDateSql))
            ->orderBy('date')
            ->get();

        $signupsChart = DB::table('users')
            ->select(
                DB::raw("{$signupDateSql} as date"),
                DB::raw('COUNT(*) as count')
            )
            ->where('created_at', '>=', $last30)
            ->groupBy(DB::raw($signupDateSql))
            ->orderBy('date')
            ->get();

        // ── Payments count by pending status (for dashboard alert) ─────────
        $pendingCount = (int) ($paymentsByStatus->get('pending')?->cnt ?? 0);

        return response()->json([
            'finance' => [
                'attempted_count' => $attemptedCount,
                'attempted_amount' => $attemptedAmount,
                'total_revenue' => (float) ($confirmedRow?->total ?? 0),
                'revenue_today' => $revenueToday,
                'revenue_this_week' => $revenueWeek,
                'revenue_this_month' => $revenueMonth,
                'today' => [
                    'date' => $todayStart->toDateString(),
                    'timezone' => $timezone,
                    'confirmed_count' => $confirmedTodayCount,
                    'confirmed_amount' => $revenueToday,
                    'attempted_count' => $todayAttemptCount,
                    'attempted_amount' => $todayAttemptAmount,
                    'pending_count' => (int) ($todayAttemptsByStatus->get('pending')?->cnt ?? 0),
                    'pending_amount' => (float) ($todayAttemptsByStatus->get('pending')?->total ?? 0),
                    'failed_count' => (int) ($todayAttemptsByStatus->get('failed')?->cnt ?? 0),
                    'failed_amount' => (float) ($todayAttemptsByStatus->get('failed')?->total ?? 0),
                ],
                'by_plan' => $revenueByPlan,
                'by_method' => $revenueByMethod,
                'by_status' => $paymentsByStatus->map(fn ($r) => [
                    'count' => (int) $r->cnt,
                    'amount' => (float) $r->total,
                ]),
                'failures_by_category' => $failuresByCategory,
                'unclassified_count' => (int) $unclassifiedRows->sum(fn ($row) => (int) $row->cnt),
                'unclassified_amount' => (float) $unclassifiedRows->sum(fn ($row) => (float) $row->total),
                'reconciliation_difference' => round((float) ($ledgerTotals?->total ?? 0) - $accountedAmount, 2),
                'confirmation_rate' => $attemptedCount > 0
                    ? round(((int) ($confirmedRow?->cnt ?? 0) / $attemptedCount) * 100, 2)
                    : 0.0,
                'recent_payments' => $recentPayments,
                'audit' => [
                    'as_of' => $now->toIso8601String(),
                    'timezone' => $timezone,
                    'ledger_count' => (int) ($ledgerTotals?->cnt ?? 0),
                    'ledger_amount' => (float) ($ledgerTotals?->total ?? 0),
                    'status_count_total' => $accountedCount,
                    'status_amount_total' => $accountedAmount,
                    'status_count_difference' => (int) ($ledgerTotals?->cnt ?? 0) - $accountedCount,
                    'status_amount_difference' => round((float) ($ledgerTotals?->total ?? 0) - $accountedAmount, 2),
                    'confirmed_missing_timestamp_count' => (int) (clone $confirmedMissingTimestamp)->count(),
                    'confirmed_missing_timestamp_amount' => (float) $confirmedMissingTimestamp->sum('amount'),
                    'confirmed_missing_transaction_count' => (int) (clone $confirmedMissingTransaction)->count(),
                    'confirmed_missing_transaction_amount' => (float) $confirmedMissingTransaction->sum('amount'),
                    'preserved_without_subscription_count' => (int) (clone $orphanedSubscriptions)->count(),
                    'preserved_without_subscription_amount' => (float) $orphanedSubscriptions->sum('amount'),
                    'preserved_without_user_count' => (int) (clone $orphanedUsers)->count(),
                    'preserved_without_user_amount' => (float) $orphanedUsers->sum('amount'),
                    'ledger_preservation_started_at' => '2026-10-22',
                ],
            ],
            'commission' => [
                'enabled' => (bool) config('services.mobile_money.agent_commission.enabled', false),
                'ratio' => $commRatio,
                'wallet_account' => $this->commissionWalletAccount(),
                'total_earned' => $totalEarned,
                'overall_total' => $totalEarned,
                'total_paid' => $totalPaid,
                'wallet_received' => $totalPaid,
                'total_withdrawn' => $totalWithdrawn,
                'available' => $availableCommission,
                'current_total' => $availableCommission,
                'outstanding' => max(0, $totalEarned - $totalPaid),
                'tracked_payment_count' => $trackedCommissionRows->count(),
                'tracked_payment_revenue' => (float) $trackedCommissionRows->sum('amount'),
                'confirmed_payment_count' => $confirmedPaymentsCount,
                'confirmed_payment_revenue' => $confirmedPaymentsAmount,
                'untracked_payment_count' => $untrackedCommissionCount,
                'untracked_payment_revenue' => $untrackedCommissionRevenue,
                'reconciliation_difference' => round(
                    $totalEarned - (float) $commByStatus->sum(fn ($row) => (float) $row['amount']),
                    2
                ),
                'by_status' => $commByStatus,
                'by_plan' => $commByPlan,
                'by_method' => $commByMethod,
                'recent' => $recentComm,
                'withdrawals' => $commissionWithdrawals
                    ->take(10)
                    ->map(fn ($row) => $this->serialiseCommissionWithdrawal($row))
                    ->values(),
            ],
            'users' => [
                'total' => $totalUsers,
                'new_today' => $newToday,
                'new_this_week' => $newWeek,
                'new_this_month' => $newMonth,
            ],
            'subscriptions' => [
                'by_status' => $subsByStatus,
                'active_total' => (int) ($subsByStatus->get('active') ?? 0),
                'active_by_plan' => $activeByPlan,
            ],
            'payments' => [
                'pending_count' => $pendingCount,
            ],
            'charts' => [
                'revenue' => $revenueChart,
                'signups' => $signupsChart,
            ],
        ]);
    }

    public function supportInsights(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'in:7,30,90,365'],
        ]);
        $days = (int) ($validated['days'] ?? 30);
        $timezone = (string) config('support.timezone', 'Africa/Kampala');
        $since = now($timezone)->subDays($days - 1)->startOfDay();

        if (! Schema::hasTable('support_conversations')) {
            return response()->json($this->emptySupportInsights($days, $since));
        }

        $conversations = SupportConversation::query()
            ->where(function ($query) use ($since) {
                $query->where('last_message_at', '>=', $since)
                    ->orWhere(function ($fallback) use ($since) {
                        $fallback->whereNull('last_message_at')->where('created_at', '>=', $since);
                    });
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get([
                'id', 'public_id', 'status', 'mode', 'category', 'sentiment',
                'priority', 'summary', 'last_message_at', 'created_at',
            ]);

        $total = $conversations->count();
        $resolved = $conversations->where('status', 'resolved')->count();
        $escalated = $conversations->whereIn('status', ['waiting_human', 'human'])->count();
        $negative = $conversations->whereIn('sentiment', ['frustrated', 'angry'])->count();
        $feedback = $conversations->whereIn('category', ['complaint', 'suggestion'])->count();

        $byCategory = collect(self::SUPPORT_CATEGORIES)->mapWithKeys(
            fn (string $label, string $category) => [$category => [
                'label' => $label,
                'count' => $conversations->where('category', $category)->count(),
            ]]
        );
        $bySentiment = collect(['positive', 'neutral', 'frustrated', 'angry'])->mapWithKeys(
            fn (string $sentiment) => [$sentiment => $conversations->where('sentiment', $sentiment)->count()]
        );
        $byPriority = collect(['low', 'normal', 'high', 'urgent'])->mapWithKeys(
            fn (string $priority) => [$priority => $conversations->where('priority', $priority)->count()]
        );

        $themes = $byCategory
            ->filter(fn (array $row) => $row['count'] > 0)
            ->map(function (array $row, string $category) use ($conversations) {
                $matching = $conversations->where('category', $category);

                return [
                    'category' => $category,
                    'label' => $row['label'],
                    'count' => $row['count'],
                    'negative' => $matching->whereIn('sentiment', ['frustrated', 'angry'])->count(),
                    'escalated' => $matching->whereIn('status', ['waiting_human', 'human'])->count(),
                    'recommended_action' => $this->supportRecommendation($category),
                ];
            })
            ->sortByDesc(fn (array $theme) => ($theme['count'] * 10) + ($theme['negative'] * 2) + $theme['escalated'])
            ->take(6)
            ->values();

        $recentFeedback = $conversations
            ->filter(fn (SupportConversation $conversation) => trim((string) $conversation->summary) !== '')
            ->sortByDesc(fn (SupportConversation $conversation) => in_array($conversation->category, ['complaint', 'suggestion'], true) ? 1 : 0)
            ->take(10)
            ->map(fn (SupportConversation $conversation) => [
                'reference' => $conversation->public_id,
                'category' => $conversation->category,
                'category_label' => self::SUPPORT_CATEGORIES[$conversation->category] ?? 'Other',
                'sentiment' => $conversation->sentiment,
                'priority' => $conversation->priority,
                'status' => $conversation->status,
                'summary' => $this->redactSupportSummary((string) $conversation->summary),
                'last_message_at' => $conversation->last_message_at ?? $conversation->created_at,
            ])
            ->values();

        $trend = collect(range(0, $days - 1))->map(function (int $offset) use ($since, $conversations, $timezone) {
            $date = $since->copy()->addDays($offset)->toDateString();
            $onDate = $conversations->filter(function (SupportConversation $conversation) use ($date, $timezone) {
                $timestamp = $conversation->last_message_at ?? $conversation->created_at;

                return $timestamp?->timezone($timezone)->toDateString() === $date;
            });

            return [
                'date' => $date,
                'conversations' => $onDate->count(),
                'feedback' => $onDate->whereIn('category', ['complaint', 'suggestion'])->count(),
            ];
        })->values();

        $topTheme = $themes->first();
        $highlights = [];
        if ($topTheme) {
            $highlights[] = "{$topTheme['label']} is the leading theme with {$topTheme['count']} conversation".($topTheme['count'] === 1 ? '.' : 's.');
        }
        if ($negative > 0) {
            $highlights[] = "{$negative} conversation".($negative === 1 ? ' shows' : 's show').' frustrated or angry sentiment.';
        }
        if ($escalated > 0) {
            $highlights[] = "{$escalated} conversation".($escalated === 1 ? ' needs' : 's need').' human attention.';
        }

        return response()->json([
            'period' => [
                'days' => $days,
                'from' => $since->toDateString(),
                'to' => now($timezone)->toDateString(),
            ],
            'summary' => [
                'total_conversations' => $total,
                'feedback_signals' => $feedback,
                'resolved' => $resolved,
                'escalated' => $escalated,
                'negative' => $negative,
                'resolution_rate' => $total > 0 ? round(($resolved / $total) * 100, 1) : 0.0,
                'negative_rate' => $total > 0 ? round(($negative / $total) * 100, 1) : 0.0,
            ],
            'brief' => [
                'headline' => $topTheme
                    ? "{$topTheme['label']} is the strongest customer signal in the last {$days} days."
                    : "No customer feedback has been recorded in the last {$days} days.",
                'highlights' => $highlights,
            ],
            'by_category' => $byCategory,
            'by_sentiment' => $bySentiment,
            'by_priority' => $byPriority,
            'themes' => $themes,
            'trend' => $trend,
            'recent_feedback' => $recentFeedback,
        ]);
    }

    public function storeCommissionWithdrawal(Request $request): JsonResponse
    {
        if (! Schema::hasTable('commission_withdrawals')) {
            return response()->json([
                'success' => false,
                'message' => 'Commission withdrawals table is not ready. Run database migrations first.',
            ], 503);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'reference' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:1000'],
            'withdrawn_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        $amount = round((float) $validated['amount'], 2);
        $walletReceived = $this->commissionWalletReceived();
        $alreadyWithdrawn = (float) DB::table('commission_withdrawals')->sum('amount');
        $available = max(0, $walletReceived - $alreadyWithdrawn);

        if ($amount > $available) {
            throw ValidationException::withMessages([
                'amount' => 'Withdrawal exceeds available commission balance of '.number_format($available).' UGX.',
            ]);
        }

        $now = now();
        $withdrawnAt = isset($validated['withdrawn_at'])
            ? Carbon::parse($validated['withdrawn_at'])
            : $now;

        $withdrawalId = DB::table('commission_withdrawals')->insertGetId([
            'amount' => $amount,
            'reference' => $validated['reference'] ?? null,
            'wallet_account' => $this->commissionWalletAccount() ?: null,
            'note' => $validated['note'] ?? null,
            'withdrawn_at' => $withdrawnAt,
            'created_at' => $now,
        ]);

        $withdrawal = DB::table('commission_withdrawals')->where('id', $withdrawalId)->first();
        $totalWithdrawn = $alreadyWithdrawn + $amount;

        return response()->json([
            'success' => true,
            'withdrawal' => $this->serialiseCommissionWithdrawal($withdrawal),
            'commission' => [
                'wallet_received' => $walletReceived,
                'total_paid' => $walletReceived,
                'total_withdrawn' => $totalWithdrawn,
                'available' => max(0, $walletReceived - $totalWithdrawn),
                'current_total' => max(0, $walletReceived - $totalWithdrawn),
            ],
        ], 201);
    }

    public function retryCommission(Payment $payment): JsonResponse
    {
        $payment->refresh();

        if ($payment->status !== 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Only confirmed payments can have commission retried.',
            ], 409);
        }

        $currentStatus = $this->normaliseCommissionStatus($payment->agent_commission_status);
        if (in_array($currentStatus, ['completed', 'processing'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Commission is already paid or currently processing.',
                'payment' => $this->serialiseCommissionPayment($payment),
            ], 409);
        }

        $result = (new MobileMoneyService)->processAgentCommission($payment, 'developer-retry');
        $payment->refresh();

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => $result['message'] ?? 'Commission retry completed.',
            'payment' => $this->serialiseCommissionPayment($payment),
        ]);
    }

    private function serialiseCommissionPayment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => (float) $payment->amount,
            'plan_type' => $payment->plan_type,
            'payment_method' => $payment->payment_method,
            'agent_commission_amount' => $payment->agent_commission_amount !== null
                ? (float) $payment->agent_commission_amount
                : null,
            'agent_commission_status' => $this->normaliseCommissionStatus($payment->agent_commission_status),
            'agent_commission_reference' => $payment->agent_commission_reference,
            'agent_commission_processed_at' => $payment->agent_commission_processed_at,
            'agent_commission_ratio' => $payment->agent_commission_ratio !== null
                ? (float) $payment->agent_commission_ratio
                : null,
            'agent_commission_error' => $payment->agent_commission_error,
            'created_at' => $payment->created_at,
        ];
    }

    private function supportRecommendation(string $category): string
    {
        return match ($category) {
            'payment' => 'Review payment confirmation, reconciliation, and customer-facing status messages.',
            'subscription' => 'Review activation, expiry, and package-access guidance.',
            'account' => 'Review sign-in, account recovery, and profile verification flows.',
            'prediction_content' => 'Review content availability, clarity, and delivery expectations.',
            'technical' => 'Prioritize reproducible platform errors and affected user journeys.',
            'complaint' => 'Review repeated complaints, ownership, and resolution time.',
            'suggestion' => 'Group similar requests and assess product value versus implementation effort.',
            default => 'Review conversation summaries and classify recurring customer needs.',
        };
    }

    private function redactSupportSummary(string $summary): string
    {
        $redacted = preg_replace(
            [
                '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
                '/(?<!\w)\+?\d[\d\s-]{8,}\d(?!\w)/',
                '/\b(?:ALX|TXN|TRX|RCP|MPESA|JPESA)[-_A-Z0-9]{4,}\b/i',
            ],
            ['[email removed]', '[phone removed]', '[reference removed]'],
            $summary
        );

        return mb_substr(trim((string) $redacted), 0, 2000);
    }

    private function emptySupportInsights(int $days, Carbon $since): array
    {
        return [
            'period' => [
                'days' => $days,
                'from' => $since->toDateString(),
                'to' => now(config('support.timezone', 'Africa/Kampala'))->toDateString(),
            ],
            'summary' => [
                'total_conversations' => 0,
                'feedback_signals' => 0,
                'resolved' => 0,
                'escalated' => 0,
                'negative' => 0,
                'resolution_rate' => 0.0,
                'negative_rate' => 0.0,
            ],
            'brief' => [
                'headline' => "No customer feedback has been recorded in the last {$days} days.",
                'highlights' => [],
            ],
            'by_category' => collect(self::SUPPORT_CATEGORIES)->mapWithKeys(
                fn (string $label, string $category) => [$category => ['label' => $label, 'count' => 0]]
            ),
            'by_sentiment' => ['positive' => 0, 'neutral' => 0, 'frustrated' => 0, 'angry' => 0],
            'by_priority' => ['low' => 0, 'normal' => 0, 'high' => 0, 'urgent' => 0],
            'themes' => [],
            'trend' => [],
            'recent_feedback' => [],
        ];
    }
}
