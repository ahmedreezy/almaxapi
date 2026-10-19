<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\PackageRelease;
use App\Models\PackageReleaseEntitlement;
use App\Models\Subscription;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PackageReleaseController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(PackageRelease::with(['group', 'resultAdmin'])
            ->withCount('entitlements')
            ->orderByDesc('published_at')
            ->limit(200)
            ->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'groupId' => ['required', 'integer', 'exists:groups,id'],
            'betslipLink' => ['sometimes', 'nullable', 'url', 'max:500'],
            'betslipCode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cutoffAt' => ['required', 'date', 'after:now'],
        ]);
        if (blank($data['betslipLink'] ?? null) && blank($data['betslipCode'] ?? null)) {
            return response()->json(['message' => 'Add a betslip link or code.'], 422);
        }

        $release = DB::transaction(function () use ($data) {
            $group = Group::lockForUpdate()->findOrFail($data['groupId']);
            $release = PackageRelease::create([
                'public_id' => (string) Str::uuid(),
                'group_id' => $group->id,
                'type' => 'paid',
                'betslip_link' => trim((string) ($data['betslipLink'] ?? '')),
                'betslip_code' => trim((string) ($data['betslipCode'] ?? '')),
                'published_at' => now(),
                'cutoff_at' => $data['cutoffAt'],
                'result' => 'pending',
            ]);
            $group->update([
                'betslip_link' => $release->betslip_link,
                'betslip_code' => $release->betslip_code,
                'subscription_deadline' => $release->cutoff_at->format('Y-m-d\TH:i'),
            ]);
            Subscription::where('group_id', $group->id)->where('status', 'active')->update([
                'betslip_link' => $release->betslip_link,
                'betslip_code' => $release->betslip_code,
            ]);

            return $release;
        });

        return response()->json($release->load('group'), 201);
    }

    public function result(Request $request, PackageRelease $release): JsonResponse
    {
        $data = $request->validate([
            'result' => ['required', 'string', 'in:passed,failed'],
            'recoveryBetslipLink' => ['sometimes', 'nullable', 'url', 'max:500'],
            'recoveryBetslipCode' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        abort_unless($release->type === 'paid', 422, 'Recovery tickets cannot create another recovery.');
        abort_if(now()->lt($release->cutoff_at), 422, 'The result can only be recorded after the cutoff.');
        if ($data['result'] === 'failed'
            && blank($data['recoveryBetslipLink'] ?? null)
            && blank($data['recoveryBetslipCode'] ?? null)) {
            return response()->json(['message' => 'A failed ticket requires a recovery betslip link or code.'], 422);
        }

        $release = DB::transaction(function () use ($request, $release, $data) {
            $locked = PackageRelease::lockForUpdate()->findOrFail($release->id);
            abort_unless($locked->result === 'pending', 409, 'This result has already been finalized.');
            $locked->update([
                'result' => $data['result'],
                'result_marked_at' => now(),
                'result_marked_by' => $request->user()->id,
            ]);

            if ($data['result'] === 'failed') {
                $recovery = PackageRelease::create([
                    'public_id' => (string) Str::uuid(),
                    'group_id' => $locked->group_id,
                    'parent_release_id' => $locked->id,
                    'type' => 'recovery',
                    'betslip_link' => trim((string) ($data['recoveryBetslipLink'] ?? '')),
                    'betslip_code' => trim((string) ($data['recoveryBetslipCode'] ?? '')),
                    'published_at' => now(),
                    'cutoff_at' => now(),
                    'result' => 'pending',
                ]);

                $eligible = Subscription::query()
                    ->where('group_id', $locked->group_id)
                    ->whereNotNull('started_at')
                    ->whereNotNull('expires_at')
                    ->where('started_at', '<=', $locked->cutoff_at)
                    ->where('expires_at', '>=', $locked->cutoff_at)
                    ->whereHas('payment', fn ($payment) => $payment->where('status', 'confirmed'))
                    ->orderBy('id')
                    ->get();

                foreach ($eligible->unique('user_id') as $subscription) {
                    PackageReleaseEntitlement::create([
                        'release_id' => $recovery->id,
                        'source_release_id' => $locked->id,
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                        'granted_at' => now(),
                    ]);
                    UserNotification::create([
                        'user_id' => $subscription->user_id,
                        'type' => 'recovery_ticket',
                        'title' => 'Your free recovery ticket is ready',
                        'message' => 'A package you received did not pass. We have added a free recovery betslip to your account.',
                        'data' => ['releaseId' => $recovery->public_id, 'sourceReleaseId' => $locked->public_id],
                    ]);
                }
            }

            return $locked;
        });

        return response()->json($release->fresh()->load(['group', 'recovery'])->loadCount('entitlements'));
    }

    public function recoveries(Request $request): JsonResponse
    {
        $entitlements = PackageReleaseEntitlement::with(['release.group', 'sourceRelease'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('granted_at')
            ->get();

        return response()->json($entitlements);
    }
}
