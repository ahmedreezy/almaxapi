<?php

namespace App\Services\Support;

use App\Models\SupportMessageAttachment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OpenAiMediaService
{
    public function transcribe(SupportMessageAttachment $attachment): string
    {
        if ($attachment->kind !== 'audio') {
            throw new RuntimeException('Only audio attachments can be transcribed.');
        }
        if ($attachment->transcript) {
            return $attachment->transcript;
        }

        if (blank(config('services.openai.api_key'))) {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $contents = Storage::disk($attachment->disk)->get($attachment->path);
        $attachment->update(['status' => 'processing', 'error' => null]);
        $response = Http::withToken((string) config('services.openai.api_key'))
            ->acceptJson()
            ->connectTimeout((int) config('services.openai.connect_timeout', 5))
            ->timeout((int) config('support.transcription_timeout_seconds', 45))
            ->attach('file', $contents, $attachment->original_name ?: 'voice-note.webm', [
                'Content-Type' => $attachment->mime_type,
            ])
            ->post(rtrim((string) config('services.openai.base_url'), '/').'/audio/transcriptions', [
                'model' => (string) config('services.openai.transcription_model', 'gpt-transcribe'),
                'prompt' => 'Almax Predictions customer support voice note. Likely English, Luganda, betting packages, mobile money, receipts, subscriptions, or betslip codes.',
            ]);

        if (! $response->successful() || trim((string) $response->json('text')) === '') {
            $attachment->update(['status' => 'failed', 'error' => 'Transcription service did not return text.']);
            throw new RuntimeException('Voice note transcription failed.');
        }

        $transcript = trim((string) $response->json('text'));
        $attachment->update(['status' => 'ready', 'transcript' => $transcript, 'error' => null]);

        return $transcript;
    }
}
