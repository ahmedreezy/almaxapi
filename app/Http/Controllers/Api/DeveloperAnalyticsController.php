<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        $commByStatus = DB::table('payments')
            ->select(
                'agent_commission_status',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('COALESCE(SUM(agent_commission_amount), 0) as total')
            )
            ->whereNotNull('agent_commission_status')
            ->where('agent_commission_status', '!=', '')
            ->groupBy('agent_commission_status')
            ->get()
            ->keyBy('agent_commission_status');

        $totalEarned = (float) DB::table('payments')
            ->whereNotNull('agent_commission_amount')
            ->sum('agent_commission_amount');

        $totalPaid = (float) DB::table('payments')
            ->where('agent_commission_status', 'completed')
            ->sum('agent_commission_amount');

        $commByPlan = DB::table('payments')
            ->select('plan_type', DB::raw('COALESCE(SUM(agent_commission_amount), 0) as total'))
            ->whereNotNull('agent_commission_amount')
            ->whereNotNull('plan_type')
            ->groupBy('plan_type')
            ->pluck('total', 'plan_type');

        $commByMethod = DB::table('payments')
            ->select('payment_method', DB::raw('COALESCE(SUM(agent_commission_amount), 0) as total'))
            ->whereNotNull('agent_commission_amount')
            ->whereNotNull('payment_method')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        // Commission ratio (read from latest stored value, fall back to env default)
        $commRatio = (float) (
            DB::table('payments')->whereNotNull('agent_commission_ratio')->value('agent_commission_ratio')
            ?? config('services.mobile_money.agent_commission.ratio', 0.1)
        );

        $recentComm = DB::table('payments')
            ->whereNotNull('agent_commission_amount')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get([
                'id', 'amount', 'plan_type', 'payment_method',
                'agent_commission_amount', 'agent_commission_status',
                'agent_commission_reference', 'agent_commission_processed_at',
                'created_at',
            ]);

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
                'outstanding'  => $totalEarned - $totalPaid,
                'by_status'    => $commByStatus->map(fn ($r) => [
                    'count'  => (int) $r->cnt,
                    'amount' => (float) $r->total,
                ]),
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
}
