<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PayoutRequest;
use App\Models\Subscription;
use App\Models\SupportConversation;
use App\Models\User;
use App\Services\Payments\WalletLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OwnerAnalyticsController extends Controller
{
    public function __construct(private readonly WalletLedgerService $wallets) {}

    public function index(): JsonResponse
    {
        $timezone = (string) config('app.timezone', 'Africa/Kampala');
        $now = Carbon::now($timezone);
        $today = $now->copy()->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $month = $now->copy()->startOfMonth();
        $chartStart = $today->copy()->subDays(13);

        $todayRevenue = $this->confirmedBetween($today, $tomorrow)->sum('amount');
        $monthRevenue = $this->confirmedBetween($month, $tomorrow)->sum('amount');
        $wallet = $this->wallets->providerSummary('owner', 'relworx');

        $sqlTimezone = str_replace("'", "''", $timezone);
        $dateSql = DB::getDriverName() === 'sqlite'
            ? 'date(COALESCE(confirmed_at, created_at))'
            : "(COALESCE(confirmed_at, created_at) AT TIME ZONE '{$sqlTimezone}')::date";

        $chartRows = Payment::query()
            ->select(DB::raw("{$dateSql} as date"), DB::raw('COALESCE(SUM(amount), 0) as amount'))
            ->where('status', 'confirmed')
            ->where(function ($query) use ($chartStart) {
                $query->where('confirmed_at', '>=', $chartStart)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('confirmed_at')->where('created_at', '>=', $chartStart));
            })
            ->groupBy(DB::raw($dateSql))
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->date);

        $chart = collect(range(0, 13))->map(function (int $offset) use ($chartStart, $chartRows) {
            $date = $chartStart->copy()->addDays($offset)->toDateString();
            return ['date' => $date, 'amount' => (float) ($chartRows->get($date)?->amount ?? 0)];
        });

        $recent = Payment::with(['user:id,username,phone', 'subscription.group:id,name,odds_type'])
            ->latest('created_at')
            ->limit(5)
            ->get();

        return response()->json([
            'finance' => [
                'revenue_today' => (float) $todayRevenue,
                'revenue_this_month' => (float) $monthRevenue,
                'pending_payments' => Payment::where('status', 'pending')->count(),
                'failed_payments' => Payment::where('status', 'failed')->count(),
            ],
            'customers' => [
                'members' => User::count(),
                'active_subscriptions' => Subscription::where('status', 'active')->count(),
            ],
            'wallet' => [
                'available' => (float) ($wallet['available'] ?? 0),
                'reserved' => (float) ($wallet['reserved'] ?? 0),
                'pending_payouts' => PayoutRequest::where('beneficiary', 'owner')
                    ->whereIn('status', ['pending', 'processing'])
                    ->count(),
            ],
            'attention' => [
                'support_waiting' => SupportConversation::whereIn('status', ['waiting_human', 'escalated'])->count(),
            ],
            'chart' => $chart,
            'recent_payments' => $recent,
            'as_of' => $now->toIso8601String(),
            'timezone' => $timezone,
        ]);
    }

    public function financialReport(Request $request): JsonResponse
    {
        [$start, $end, $period] = $this->reportRange($request);
        $attempts = Payment::query()->where('created_at', '>=', $start)->where('created_at', '<', $end);
        $confirmed = $this->confirmedBetween($start, $end);

        $attemptSummary = (clone $attempts)->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')->first();
        $confirmedSummary = (clone $confirmed)->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')->first();
        $statusRows = (clone $attempts)->selectRaw('status, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')->groupBy('status')->get();
        $wallet = $this->wallets->providerSummary('owner', 'relworx');

        $timezone = (string) config('app.timezone', 'Africa/Kampala');
        $sqlTimezone = str_replace("'", "''", $timezone);
        $dateSql = DB::getDriverName() === 'sqlite'
            ? 'date(COALESCE(confirmed_at, created_at))'
            : "(COALESCE(confirmed_at, created_at) AT TIME ZONE '{$sqlTimezone}')::date";
        $trendRows = (clone $confirmed)
            ->select(DB::raw("{$dateSql} as date"), DB::raw('COUNT(*) as count'), DB::raw('COALESCE(SUM(amount), 0) as amount'))
            ->groupBy(DB::raw($dateSql))->orderBy('date')->get()->keyBy(fn ($row) => (string) $row->date);
        $days = max(1, $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()));
        $trend = collect(range(0, $days - 1))->map(function (int $offset) use ($start, $trendRows) {
            $date = $start->copy()->addDays($offset)->toDateString();
            $row = $trendRows->get($date);
            return ['date' => $date, 'count' => (int) ($row?->count ?? 0), 'amount' => (float) ($row?->amount ?? 0)];
        });

        $breakdown = fn (string $field) => (clone $attempts)
            ->selectRaw("COALESCE({$field}, 'unknown') as label, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount")
            ->groupBy($field)->orderByDesc('amount')->get();
        $payouts = PayoutRequest::query()->where('beneficiary', 'owner')->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')->groupBy('status')->get();

        $attemptCount = (int) ($attemptSummary?->count ?? 0);
        $confirmedCount = (int) ($confirmedSummary?->count ?? 0);
        $confirmedAmount = (float) ($confirmedSummary?->amount ?? 0);

        return response()->json([
            'range' => ['period' => $period, 'from' => $start->toDateString(), 'to' => $end->copy()->subDay()->toDateString()],
            'timezone' => $timezone,
            'generated_at' => Carbon::now($timezone)->toIso8601String(),
            'summary' => [
                'attempted_count' => $attemptCount,
                'attempted_amount' => (float) ($attemptSummary?->amount ?? 0),
                'confirmed_count' => $confirmedCount,
                'confirmed_revenue' => $confirmedAmount,
                'confirmation_rate' => $attemptCount > 0 ? round(($confirmedCount / $attemptCount) * 100, 1) : 0,
                'average_payment' => $confirmedCount > 0 ? round($confirmedAmount / $confirmedCount, 2) : 0,
                'pending_amount' => (float) ($statusRows->firstWhere('status', 'pending')?->amount ?? 0),
                'failed_amount' => (float) ($statusRows->firstWhere('status', 'failed')?->amount ?? 0),
            ],
            'trend' => $trend,
            'breakdowns' => [
                'status' => $statusRows->map(fn ($row) => ['label' => $row->status ?: 'unknown', 'count' => (int) $row->count, 'amount' => (float) $row->amount])->values(),
                'method' => $breakdown('payment_method'),
                'plan' => $breakdown('plan_type'),
                'gateway' => $breakdown('provider'),
            ],
            'wallet' => ['available' => (float) ($wallet['available'] ?? 0), 'reserved' => (float) ($wallet['reserved'] ?? 0)],
            'payouts' => $payouts,
        ]);
    }

    public function exportFinancialReport(Request $request): StreamedResponse
    {
        [$start, $end] = $this->reportRange($request);
        $payments = Payment::with('user:id,username,phone')->where('created_at', '>=', $start)->where('created_at', '<', $end)->orderByDesc('created_at');
        $filename = "almax-financial-report-{$start->toDateString()}-{$end->copy()->subDay()->toDateString()}.csv";

        return response()->streamDownload(function () use ($payments) {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Attempted at', 'Customer', 'Phone', 'Reference', 'Transaction ID', 'Plan', 'Method', 'Gateway', 'Status', 'Amount']);
            $payments->chunk(500, function ($rows) use ($output) {
                foreach ($rows as $payment) {
                    fputcsv($output, [$payment->created_at?->toIso8601String(), $payment->user?->username, $payment->user?->phone ?? $payment->phone, $payment->payment_reference, $payment->transaction_id, $payment->plan_type, $payment->payment_method, $payment->provider, $payment->status, $payment->amount]);
                }
            });
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function reportRange(Request $request): array
    {
        $data = $request->validate([
            'period' => ['sometimes', 'string', 'in:today,7d,30d,month,custom'],
            'from' => ['required_if:period,custom', 'nullable', 'date'],
            'to' => ['required_if:period,custom', 'nullable', 'date', 'after_or_equal:from'],
        ]);
        $timezone = (string) config('app.timezone', 'Africa/Kampala');
        $now = Carbon::now($timezone);
        $period = $data['period'] ?? 'month';
        $end = $now->copy()->addDay()->startOfDay();
        $start = match ($period) {
            'today' => $now->copy()->startOfDay(),
            '7d' => $now->copy()->subDays(6)->startOfDay(),
            '30d' => $now->copy()->subDays(29)->startOfDay(),
            'custom' => Carbon::parse($data['from'], $timezone)->startOfDay(),
            default => $now->copy()->startOfMonth(),
        };
        if ($period === 'custom') $end = Carbon::parse($data['to'], $timezone)->addDay()->startOfDay();
        return [$start, $end, $period];
    }

    private function confirmedBetween(Carbon $start, Carbon $end)
    {
        return Payment::query()
            ->where('status', 'confirmed')
            ->where(function ($query) use ($start, $end) {
                $query->where(function ($confirmed) use ($start, $end) {
                    $confirmed->whereNotNull('confirmed_at')
                        ->where('confirmed_at', '>=', $start)
                        ->where('confirmed_at', '<', $end);
                })->orWhere(function ($legacy) use ($start, $end) {
                    $legacy->whereNull('confirmed_at')
                        ->where('created_at', '>=', $start)
                        ->where('created_at', '<', $end);
                });
            });
    }
}
