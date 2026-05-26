<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET    /api/groups          — public:  active groups only (special hidden until priced)
 * GET    /api/groups/admin    — admin:   all groups including hidden ones
 * POST   /api/groups          — admin:   create a new package
 * PATCH  /api/groups/:id      — admin:   update price, betslip, special fields
 * DELETE /api/groups/:id      — admin:   remove a package
 */
class GroupController extends Controller
{
    use HandlesFileUploads;
    /**
     * Public: return only groups that end-users can purchase.
     * Regular groups: is_active must be true.
     * Special groups: is_active must be true AND special_price must be set.
     */
    public function index(): JsonResponse
    {
        $groups = Group::orderBy('price')
            ->get()
            ->filter(fn (Group $g) => $g->isPubliclyVisible())
            ->map(fn (Group $g) => $this->formatGroup($g))
            ->values();

        return response()->json($groups);
    }

    /**
     * Admin: return ALL groups, including inactive and unpriced special ones.
     */
    public function indexAdmin(): JsonResponse
    {
        $groups = Group::orderBy('price')
            ->get()
            ->map(fn (Group $g) => $this->formatGroup($g));

        return response()->json($groups);
    }

    /**
     * Admin: create a new VIP package.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:100', 'unique:groups,name'],
            'odds_type'             => ['required', 'string', 'max:20'],
            'plan_type'             => ['required', 'string', 'in:daily,weekly,monthly,special'],
            'price'                 => ['required', 'numeric', 'min:0'],
            'betslip_link'          => ['sometimes', 'nullable', 'string', 'max:500'],
            'betslip_code'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_special'            => ['sometimes', 'boolean'],
            'is_active'             => ['sometimes', 'boolean'],
            'special_price'         => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'special_odds'          => ['sometimes', 'nullable', 'string', 'max:50'],
            'subscription_deadline' => ['sometimes', 'nullable', 'date_format:H:i'],
            'photo'                 => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $photoUrl = null;
        if ($request->hasFile('photo')) {
            $photoUrl = $this->storeUpload($request->file('photo'), 'groups');
        }

        $group = Group::create(array_merge([
            'betslip_link' => '',
            'betslip_code' => '',
            'is_special'   => false,
            'is_active'    => true,
            'photo_url'    => $photoUrl ?? '',
        ], $data));

        return response()->json($this->formatGroup($group), 201);
    }

    /**
     * Admin: update a group's fields.
     * Supports toggling special odds: set is_active + special_price + special_odds.
     * Clearing special_price (null) hides the special group from users.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name'                  => ['sometimes', 'string', 'max:100'],
            'price'                 => ['sometimes', 'numeric', 'min:0'],
            'betslip_link'          => ['sometimes', 'nullable', 'string', 'max:500'],
            'betslip_code'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_special'            => ['sometimes', 'boolean'],
            'is_active'             => ['sometimes', 'boolean'],
            'special_price'         => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'special_odds'          => ['sometimes', 'nullable', 'string', 'max:50'],
            'subscription_deadline' => ['sometimes', 'nullable', 'date_format:H:i'],
            'photo'                 => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
            'clear_photo'           => ['sometimes', 'boolean'],
        ]);

        $clearPhoto = !empty($data['clear_photo']);
        unset($data['clear_photo']);

        $group = Group::findOrFail($id);

        if ($request->hasFile('photo')) {
            $this->deleteUpload($group->photo_url);
            $data['photo_url'] = $this->storeUpload($request->file('photo'), 'groups');
        } elseif ($clearPhoto) {
            if ($group->photo_url) {
                $this->deleteUpload($group->photo_url);
            }
            $data['photo_url'] = '';
        }

        // Allow explicit null for special_price and special_odds (to reset)
        $group->fill($data);
        // ConvertEmptyStringsToNull middleware can turn '' into null for NOT NULL columns — guard against it
        $group->betslip_link = $group->betslip_link ?? '';
        $group->betslip_code = $group->betslip_code ?? '';
        if ($request->has('special_price')) {
            $group->special_price = $request->input('special_price');
        }
        if ($request->has('special_odds')) {
            $group->special_odds = $request->input('special_odds');
        }
        if ($request->has('subscription_deadline')) {
            $group->subscription_deadline = $request->input('subscription_deadline') ?: null;
        }
        $group->save();

        // Immediately push updated betslip link/code to all active subscribers so
        // weekly/monthly users see the daily pick refresh without a manual status check.
        if ($group->wasChanged('betslip_link') || $group->wasChanged('betslip_code')) {
            \App\Models\Subscription::where('group_id', $group->id)
                ->where('status', 'active')
                ->update([
                    'betslip_link' => $group->betslip_link,
                    'betslip_code' => $group->betslip_code,
                ]);

            if (in_array($group->plan_type, ['weekly', 'monthly'], true)) {
                \Illuminate\Support\Facades\Log::info('Betslip refreshed for long-term subscribers', [
                    'group_id'  => $group->id,
                    'plan_type' => $group->plan_type,
                    'name'      => $group->name,
                ]);
            }
        }

        return response()->json($this->formatGroup($group->fresh()));
    }

    /**
     * Admin: permanently delete a package.
     * Blocked only if there are ACTIVE or PENDING subscriptions (data still in-use).
     * Expired/rejected subscriptions are cascade-deleted with the group.
     */
    public function destroy(int $id): JsonResponse
    {
        $group = Group::findOrFail($id);

        // Cascade-delete all subscriptions and payments regardless of status,
        // then remove the package photo and the group itself.
        \Illuminate\Support\Facades\DB::transaction(function () use ($group) {
            $group->subscriptions()->each(function ($sub) {
                $sub->payment()->delete();
                $sub->delete();
            });
            if ($group->photo_url) {
                $this->deleteUpload($group->photo_url);
            }
            $group->delete();
        });

        return response()->json(['message' => 'Package deleted.'], 200);
    }

    // ─── Private helpers ──────────────────────────────────────────────────

    private function formatGroup(Group $group): array
    {
        return [
            'id'                   => $group->id,
            'name'                 => $group->name,
            'oddsType'             => $group->odds_type,
            'planType'             => $group->plan_type,
            'price'                => $group->price,
            'betslipLink'          => $group->betslip_link ?? '',
            'betslipCode'          => $group->betslip_code ?? '',
            'isSpecial'            => (bool) $group->is_special,
            'isActive'             => (bool) $group->is_active,
            'specialPrice'         => $group->special_price,
            'specialOdds'          => $group->special_odds,
            'effectivePrice'       => $group->effectivePrice(),
            'photoUrl'             => $group->photo_url ?? '',
            'subscriptionDeadline' => $group->subscription_deadline
                ? substr($group->subscription_deadline, 0, 5)   // trim to "HH:MM"
                : null,
            'isClosed'             => $group->isPastDeadline(),
        ];
    }
}
