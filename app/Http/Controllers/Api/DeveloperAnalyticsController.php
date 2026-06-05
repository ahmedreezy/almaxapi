<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\MobileMoneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
            'processing'        => 'processing',
            'failed', 'failure', 'error' => 'failed',
            default             => 'pending',
        };
    }

    public function index(): JsonResponse
    {
        $now        = now();
        $todayStart = $now->copy()->startOfDay();
        $weekStart  = $now->copy()->startOfWeek();
        $monthStart = $now->copy()->startOfMonth();
        $last30     = $now->copy()->subDays(29)->startOfDay();

        // ── Finance ────────────────────────────────────────────────────────
        $paymentsByStatus = DB::table('payments')
            ->select('status', DB::raw('COUNT(*) as cnt'), DB::raw('COALESCE(SUM(amount), 0) as total'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $confirmedRow = $paymentsByStatus->get('confirmed');

        $revenueToday  = (float) DB::table('payments')
            ->where('status', 'confirmed')
            ->where('created_at', '>=', $todayStart)
            ->sum('amount');

        $revenueWeek   = (float) DB::table('payments')
            ->where('status', 'confirmed')
            ->where('created_at', '>=', $weekStart)
            ->sum('amount');

        $revenueMonth  = (float) DB::table('payments')
            ->where('status', 'confirmed')
            ->where('created_at', '>=', $monthStart)
            ->sum('amount');

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

        $totalEarned = (float) $trackedCommissionRows->sum('agent_commission_amount');

        $totalPaid = (float) $trackedCommissionRows
            ->filter(fn ($row) => $this->normaliseCommissionStatus($row->agent_commission_status) === 'completed')
            ->sum('agent_commission_amount');

        $commByStatus = $trackedCommissionRows
            ->groupBy(fn ($row) => $this->normaliseCommissionStatus($row->agent_commission_status))
            ->map(fn ($rows) => [
                'count'  => $rows->count(),
                'amount' => (float) $rows->sum('agent_commission_amount'),
            ]);

        $commByStatus = collect([
            'completed'  => ['count' => 0, 'amount' => 0.0],
            'processing' => ['count' => 0, 'amount' => 0.0],
            'pending'    => ['count' => 0, 'amount' => 0.0],
            'failed'     => ['count' => 0, 'amount' => 0.0],
        ])->merge($commByStatus);

        $commByPlan = $trackedCommissionRows
            ->filter(fn ($row) => ! empty($row->plan_type))
            ->groupBy('plan_type')
            ->map(fn ($rows) => (float) $rows->sum('agent_commission_amount'));

        $commByMethod = $trackedCommissionRows
            ->filter(fn ($row) => ! empty($row->payment_method))
            ->groupBy('payment_method')
            ->map(fn ($rows) => (float) $rows->sum('agent_commission_amount'));

        // Commission ratio (read from latest stored value, fall back to env default)
        $commRatio = (float) (
            $trackedCommissionRows->first(fn ($row) => $row->agent_commission_ratio !== null)?->agent_commission_ratio
            ?? config('services.mobile_money.agent_commission.ratio', 0.1)
        );

        $recentComm = $trackedCommissionRows
            ->take(25)
            ->map(function ($row) {
                $row->agent_commission_status = $this->normaliseCommissionStatus($row->agent_commission_status);
                return $row;
            })
            ->values();

        // ── Users ──────────────────────────────────────────────────────────
        $totalUsers  = (int) DB::table('users')->count();
        $newToday    = (int) DB::table('users')->where('created_at', '>=', $todayStart)->count();
        $newWeek     = (int) DB::table('users')->where('created_at', '>=', $weekStart)->count();
        $newMonth    = (int) DB::table('users')->where('created_at', '>=', $monthStart)->count();

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

        // ── Charts (last 30 days) ──────────────────────────────────────────
        // Using (timestamptz AT TIME ZONE 'UTC')::date for deterministic UTC dates
        $revenueChart = DB::table('payments')
            ->select(
                DB::raw("(created_at AT TIME ZONE 'UTC')::date as date"),
                DB::raw('COALESCE(SUM(amount), 0) as amount')
            )
            ->where('status', 'confirmed')
            ->where('created_at', '>=', $last30)
            ->groupBy(DB::raw("(created_at AT TIME ZONE 'UTC')::date"))
            ->orderBy('date')
            ->get();

        $signupsChart = DB::table('users')
            ->select(
                DB::raw("(created_at AT TIME ZONE 'UTC')::date as date"),
                DB::raw('COUNT(*) as count')
            )
            ->where('created_at', '>=', $last30)
            ->groupBy(DB::raw("(created_at AT TIME ZONE 'UTC')::date"))
            ->orderBy('date')
            ->get();

        // ── Payments count by pending status (for dashboard alert) ─────────
        $pendingCount = (int) ($paymentsByStatus->get('pending')?->cnt ?? 0);

        return response()->json([
            'finance' => [
                'total_revenue'      => (float) ($confirmedRow?->total ?? 0),
                'revenue_today'      => $revenueToday,
                'revenue_this_week'  => $revenueWeek,
                'revenue_this_month' => $revenueMonth,
                'by_plan'            => $revenueByPlan,
                'by_method'          => $revenueByMethod,
                'by_status'          => $paymentsByStatus->map(fn ($r) => [
                    'count'  => (int) $r->cnt,
                    'amount' => (float) $r->total,
                ]),
            ],
            'commission' => [
                'enabled'      => (bool) config('services.mobile_money.agent_commission.enabled', false),
                'ratio'        => $commRatio ?: 0.1,
                'total_earned' => $totalEarned,
                'total_paid'   => $totalPaid,
                'outstanding'  => max(0, $totalEarned - $totalPaid),
                'by_status'    => $commByStatus,
                'by_plan'   => $commByPlan,
                'by_method' => $commByMethod,
                'recent'    => $recentComm,
            ],
            'users' => [
                'total'          => $totalUsers,
                'new_today'      => $newToday,
                'new_this_week'  => $newWeek,
                'new_this_month' => $newMonth,
            ],
            'subscriptions' => [
                'by_status'      => $subsByStatus,
                'active_total'   => (int) ($subsByStatus->get('active') ?? 0),
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

        $result = (new MobileMoneyService())->processAgentCommission($payment, 'developer-retry');
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
}
