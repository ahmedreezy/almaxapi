<?php

return [
    'system_context_path' => env('SUPPORT_SYSTEM_CONTEXT_PATH') ?: resource_path('support/almax.md'),
    'timezone' => env('SUPPORT_TIMEZONE', 'Africa/Kampala'),
    'daily_reply_limit' => (int) env('SUPPORT_DAILY_REPLY_LIMIT', 10),
    'max_output_tokens' => min(350, max(128, (int) env('SUPPORT_MAX_OUTPUT_TOKENS', 350))),
    'history_messages' => (int) env('SUPPORT_HISTORY_MESSAGES', 10),
    'knowledge_articles' => (int) env('SUPPORT_KNOWLEDGE_ARTICLES', 8),
    'knowledge_fast_path_confidence' => min(1, max(0.7, (float) env('SUPPORT_KNOWLEDGE_FAST_PATH_CONFIDENCE', 0.76))),
    'platform_sync' => filter_var(env('SUPPORT_PLATFORM_SYNC', true), FILTER_VALIDATE_BOOL),
    'platform_sync_budget_seconds' => min(15, max(5, (int) env('SUPPORT_PLATFORM_SYNC_BUDGET_SECONDS', 12))),
    'retention_days' => (int) env('SUPPORT_MESSAGE_RETENTION_DAYS', 365),
    'job_timeout' => min(60, max(30, (int) env('SUPPORT_JOB_TIMEOUT_SECONDS', 60))),
    'job_tries' => 1,
    'openai_budget_seconds' => min(50, max(10, (int) env('SUPPORT_OPENAI_BUDGET_SECONDS', 50))),
    'openai_max_rounds' => min(3, max(1, (int) env('SUPPORT_OPENAI_MAX_ROUNDS', 3))),
    'limit_message' => 'You have reached today\'s automated reply limit. Please try again tomorrow when your AI support allowance resets.',
    'fallback_message' => 'We could not complete that automated check right now. Please send your message again in a moment so we can retry.',
    'text_only_message' => 'Please send your feedback or question as a text message so we can assist you.',
];
