<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CheckSupportConfiguration extends Command
{
    protected $signature = 'support:doctor
        {--channel=all : Validate all, platform, or whatsapp support requirements}
        {--probe-openai : Send a minimal Responses API request to verify live model access and billing}';

    protected $description = 'Check AI support configuration and optionally probe live OpenAI access without exposing credentials';

    public function handle(): int
    {
        $channel = strtolower((string) $this->option('channel'));
        if (! in_array($channel, ['all', 'platform', 'whatsapp'], true)) {
            $this->error('The --channel option must be all, platform, or whatsapp.');

            return self::FAILURE;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        $inboundUrl = (string) config('services.twilio.inbound_webhook_url');
        $statusUrl = (string) config('services.twilio.status_webhook_url');
        $contextPath = (string) config('support.system_context_path');

        $checks = [
            ['Laravel APP_KEY', (string) config('app.key') !== '', 'Run php artisan key:generate.'],
            ['Database connection', $this->databaseReady(), 'Run php artisan migrate.'],
            ['Support tables', $this->supportTablesReady(), 'Run php artisan migrate.'],
            ['Queue connection', config('queue.default') === 'database', 'Set QUEUE_CONNECTION=database.'],
            ['OpenAI API key', (string) config('services.openai.api_key') !== '', 'Set an API project key with billing enabled.'],
            ['OpenAI model', (string) config('services.openai.model') !== '', 'Set OPENAI_MODEL=gpt-5.4-mini.'],
            ['Almax system context', $contextPath !== '' && File::isFile($contextPath) && trim(File::get($contextPath)) !== '', 'Restore resources/support/almax.md or set SUPPORT_SYSTEM_CONTEXT_PATH.'],
        ];

        if (in_array($channel, ['all', 'whatsapp'], true)) {
            $checks = array_merge($checks, [
                ['Public HTTPS APP_URL', str_starts_with($appUrl, 'https://'), 'Set APP_URL to the current public HTTPS origin.'],
                ['Twilio Account SID', preg_match('/^AC[a-fA-F0-9]{32}$/', (string) config('services.twilio.account_sid')) === 1, 'Set the normal Twilio Account SID.'],
                ['Twilio primary Auth Token', (string) config('services.twilio.auth_token') !== '', 'Set the primary Twilio Auth Token.'],
                ['Twilio WhatsApp sender', preg_match('/^\+[1-9]\d{7,14}$/', (string) config('services.twilio.whatsapp_from')) === 1, 'Set the sender in E.164 form.'],
                ['Inbound webhook URL', $this->validWebhook($inboundUrl, $appUrl, '/api/support/twilio/inbound'), 'Use the exact public HTTPS inbound URL.'],
                ['Status webhook URL', $this->validWebhook($statusUrl, $appUrl, '/api/support/twilio/status'), 'Use the exact public HTTPS status URL.'],
            ]);
        }

        $didProbeOpenAi = (bool) $this->option('probe-openai');
        if ($didProbeOpenAi) {
            $checks[] = $this->probeOpenAi();
        }

        $failed = false;
        $rows = array_map(function (array $check) use (&$failed) {
            [$name, $passes, $guidance] = $check;
            $failed = $failed || ! $passes;

            return [$passes ? 'PASS' : 'FAIL', $name, $passes ? 'Ready' : $guidance];
        }, $checks);

        $this->table(['Result', 'Check', 'Next action'], $rows);

        if ($failed) {
            $this->newLine();
            $this->warn($didProbeOpenAi
                ? 'Support is not ready for a live end-to-end test. The OpenAI probe was attempted without exposing credentials.'
                : 'Support is not ready for a live end-to-end test. No external requests were sent.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info($didProbeOpenAi
            ? 'Support configuration and live OpenAI access are ready for an end-to-end test.'
            : 'Support configuration is ready for a live end-to-end test. No external requests were sent.');

        return self::SUCCESS;
    }

    /** @return array{string, bool, string} */
    private function probeOpenAi(): array
    {
        $apiKey = (string) config('services.openai.api_key');
        $model = (string) config('services.openai.model');
        if ($apiKey === '' || $model === '') {
            return ['OpenAI live probe', false, 'Configure the OpenAI API key and model first.'];
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(min(30, max(5, (int) config('services.openai.timeout', 20))))
                ->post(rtrim((string) config('services.openai.base_url'), '/').'/responses', [
                    'model' => $model,
                    'input' => 'Reply with OK.',
                    'store' => false,
                    'max_output_tokens' => 32,
                ]);

            if ($response->successful()) {
                return ['OpenAI live probe', true, 'Ready'];
            }

            $code = (string) data_get($response->json(), 'error.code', 'unknown_error');

            return [
                'OpenAI live probe',
                false,
                sprintf('OpenAI returned HTTP %d (%s). Check the key, model access, billing, and project limits.', $response->status(), $code),
            ];
        } catch (Throwable $exception) {
            return ['OpenAI live probe', false, 'OpenAI could not be reached: '.$exception->getMessage()];
        }
    }

    private function databaseReady(): bool
    {
        try {
            return Schema::hasTable('migrations');
        } catch (Throwable) {
            return false;
        }
    }

    private function supportTablesReady(): bool
    {
        try {
            return Schema::hasTable('support_contacts')
                && Schema::hasTable('support_conversations')
                && Schema::hasTable('support_messages')
                && Schema::hasTable('support_knowledge_articles');
        } catch (Throwable) {
            return false;
        }
    }

    private function validWebhook(string $url, string $appUrl, string $path): bool
    {
        return $appUrl !== ''
            && str_starts_with($appUrl, 'https://')
            && $url === $appUrl.$path;
    }
}
