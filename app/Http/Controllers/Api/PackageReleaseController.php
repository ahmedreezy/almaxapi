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
use Illuminate\Support\Collection;
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
            'groupId' => ['required_without:groupIds', 'integer', 'exists:groups,id'],
            'groupIds' => ['required_without:groupId', 'array', 'min:1'],
            'groupIds.*' => ['required', 'integer', 'distinct', 'exists:groups,id'],
            'betslipLink' => ['sometimes', 'nullable', 'url', 'max:500'],
            'betslipCode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cutoffAt' => ['required', 'date', 'after:now'],
        ]);
        if (blank($data['betslipLink'] ?? null) && blank($data['betslipCode'] ?? null)) {
            return response()->json(['message' => 'Add a betslip link or code.'], 422);
        }

        $groupIds = collect($data['groupIds'] ?? [$data['groupId']])->unique()->values();
        $releases = DB::transaction(function () use ($data, $groupIds) {
            $groups = Group::query()
                ->whereIn('id', $groupIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            return $groupIds->map(function (int $groupId) use ($data, $groups) {
                $group = $groups->get($groupId);
                abort_unless($group, 422, 'One or more selected packages no longer exist.');

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

                return $release->load('group');
            });
        });

        if (array_key_exists('groupId', $data) && ! array_key_exists('groupIds', $data)) {
            return response()->json($releases->first(), 201);
        }

        return response()->json(['releases' => $releases->values()], 201);
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
                $this->issueRecovery(collect([$locked]), $data);
            }

            return $locked;
        });

        return response()->json($release->fresh()->load(['group', 'recovery'])->loadCount('entitlements'));
    }

    public function recovery(Request $request): JsonResponse
    {
        $data = $request->validate([
            'releaseIds' => ['required', 'array', 'min:1'],
            'releaseIds.*' => ['required', 'uuid', 'distinct', 'exists:package_releases,public_id'],
            'recoveryBetslipLink' => ['sometimes', 'nullable', 'url', 'max:500'],
            'recoveryBetslipCode' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        if (blank($data['recoveryBetslipLink'] ?? null) && blank($data['recoveryBetslipCode'] ?? null)) {
            return response()->json(['message' => 'Add a recovery betslip link or code.'], 422);
        }

        $result = DB::transaction(function () use ($request, $data) {
            $releases = PackageRelease::query()
                ->whereIn('public_id', $data['releaseIds'])
                ->lockForUpdate()
                ->get();

            abort_unless($releases->count() === count($data['releaseIds']), 422, 'One or more selected package releases no longer exist.');
            abort_if($releases->contains(fn (PackageRelease $release) => $release->type !== 'paid'), 422, 'Only paid package releases can receive a recovery ticket.');
            abort_if($releases->contains(fn (PackageRelease $release) => $release->result !== 'pending'), 409, 'One or more selected package results have already been finalized.');
            abort_if($releases->contains(fn (PackageRelease $release) => now()->lt($release->cutoff_at)), 422, 'All selected package deadlines must pass before issuing a recovery ticket.');

            foreach ($releases as $release) {
                $release->update([
                    'result' => 'failed',
                    'result_marked_at' => now(),
                    'result_marked_by' => $request->user()->id,
                ]);
            }

            $recovery = $this->issueRecovery($releases, $data);

            return [
                'recovery' => $recovery->load(['group', 'recoverySources.group'])->loadCount('entitlements'),
                'releases' => $releases->map(fn (PackageRelease $release) => $release->fresh()->load('group'))->values(),
            ];
        });

        return response()->json($result);
    }

    public function recoveries(Request $request): JsonResponse
    {
        $entitlements = PackageReleaseEntitlement::with(['release.group', 'sourceRelease'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('granted_at')
            ->get();

        return response()->json($entitlements);
    }

    private function issueRecovery(Collection $releases, array $data): PackageRelease
    {
        /** @var PackageRelease $primary */
        $primary = $releases->sortBy('id')->first();
        $recovery = PackageRelease::create([
            'public_id' => (string) Str::uuid(),
            'group_id' => $primary->group_id,
            'parent_release_id' => $primary->id,
            'type' => 'recovery',
            'betslip_link' => trim((string) ($data['recoveryBetslipLink'] ?? '')),
            'betslip_code' => trim((string) ($data['recoveryBetslipCode'] ?? '')),
            'published_at' => now(),
            'cutoff_at' => now(),
            'result' => 'pending',
        ]);

        $recovery->recoverySources()->attach($releases->pluck('id')->all());
        $eligibleByUser = collect();
        foreach ($releases as $source) {
            $subscriptions = Subscription::query()
                ->where('group_id', $source->group_id)
                ->whereNotNull('started_at')
                ->whereNotNull('expires_at')
                ->where('started_at', '<=', $source->cutoff_at)
                ->where('expires_at', '>=', $source->cutoff_at)
                ->whereHas('payment', fn ($payment) => $payment->where('status', 'confirmed'))
                ->orderBy('id')
                ->get();

            foreach ($subscriptions as $subscription) {
                if (! $eligibleByUser->has($subscription->user_id)) {
                    $eligibleByUser->put($subscription->user_id, [
                        'subscription' => $subscription,
                        'source' => $source,
                    ]);
                }
            }
        }

        foreach ($eligibleByUser as $eligible) {
            $subscription = $eligible['subscription'];
            $source = $eligible['source'];
            PackageReleaseEntitlement::create([
                'release_id' => $recovery->id,
                'source_release_id' => $source->id,
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'granted_at' => now(),
            ]);
            UserNotification::create([
                'user_id' => $subscription->user_id,
                'type' => 'recovery_ticket',
                'title' => 'Your free recovery ticket is ready',
                'message' => 'A package you received did not pass. We have added a free recovery betslip to your account.',
                'data' => [
                    'releaseId' => $recovery->public_id,
                    'sourceReleaseId' => $source->public_id,
                    'sourceReleaseIds' => $releases->pluck('public_id')->values()->all(),
                ],
            ]);
        }

        return $recovery;
    }
}
