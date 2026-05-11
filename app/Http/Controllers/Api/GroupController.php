<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET  /api/groups        — public: list all groups with name, odds, plan, price
 * PATCH /api/groups/:id   — admin: update betslip_link, betslip_code, price, name
 */
class GroupController extends Controller
{
    /** Public: return all groups */
    public function index(): JsonResponse
    {
        $groups = Group::orderBy('price')->get();
        return response()->json($groups);
    }

    /** Admin: update a group's betslip link/code, price, or name */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:100'],
            'price'         => ['sometimes', 'numeric', 'min:0'],
            'betslip_link'  => ['sometimes', 'nullable', 'string', 'max:500'],
            'betslip_code'  => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $group = Group::findOrFail($id);
        $group->update(array_filter($data, fn ($v) => $v !== null));

        return response()->json($group->fresh());
    }
}
