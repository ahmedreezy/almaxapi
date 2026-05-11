<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestimonialController extends Controller
{
    use HandlesFileUploads;

    public function index(): JsonResponse
    {
        return response()->json(
            Testimonial::orderByDesc('created_at')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'caption'    => ['sometimes', 'nullable', 'string'],
            'memberName' => ['sometimes', 'nullable', 'string', 'max:200'],
            'image'      => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $imageUrl = null;
        if ($request->hasFile('image')) {
            $imageUrl = $this->storeUpload($request->file('image'), 'testimonials');
        }

        $testimonial = Testimonial::create([
            'caption'     => $data['caption'] ?? '',
            'member_name' => $data['memberName'] ?? '',
            'image_url'   => $imageUrl ?? '',
        ]);

        return response()->json($testimonial, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $testimonial = Testimonial::findOrFail($id);
        $data        = $request->validate([
            'caption'    => ['sometimes', 'nullable', 'string'],
            'memberName' => ['sometimes', 'nullable', 'string', 'max:200'],
            'image'      => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        if ($request->hasFile('image')) {
            $this->deleteUpload($testimonial->image_url);
            $data['image_url'] = $this->storeUpload($request->file('image'), 'testimonials');
        }

        $testimonial->update([
            'caption'     => $data['caption']     ?? $testimonial->caption,
            'member_name' => $data['memberName']   ?? $testimonial->member_name,
            'image_url'   => $data['image_url']    ?? $testimonial->image_url,
        ]);

        return response()->json($testimonial->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $testimonial = Testimonial::findOrFail($id);
        $this->deleteUpload($testimonial->image_url);
        $testimonial->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
