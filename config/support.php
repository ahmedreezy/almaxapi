<?php

return [
    'system_context_path' => env('SUPPORT_SYSTEM_CONTEXT_PATH') ?: resource_path('support/almax.md'),
    'timezone' => env('SUPPORT_TIMEZONE', 'Africa/Kampala'),
    'daily_reply_limit' => (int) env('SUPPORT_DAILY_REPLY_LIMIT', 10),
    'max_output_tokens' => (int) env('SUPPORT_MAX_OUTPUT_TOKENS', 800),
    'history_messages' => (int) env('SUPPORT_HISTORY_MESSAGES', 10),
    'knowledge_articles' => (int) env('SUPPORT_KNOWLEDGE_ARTICLES', 8),
    'retention_days' => (int) env('SUPPORT_MESSAGE_RETENTION_DAYS', 365),
    'job_timeout' => (int) env('SUPPORT_JOB_TIMEOUT_SECONDS', 240),
    'job_tries' => (int) env('SUPPORT_JOB_TRIES', 2),
    'limit_message' => 'You have reached today\'s automated reply limit. Please try again tomorrow when your AI support allowance resets.',
    'fallback_message' => 'We could not complete that automated check right now. Please send your message again in a moment so we can retry.',
    'text_only_message' => 'Please send your feedback or question as a text message so we can assist you.',
];
