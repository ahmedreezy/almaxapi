<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlmaxPrediction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlmaxPredictionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            AlmaxPrediction::orderByDesc('created_at')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'home'        => ['required', 'string', 'max:200'],
            'away'        => ['required', 'string', 'max:200'],
            'competition' => ['required', 'string', 'max:200'],
            'kickoff'     => ['required', 'string', 'max:50'],
            'tip'         => ['required', 'string', 'max:200'],
            'odds'        => ['sometimes', 'nullable', 'string', 'max:50'],
            'result'      => ['sometimes', 'string', 'in:pending,won,lost'],
        ]);

        $prediction = AlmaxPrediction::create([
            'home'        => $data['home'],
            'away'        => $data['away'],
            'competition' => $data['competition'],
            'kickoff'     => $data['kickoff'],
            'tip'         => $data['tip'],
            'odds'        => $data['odds'] ?? '',
            'result'      => $data['result'] ?? 'pending',
        ]);

        return response()->json($prediction, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $prediction = AlmaxPrediction::findOrFail($id);
        $data       = $request->validate([
            'home'        => ['sometimes', 'string', 'max:200'],
            'away'        => ['sometimes', 'string', 'max:200'],
            'competition' => ['sometimes', 'string', 'max:200'],
            'kickoff'     => ['sometimes', 'string', 'max:50'],
            'tip'         => ['sometimes', 'string', 'max:200'],
            'odds'        => ['sometimes', 'nullable', 'string', 'max:50'],
            'result'      => ['sometimes', 'string', 'in:pending,won,lost'],
        ]);

        $prediction->update($data);

        return response()->json($prediction->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        AlmaxPrediction::findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
