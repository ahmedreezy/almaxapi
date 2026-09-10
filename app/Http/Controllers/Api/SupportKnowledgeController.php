<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportKnowledgeArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SupportKnowledgeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(SupportKnowledgeArticle::orderBy('locale')->orderBy('title')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $adminId = $request->user()?->id;
        $article = SupportKnowledgeArticle::create(array_merge($data, [
            'public_id' => (string) Str::uuid(),
            'created_by' => $adminId,
            'updated_by' => $adminId,
            'published_at' => ($data['status'] ?? 'draft') === 'published' ? now() : null,
        ]));

        return response()->json($article, 201);
    }

    public function update(Request $request, SupportKnowledgeArticle $article): JsonResponse
    {
        $data = $this->validated($request, true);
        $data['updated_by'] = $request->user()?->id;
        $data['version'] = $article->version + 1;
        if (($data['status'] ?? null) === 'published' && $article->status !== 'published') {
            $data['published_at'] = now();
        } elseif (($data['status'] ?? null) === 'draft') {
            $data['published_at'] = null;
        }
        $article->update($data);

        return response()->json($article->fresh());
    }

    public function destroy(SupportKnowledgeArticle $article): JsonResponse
    {
        $article->delete();

        return response()->json(['deleted' => true]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$presence, 'string', 'max:200'],
            'question' => [$presence, 'string', 'max:2000'],
            'answer' => [$presence, 'string', 'max:10000'],
            'keywords' => ['sometimes', 'array', 'max:30'],
            'keywords.*' => ['string', 'max:80'],
            'locale' => ['sometimes', 'string', 'in:en,lg'],
            'status' => ['sometimes', 'string', 'in:draft,published'],
        ]);
    }
}
