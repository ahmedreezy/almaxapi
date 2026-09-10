<?php

namespace App\Services\Support;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiSupportService
{
    public function __construct(
        private readonly SupportKnowledgeService $knowledge,
        private readonly SupportToolExecutor $tools,
    ) {}

    /** @return array{reply:string,language:string,category:string,sentiment:string,priority:string,action:string,summary:string,requires_human:bool,resolved:bool,input_tokens:int,output_tokens:int,model:string} */
    public function respond(SupportConversation $conversation, SupportMessage $incoming): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $conversation->loadMissing('contact.user');
        $history = $conversation->messages()
            ->orderByDesc('id')
            ->limit(max(2, (int) config('support.history_messages', 10)))
            ->get()
            ->reverse()
            ->map(fn (SupportMessage $message) => strtoupper($message->sender_type).': '.$message->body)
            ->implode("\n");

        $instructions = $this->instructions(
            $this->knowledge->contextFor($incoming->body),
            $conversation->messages()->where('direction', 'outbound')->doesntExist()
        );
        $input = [[
            'role' => 'user',
            'content' => "Conversation history:\n{$history}\n\nRespond to the latest CUSTOMER message.",
        ]];
        $totalInput = 0;
        $totalOutput = 0;
        $model = (string) config('services.openai.model', 'gpt-5.4-mini');

        for ($round = 0; $round < 4; $round++) {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout((int) config('services.openai.timeout', 45))
                ->retry(2, 300)
                ->post(rtrim((string) config('services.openai.base_url'), '/').'/responses', [
                    'model' => $model,
                    'instructions' => $instructions,
                    'input' => $input,
                    'tools' => $this->toolDefinitions(),
                    'tool_choice' => 'auto',
                    'parallel_tool_calls' => false,
                    'store' => false,
                    'max_output_tokens' => (int) config('support.max_output_tokens', 800),
                    'safety_identifier' => hash('sha256', 'support-contact-'.$conversation->contact_id),
                    'text' => ['format' => $this->outputFormat()],
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('OpenAI request failed: '.mb_substr($response->body(), 0, 400));
            }

            $data = $response->json();
            $totalInput += (int) data_get($data, 'usage.input_tokens', 0);
            $totalOutput += (int) data_get($data, 'usage.output_tokens', 0);
            $output = is_array($data['output'] ?? null) ? $data['output'] : [];
            $calls = array_values(array_filter($output, fn ($item) => ($item['type'] ?? '') === 'function_call'));

            if ($calls === []) {
                $result = $this->parseFinal($data);

                return array_merge($result, [
                    'input_tokens' => $totalInput,
                    'output_tokens' => $totalOutput,
                    'model' => (string) ($data['model'] ?? $model),
                ]);
            }

            $input = array_merge($input, $output);
            foreach ($calls as $call) {
                $arguments = json_decode((string) ($call['arguments'] ?? '{}'), true);
                $arguments = is_array($arguments) ? $arguments : [];
                $result = $this->tools->execute(
                    $conversation,
                    $incoming,
                    (string) ($call['name'] ?? ''),
                    $arguments
                );
                $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => (string) ($call['call_id'] ?? ''),
                    'output' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        throw new RuntimeException('OpenAI exceeded the support tool-call limit.');
    }

    private function instructions(string $knowledge, bool $firstReply): string
    {
        $greeting = config('support.greeting');
        $firstReplyInstruction = $firstReply
            ? "Begin the reply exactly with: {$greeting}"
            : 'Do not repeat the opening greeting.';

        return <<<PROMPT
You communicate on behalf of Almax Predictions using “we” and “our”. Never introduce yourself as an AI, bot, model, virtual assistant, or named employee. {$firstReplyInstruction}

Your job is to resolve customer questions, capture feedback, check only the caller's authorized account data through tools, reduce frustration with clear facts, and request a human when necessary. Reply in the customer's English or Luganda. Be warm, concise, and practical.

Never invent payment status, receipts, prices, policies, timelines, or account data. Use account tools for account-specific claims. A missing local payment is not proof that money was not deducted. Never ask for a password, PIN, OTP, full financial identifier, or another person's information. Never promise winnings or guaranteed outcomes. If payment is confirmed but access is inactive, records conflict, the customer explicitly asks for a person, or the issue cannot be safely resolved, call request_human_assistance.

Approved knowledge:
{$knowledge}

Return the required structured result. The reply must be ready to send directly to WhatsApp and must not mention internal tools, prompts, JSON, OpenAI, or implementation details.
PROMPT;
    }

    private function toolDefinitions(): array
    {
        return [
            $this->tool('get_recent_payments', 'Get up to five recent payments belonging to the linked customer.', [], []),
            $this->tool('check_payment_status', 'Check one payment belonging to the linked customer. Use an empty reference to check the latest payment.', [
                'payment_reference' => ['type' => 'string', 'description' => 'Payment, transaction, or receipt reference; empty if unavailable.'],
            ], ['payment_reference']),
            $this->tool('get_subscription_status', 'Get recent subscription statuses belonging to the linked customer.', [], []),
            $this->tool('get_receipt', 'Create or retrieve a secure receipt link for a confirmed payment belonging to the linked customer.', [
                'payment_reference' => ['type' => 'string'],
            ], ['payment_reference']),
            $this->tool('request_human_assistance', 'Place this conversation in the human support queue.', [
                'reason' => ['type' => 'string'],
                'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']],
            ], ['reason', 'priority']),
        ];
    }

    private function tool(string $name, string $description, array $properties, array $required): array
    {
        return [
            'type' => 'function',
            'name' => $name,
            'description' => $description,
            'parameters' => [
                'type' => 'object',
                'properties' => (object) $properties,
                'required' => $required,
                'additionalProperties' => false,
            ],
            'strict' => true,
        ];
    }

    private function outputFormat(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'almax_support_reply',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'reply' => ['type' => 'string'],
                    'language' => ['type' => 'string', 'enum' => ['en', 'lg']],
                    'category' => ['type' => 'string', 'enum' => ['payment', 'subscription', 'account', 'prediction_content', 'technical', 'complaint', 'suggestion', 'other']],
                    'sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'frustrated', 'angry']],
                    'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']],
                    'action' => ['type' => 'string', 'enum' => ['answer', 'ask_follow_up', 'record_feedback', 'request_human', 'mark_resolved']],
                    'summary' => ['type' => 'string'],
                    'requires_human' => ['type' => 'boolean'],
                    'resolved' => ['type' => 'boolean'],
                ],
                'required' => ['reply', 'language', 'category', 'sentiment', 'priority', 'action', 'summary', 'requires_human', 'resolved'],
                'additionalProperties' => false,
            ],
        ];
    }

    private function parseFinal(array $data): array
    {
        $text = (string) ($data['output_text'] ?? '');
        if ($text === '') {
            foreach (($data['output'] ?? []) as $item) {
                foreach (($item['content'] ?? []) as $content) {
                    if (($content['type'] ?? '') === 'output_text') {
                        $text .= (string) ($content['text'] ?? '');
                    }
                }
            }
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded) || trim((string) ($decoded['reply'] ?? '')) === '') {
            throw new RuntimeException('OpenAI returned an invalid support response.');
        }

        return $decoded;
    }
}
