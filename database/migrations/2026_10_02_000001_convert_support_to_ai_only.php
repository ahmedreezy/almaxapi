<?php

use App\Jobs\ProcessSupportMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_conversations')) {
            return;
        }

        $legacyConversationIds = DB::table('support_conversations')
            ->where(function ($query) {
                $query->whereIn('mode', ['waiting_human', 'human'])
                    ->orWhereIn('status', ['waiting_human', 'human']);
            })
            ->pluck('id');

        DB::table('support_conversations')
            ->whereIn('id', $legacyConversationIds)
            ->update([
                'mode' => 'ai',
                'status' => 'open',
                'assigned_admin_id' => null,
                'human_requested_at' => null,
                'resolved_at' => null,
                'updated_at' => now(),
            ]);

        foreach ($legacyConversationIds as $conversationId) {
            $latestMessage = DB::table('support_messages')
                ->where('conversation_id', $conversationId)
                ->orderByDesc('id')
                ->first(['id', 'direction']);

            if ($latestMessage?->direction === 'inbound') {
                ProcessSupportMessage::dispatch((int) $latestMessage->id)->afterCommit();
            }
        }
    }

    public function down(): void
    {
        // Historical human-assignment state cannot be reconstructed safely.
    }
};
