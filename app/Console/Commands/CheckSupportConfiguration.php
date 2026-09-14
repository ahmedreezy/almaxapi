<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class CheckSupportConfiguration extends Command
{
    protected $signature = 'support:doctor';

    protected $description = 'Check WhatsApp AI support configuration without exposing credentials or sending requests';

    public function handle(): int
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $inboundUrl = (string) config('services.twilio.inbound_webhook_url');
        $statusUrl = (string) config('services.twilio.status_webhook_url');
        $contextPath = (string) config('support.system_context_path');

        $checks = [
            ['Laravel APP_KEY', (string) config('app.key') !== '', 'Run php artisan key:generate.'],
            ['Database connection', $this->databaseReady(), 'Run php artisan migrate.'],
            ['Support tables', $this->supportTablesReady(), 'Run php artisan migrate.'],
            ['Queue connection', config('queue.default') === 'database', 'Set QUEUE_CONNECTION=database.'],
            ['Public HTTPS APP_URL', str_starts_with($appUrl, 'https://'), 'Set APP_URL to the current HTTPS tunnel origin.'],
            ['Twilio Account SID', preg_match('/^AC[a-fA-F0-9]{32}$/', (string) config('services.twilio.account_sid')) === 1, 'Set the normal Twilio Account SID.'],
            ['Twilio primary Auth Token', (string) config('services.twilio.auth_token') !== '', 'Set the newly rotated primary Auth Token.'],
            ['Twilio WhatsApp sender', preg_match('/^\+[1-9]\d{7,14}$/', (string) config('services.twilio.whatsapp_from')) === 1, 'Set the Sandbox sender in E.164 form.'],
            ['Inbound webhook URL', $this->validWebhook($inboundUrl, $appUrl, '/api/support/twilio/inbound'), 'Use the exact HTTPS tunnel inbound URL.'],
            ['Status webhook URL', $this->validWebhook($statusUrl, $appUrl, '/api/support/twilio/status'), 'Use the exact HTTPS tunnel status URL.'],
            ['OpenAI API key', (string) config('services.openai.api_key') !== '', 'Set an API project key with billing enabled.'],
            ['OpenAI model', (string) config('services.openai.model') !== '', 'Set OPENAI_MODEL=gpt-5.4-mini.'],
            ['Almax system context', $contextPath !== '' && File::isFile($contextPath) && trim(File::get($contextPath)) !== '', 'Restore resources/support/almax.md or set SUPPORT_SYSTEM_CONTEXT_PATH.'],
        ];

        $failed = false;
        $rows = array_map(function (array $check) use (&$failed) {
            [$name, $passes, $guidance] = $check;
            $failed = $failed || ! $passes;

            return [$passes ? 'PASS' : 'FAIL', $name, $passes ? 'Ready' : $guidance];
        }, $checks);

        $this->table(['Result', 'Check', 'Next action'], $rows);

        if ($failed) {
            $this->newLine();
            $this->warn('Support is not ready for a live end-to-end test. No external requests were sent.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Support configuration is ready for a live end-to-end test. No external requests were sent.');

        return self::SUCCESS;
    }

    private function databaseReady(): bool
    {
        try {
            return Schema::hasTable('migrations');
        } catch (\Throwable) {
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
        } catch (\Throwable) {
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
