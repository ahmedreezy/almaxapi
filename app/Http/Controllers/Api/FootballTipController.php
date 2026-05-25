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
        return response()->json(
            FootballTip::orderByDesc('created_at')->get()
        );
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
        ]);

        return response()->json($tip, 201);
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
        ]);

        return response()->json($tip->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $tip = FootballTip::findOrFail($id);
        $this->deleteUpload($tip->image_url);
        $tip->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
