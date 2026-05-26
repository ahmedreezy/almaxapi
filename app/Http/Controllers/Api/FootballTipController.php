<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FootballTip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FootballTipController extends Controller
{
    use HandlesFileUploads;

    public function index(): JsonResponse
    {
        $tips = FootballTip::with('group')->orderByDesc('created_at')->get();

        return response()->json($tips->map(fn (FootballTip $t) => $this->formatTip($t)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'home'        => ['sometimes', 'string', 'max:200'],
            'away'        => ['sometimes', 'string', 'max:200'],
            'competition' => ['sometimes', 'string', 'max:200'],
            'kickoff'     => ['sometimes', 'string', 'max:50'],
            'winProb'     => ['sometimes', 'integer', 'min:0', 'max:100'],
            'kitColor'    => ['sometimes', 'string', 'max:20'],
            'kitNumber'   => ['sometimes', 'string', 'max:10'],
            'prediction'  => ['sometimes', 'string'],
            'accent'      => ['sometimes', 'string', 'max:20'],
            'caption'     => ['sometimes', 'string'],
            'image'       => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
            'group_id'    => ['sometimes', 'nullable', 'integer', 'exists:groups,id'],
        ]);

        $imageUrl = null;
        if ($request->hasFile('image')) {
            $imageUrl = $this->storeUpload($request->file('image'), 'tips');
        }

        $tip = FootballTip::create([
            'home'        => $data['home'] ?? '',
            'away'        => $data['away'] ?? '',
            'competition' => $data['competition'] ?? '',
            'kickoff'     => $data['kickoff'] ?? '',
            'win_prob'    => $data['winProb'] ?? 75,
            'kit_color'   => $data['kitColor'] ?? '#FFD700',
            'kit_number'  => $data['kitNumber'] ?? '10',
            'prediction'  => $data['prediction'] ?? '',
            'accent'      => $data['accent'] ?? '#FFD700',
            'image_url'   => $imageUrl ?? '',
            'caption'     => $data['caption'] ?? '',
            'group_id'    => $data['group_id'] ?? null,
        ]);

        return response()->json($this->formatTip($tip->load('group')), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tip  = FootballTip::findOrFail($id);
        $data = $request->validate([
            'home'        => ['sometimes', 'string', 'max:200'],
            'away'        => ['sometimes', 'string', 'max:200'],
            'competition' => ['sometimes', 'string', 'max:200'],
            'kickoff'     => ['sometimes', 'string', 'max:50'],
            'winProb'     => ['sometimes', 'integer', 'min:0', 'max:100'],
            'kitColor'    => ['sometimes', 'string', 'max:20'],
            'kitNumber'   => ['sometimes', 'string', 'max:10'],
            'prediction'  => ['sometimes', 'string'],
            'accent'      => ['sometimes', 'string', 'max:20'],
            'caption'     => ['sometimes', 'string'],
            'image'       => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
            'group_id'    => ['sometimes', 'nullable', 'integer', 'exists:groups,id'],
        ]);

        if ($request->hasFile('image')) {
            $this->deleteUpload($tip->image_url);
            $data['image_url'] = $this->storeUpload($request->file('image'), 'tips');
        }

        $tip->update([
            'home'        => $data['home']        ?? $tip->home,
            'away'        => $data['away']         ?? $tip->away,
            'competition' => $data['competition']  ?? $tip->competition,
            'kickoff'     => $data['kickoff']      ?? $tip->kickoff,
            'win_prob'    => $data['winProb']      ?? $tip->win_prob,
            'kit_color'   => $data['kitColor']     ?? $tip->kit_color,
            'kit_number'  => $data['kitNumber']    ?? $tip->kit_number,
            'prediction'  => $data['prediction']   ?? $tip->prediction,
            'accent'      => $data['accent']       ?? $tip->accent,
            'image_url'   => $data['image_url']    ?? $tip->image_url,
            'caption'     => $data['caption']      ?? $tip->caption,
            'group_id'    => array_key_exists('group_id', $data) ? $data['group_id'] : $tip->group_id,
        ]);

        return response()->json($this->formatTip($tip->fresh()->load('group')));
    }

    public function destroy(int $id): JsonResponse
    {
        $tip = FootballTip::findOrFail($id);
        $this->deleteUpload($tip->image_url);
        $tip->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    // ─── Private helpers ──────────────────────────────────────────────────

    private function formatTip(FootballTip $tip): array
    {
        $group = $tip->relationLoaded('group') ? $tip->group : null;

        return [
            'id'          => $tip->id,
            'home'        => $tip->home,
            'away'        => $tip->away,
            'competition' => $tip->competition,
            'kickoff'     => $tip->kickoff,
            'win_prob'    => $tip->win_prob,
            'kit_color'   => $tip->kit_color,
            'kit_number'  => $tip->kit_number,
            'prediction'  => $tip->prediction,
            'accent'      => $tip->accent,
            'image_url'   => $tip->image_url,
            'caption'     => $tip->caption,
            'created_at'  => $tip->created_at,
            'groupId'     => $tip->group_id,
            'group'       => $group ? [
                'id'           => $group->id,
                'name'         => $group->name,
                'planType'     => $group->plan_type,
                'oddsType'     => $group->odds_type,
                'effectivePrice' => $group->effectivePrice(),
                'photoUrl'     => $group->photo_url ?? '',
                'isClosed'     => $group->isPastDeadline(),
            ] : null,
        ];
    }
}
