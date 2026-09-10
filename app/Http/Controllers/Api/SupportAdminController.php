<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use App\Models\SupportDailyUsage;
use App\Models\SupportMessage;
use App\Services\Support\TwilioWhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:open,waiting_human,human,resolved'],
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
            'messages' => fn ($query) => $query->orderBy('id'),
        ]));
    }

    public function update(Request $request, SupportConversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['sometimes', 'string', 'in:ai,waiting_human,human'],
            'status' => ['sometimes', 'string', 'in:open,waiting_human,human,resolved'],
            'category' => ['sometimes', 'string', 'in:payment,subscription,account,prediction_content,technical,complaint,suggestion,other'],
            'priority' => ['sometimes', 'string', 'in:low,normal,high,urgent'],
            'assignedAdminId' => ['sometimes', 'nullable', 'integer', 'exists:admin_users,id'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'dailyLimit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        if (array_key_exists('dailyLimit', $data)) {
            $conversation->contact()->update(['daily_limit_override' => $data['dailyLimit']]);
            unset($data['dailyLimit']);
        }

        if (array_key_exists('assignedAdminId', $data)) {
            $data['assigned_admin_id'] = $data['assignedAdminId'];
            unset($data['assignedAdminId']);
        }
        if (($data['status'] ?? null) === 'resolved') {
            $data['resolved_at'] = now();
        } elseif (isset($data['status'])) {
            $data['resolved_at'] = null;
        }
        if (($data['mode'] ?? null) === 'human') {
            $data['status'] = 'human';
            $data['assigned_admin_id'] ??= $request->user()?->id;
        }
        if (($data['mode'] ?? null) === 'waiting_human') {
            $data['status'] = 'waiting_human';
            $data['human_requested_at'] = $conversation->human_requested_at ?? now();
        }
        if (($data['mode'] ?? null) === 'ai' && $conversation->status !== 'resolved') {
            $data['status'] = 'open';
        }

        $conversation->update($data);

        return response()->json($conversation->fresh(['contact.user', 'assignedAdmin']));
    }

    public function reply(
        Request $request,
        SupportConversation $conversation,
        TwilioWhatsAppService $twilio
    ): JsonResponse {
        $data = $request->validate(['body' => ['required', 'string', 'max:1500']]);
        $conversation->loadMissing('contact');
        $sent = $twilio->sendText(
            $conversation->contact->phone ?: $conversation->contact->external_id,
            $data['body']
        );

        $message = DB::transaction(function () use ($request, $conversation, $data, $sent) {
            $conversation->update([
                'mode' => 'human',
                'status' => 'human',
                'assigned_admin_id' => $conversation->assigned_admin_id ?: $request->user()?->id,
                'last_message_at' => now(),
            ]);

            return SupportMessage::create([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'sender_type' => 'human',
                'body' => $data['body'],
                'provider_message_id' => $sent['sid'] ?: null,
                'delivery_status' => $sent['status'],
                'sent_at' => now(),
            ]);
        });

        return response()->json($message, 201);
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
