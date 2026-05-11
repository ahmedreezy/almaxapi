<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RecentWin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecentWinController extends Controller
{
    use HandlesFileUploads;

    public function index(): JsonResponse
    {
        return response()->json(
            RecentWin::orderByDesc('created_at')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'betType'    => ['required', 'string', 'max:100'],
            'date'       => ['required', 'string', 'max:50'],
            'staked'     => ['required', 'string', 'max:50'],
            'returned'   => ['required', 'string', 'max:50'],
            'odds'       => ['required', 'string', 'max:50'],
            'memberName' => ['sometimes', 'nullable', 'string', 'max:200'],
            'image'      => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $imageUrl = null;
        if ($request->hasFile('image')) {
            $imageUrl = $this->storeUpload($request->file('image'), 'wins');
        }

        $win = RecentWin::create([
            'bet_type'    => $data['betType'],
            'date'        => $data['date'],
            'staked'      => $data['staked'],
            'returned'    => $data['returned'],
            'odds'        => $data['odds'],
            'member_name' => $data['memberName'] ?? '',
            'image_url'   => $imageUrl ?? '',
        ]);

        return response()->json($win, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $win  = RecentWin::findOrFail($id);
        $data = $request->validate([
            'betType'    => ['sometimes', 'string', 'max:100'],
            'date'       => ['sometimes', 'string', 'max:50'],
            'staked'     => ['sometimes', 'string', 'max:50'],
            'returned'   => ['sometimes', 'string', 'max:50'],
            'odds'       => ['sometimes', 'string', 'max:50'],
            'memberName' => ['sometimes', 'nullable', 'string', 'max:200'],
            'image'      => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        if ($request->hasFile('image')) {
            $this->deleteUpload($win->image_url);
            $data['image_url'] = $this->storeUpload($request->file('image'), 'wins');
        }

        $win->update([
            'bet_type'    => $data['betType']    ?? $win->bet_type,
            'date'        => $data['date']        ?? $win->date,
            'staked'      => $data['staked']      ?? $win->staked,
            'returned'    => $data['returned']    ?? $win->returned,
            'odds'        => $data['odds']         ?? $win->odds,
            'member_name' => $data['memberName']  ?? $win->member_name,
            'image_url'   => $data['image_url']   ?? $win->image_url,
        ]);

        return response()->json($win->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $win = RecentWin::findOrFail($id);
        $this->deleteUpload($win->image_url);
        $win->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
