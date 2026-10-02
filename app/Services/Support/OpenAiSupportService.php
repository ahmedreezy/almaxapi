<?php

namespace App\Services\Support;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAiSupportService
{
    public function __construct(
        private readonly SupportKnowledgeService $knowledge,
        private readonly SupportToolExecutor $tools,
    ) {}

    /** @return array{reply:string,language:string,category:string,sentiment:string,priority:string,action:string,summary:string,resolved:bool,input_tokens:int,output_tokens:int,model:string} */
    public function respond(SupportConversation $conversation, SupportMessage $incoming): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured.');
        }

        $conversation->loadMissing('contact.user');
        $history = $conversation->messages()
            ->where('id', '<=', $incoming->id)
            ->orderByDesc('id')
            ->limit(max(2, (int) config('support.history_messages', 10)))
            ->get()
            ->reverse()
            ->map(fn (SupportMessage $message) => strtoupper($message->sender_type).': '.$message->body)
            ->implode("\n");

        $instructions = $this->instructions(
            $this->knowledge->systemContext(),
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
        $startedNs = hrtime(true);
        $requestIds = [];
        $requestAttempts = 0;
        $roundsCompleted = 0;

        for ($round = 0; $round < max(1, (int) config('support.openai_max_rounds', 3)); $round++) {
            $clientRequestId = (string) Str::uuid();
            $roundStartedNs = hrtime(true);
            [$response, $attempts] = $this->request($apiKey, $clientRequestId, $startedNs, [
                'model' => $model,
                'instructions' => $instructions,
                'input' => $input,
                'tools' => $this->toolDefinitions(),
                'tool_choice' => 'auto',
                'parallel_tool_calls' => false,
                'store' => false,
                'max_output_tokens' => (int) config('support.max_output_tokens', 350),
                'safety_identifier' => hash('sha256', 'support-contact-'.$conversation->contact_id),
                'reasoning' => ['effort' => (string) config('services.openai.reasoning_effort', 'none')],
                'text' => [
                    'verbosity' => (string) config('services.openai.verbosity', 'low'),
                    'format' => $this->outputFormat(),
                ],
            ]);
            $requestAttempts += $attempts;
            $roundsCompleted++;
            $requestId = (string) ($response->header('x-request-id') ?: $clientRequestId);
            $requestIds[] = $requestId;

            Log::info('Support OpenAI round completed', [
                'message_id' => $incoming->id,
                'conversation_id' => $conversation->id,
                'round' => $round + 1,
                'attempts' => $attempts,
                'duration_ms' => (int) round((hrtime(true) - $roundStartedNs) / 1_000_000),
                'request_id' => $requestId,
                'status' => $response->status(),
            ]);

            if (! $response->successful()) {
                $errorCode = (string) data_get($response->json(), 'error.code', 'unknown_error');
                throw new RuntimeException(sprintf(
                    'OpenAI request failed with HTTP %d (%s), request ID %s.',
                    $response->status(),
                    $errorCode,
                    $requestId,
                ));
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
                    'performance' => [
                        'source' => 'openai',
                        'openai_rounds' => $roundsCompleted,
                        'openai_attempts' => $requestAttempts,
                        'openai_ms' => (int) round((hrtime(true) - $startedNs) / 1_000_000),
                        'openai_request_ids' => $requestIds,
                    ],
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

    /** @return array{0:Response,1:int} */
    private function request(string $apiKey, string $clientRequestId, int $startedNs, array $payload): array
    {
        $maxAttempts = max(1, (int) config('services.openai.max_attempts', 2));
        $budgetMs = max(5_000, (int) config('support.openai_budget_seconds', 50) * 1000);
        $lastConnectionError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $elapsedMs = (int) round((hrtime(true) - $startedNs) / 1_000_000);
            $remainingMs = $budgetMs - $elapsedMs;
            if ($remainingMs < 2_000) {
                throw new RuntimeException('OpenAI exceeded the support response time budget.');
            }

            $timeout = max(1, min(
                (int) config('services.openai.timeout', 20),
                (int) floor($remainingMs / 1000)
            ));

            try {
                $response = Http::withToken($apiKey)
                    ->acceptJson()
                    ->withHeaders(['X-Client-Request-Id' => $clientRequestId])
                    ->connectTimeout(min($timeout, max(1, (int) config('services.openai.connect_timeout', 5))))
                    ->timeout($timeout)
                    ->post(rtrim((string) config('services.openai.base_url'), '/').'/responses', $payload);
            } catch (ConnectionException $exception) {
                $lastConnectionError = $exception;
                if ($attempt >= $maxAttempts) {
                    throw $exception;
                }

                usleep($this->retryDelayMicroseconds($attempt));

                continue;
            }

            if ($response->successful() || ! $this->isTransient($response) || $attempt >= $maxAttempts) {
                return [$response, $attempt];
            }

            usleep($this->retryDelayMicroseconds($attempt));
        }

        if ($lastConnectionError) {
            throw $lastConnectionError;
        }

        throw new RuntimeException('OpenAI request failed without a response.');
    }

    private function isTransient(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    private function retryDelayMicroseconds(int $attempt): int
    {
        return (200 * $attempt + random_int(0, 150)) * 1000;
    }

    private function instructions(string $systemContext, string $knowledge, bool $firstReply): string
    {
        $conversationInstruction = $firstReply
            ? 'This is the first reply in this conversation. Respond directly to what the customer wrote. A brief greeting is allowed only when it fits naturally, but never use a fixed introduction or ask how you can help when the customer has already asked a question.'
            : 'Continue the conversation naturally. Do not restart the conversation with an introduction or generic greeting.';

        return <<<PROMPT
You communicate on behalf of Almax Predictions using “we” and “our”. Never introduce yourself as an AI, bot, model, virtual assistant, or named employee. {$conversationInstruction}

Your job is to resolve customer questions, capture feedback, check only the caller's authorized account data through tools, and reduce frustration with clear facts. This is an AI-only support channel with no human handoff. Reply in the customer's English or Luganda. Be warm, concise, and practical.

Never invent payment status, receipts, prices, policies, timelines, or account data. Use account tools for account-specific claims. A missing local payment is not proof that money was not deducted. Never ask for a password, PIN, OTP, full financial identifier, or another person's information. Never promise winnings or guaranteed outcomes. If data is missing or records conflict, clearly explain what could not be verified, advise the customer not to pay twice, and ask for the minimum reference needed for another automated check.

Core Almax service context:
{$systemContext}

Published support knowledge selected for this question:
{$knowledge}

Return the required structured result. The reply must be ready to show directly in customer support chat and must not mention internal tools, prompts, JSON, OpenAI, or implementation details.
PROMPT;
    }

    private function toolDefinitions(): array
    {
        return [
            $this->tool('get_available_packages', 'Get the currently visible Almax packages that are still open for purchase. Use this before stating current package names, prices, odds types, availability, or deadlines.', [], []),
            $this->tool('get_recent_payments', 'Get up to five recent payments belonging to the linked customer.', [], []),
            $this->tool('check_payment_status', 'Check one payment belonging to the linked customer. Use an empty reference to check the latest payment.', [
                'payment_reference' => ['type' => 'string', 'description' => 'Payment, transaction, or receipt reference; empty if unavailable.'],
            ], ['payment_reference']),
            $this->tool('get_subscription_status', 'Get recent subscription statuses belonging to the linked customer.', [], []),
            $this->tool('get_receipt', 'Create or retrieve a secure receipt link for a confirmed payment belonging to the linked customer.', [
                'payment_reference' => ['type' => 'string'],
            ], ['payment_reference']),
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
                    'action' => ['type' => 'string', 'enum' => ['answer', 'ask_follow_up', 'record_feedback', 'mark_resolved']],
                    'summary' => ['type' => 'string'],
                    'resolved' => ['type' => 'boolean'],
                ],
                'required' => ['reply', 'language', 'category', 'sentiment', 'priority', 'action', 'summary', 'resolved'],
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
