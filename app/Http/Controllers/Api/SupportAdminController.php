<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSupportMessage;
use App\Models\SupportConversation;
use App\Models\SupportDailyUsage;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:open,resolved'],
            'channel' => ['sometimes', 'string', 'in:platform,whatsapp'],
            'category' => ['sometimes', 'string', 'max:50'],
            'priority' => ['sometimes', 'string', 'in:low,normal,high,urgent'],
            'search' => ['sometimes', 'string', 'max:100'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SupportConversation::with(['contact.user', 'assignedAdmin'])
            ->withCount('messages')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END")
            ->orderByDesc('last_message_at');

        foreach (['status', 'category', 'priority'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (! empty($data['channel'])) {
            $query->whereHas('contact', fn ($contact) => $contact->where('channel', $data['channel']));
        }
        if (! empty($data['search'])) {
            $search = '%'.$data['search'].'%';
            $query->whereHas('contact', function ($contact) use ($search) {
                $contact->where('phone', 'like', $search)->orWhere('display_name', 'like', $search);
            });
        }

        return response()->json($query->paginate($data['perPage'] ?? 30));
    }

    public function show(SupportConversation $conversation): JsonResponse
    {
        return response()->json($conversation->load([
            'contact.user',
            'contact.dailyUsages' => fn ($query) => $query->orderByDesc('usage_date')->limit(30),
            'assignedAdmin',
            'messages' => fn ($query) => $query->with('attachments')->orderBy('id'),
        ]));
    }

    public function update(Request $request, SupportConversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['sometimes', 'string', 'in:ai,human'],
            'status' => ['sometimes', 'string', 'in:open,resolved'],
            'category' => ['sometimes', 'string', 'in:payment,subscription,account,prediction_content,technical,complaint,suggestion,other'],
            'priority' => ['sometimes', 'string', 'in:low,normal,high,urgent'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'dailyLimit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        if (array_key_exists('dailyLimit', $data)) {
            $conversation->contact()->update(['daily_limit_override' => $data['dailyLimit']]);
            unset($data['dailyLimit']);
        }

        if (($data['status'] ?? null) === 'resolved') {
            $data['resolved_at'] = now();
        } elseif (isset($data['status'])) {
            $data['resolved_at'] = null;
        }
        if (($data['mode'] ?? null) === 'ai' && $conversation->status !== 'resolved') {
            $data['status'] = 'open';
            $data['assigned_admin_id'] = null;
            $data['human_requested_at'] = null;
        }

        $conversation->update($data);

        return response()->json($conversation->fresh(['contact.user', 'assignedAdmin']));
    }

    public function takeOver(Request $request, SupportConversation $conversation): JsonResponse
    {
        abort_unless($conversation->contact()->where('channel', 'platform')->exists(), 422, 'Only platform chats can be taken over.');

        $conversation = DB::transaction(function () use ($request, $conversation) {
            $locked = SupportConversation::lockForUpdate()->findOrFail($conversation->id);
            $locked->update([
                'mode' => 'human',
                'status' => 'open',
                'assigned_admin_id' => $request->user()->id,
                'human_requested_at' => now(),
                'resolved_at' => null,
            ]);

            return $locked;
        });

        return response()->json($conversation->fresh(['contact.user', 'assignedAdmin']));
    }

    public function reply(Request $request, SupportConversation $conversation): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        abort_unless($conversation->contact()->where('channel', 'platform')->exists(), 422, 'Manual replies are limited to platform chats.');

        $message = DB::transaction(function () use ($request, $conversation, $data) {
            $locked = SupportConversation::lockForUpdate()->findOrFail($conversation->id);
            abort_unless($locked->mode === 'human', 409, 'Take over this conversation before replying.');
            abort_unless((int) $locked->assigned_admin_id === (int) $request->user()->id, 403, 'This conversation is assigned to another administrator.');

            $message = SupportMessage::create([
                'conversation_id' => $locked->id,
                'direction' => 'outbound',
                'sender_type' => 'admin',
                'body' => trim($data['body']),
                'delivery_status' => 'delivered',
                'metadata' => ['admin_id' => $request->user()->id],
                'sent_at' => now(),
            ]);
            $locked->update(['last_message_at' => now(), 'status' => 'open']);

            return $message;
        });

        return response()->json($message, 201);
    }

    public function releaseToAi(Request $request, SupportConversation $conversation): JsonResponse
    {
        abort_unless($conversation->contact()->where('channel', 'platform')->exists(), 422);
        [$conversation, $latest] = DB::transaction(function () use ($conversation) {
            $locked = SupportConversation::lockForUpdate()->findOrFail($conversation->id);
            $locked->update([
                'mode' => 'ai',
                'status' => 'open',
                'assigned_admin_id' => null,
                'human_requested_at' => null,
                'resolved_at' => null,
            ]);

            return [$locked, $locked->messages()->latest('id')->first()];
        });
        if ($latest?->direction === 'inbound') {
            ProcessSupportMessage::dispatch($latest->id);
        }

        return response()->json($conversation->fresh(['contact.user', 'assignedAdmin']));
    }

    public function attachment(SupportMessageAttachment $attachment): StreamedResponse
    {
        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function metrics(): JsonResponse
    {
        $byStatus = SupportConversation::selectRaw('status, COUNT(*) as count')->groupBy('status')->get();
        $byCategory = SupportConversation::selectRaw('category, COUNT(*) as count')->groupBy('category')->get();
        $today = now(config('support.timezone'))->toDateString();
        $usage = SupportDailyUsage::whereDate('usage_date', $today)
            ->selectRaw('COALESCE(SUM(replies_used), 0) as replies, COALESCE(SUM(input_tokens), 0) as input_tokens, COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->first();

        return response()->json([
            'byStatus' => $byStatus,
            'byCategory' => $byCategory,
            'today' => [
                'date' => $today,
                'aiReplies' => (int) ($usage?->replies ?? 0),
                'inputTokens' => (int) ($usage?->input_tokens ?? 0),
                'outputTokens' => (int) ($usage?->output_tokens ?? 0),
            ],
        ]);
    }
}
