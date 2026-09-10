<?php

return [
    'timezone' => env('SUPPORT_TIMEZONE', 'Africa/Kampala'),
    'daily_reply_limit' => (int) env('SUPPORT_DAILY_REPLY_LIMIT', 10),
    'max_output_tokens' => (int) env('SUPPORT_MAX_OUTPUT_TOKENS', 800),
    'history_messages' => (int) env('SUPPORT_HISTORY_MESSAGES', 10),
    'knowledge_articles' => (int) env('SUPPORT_KNOWLEDGE_ARTICLES', 8),
    'retention_days' => (int) env('SUPPORT_MESSAGE_RETENTION_DAYS', 365),
    'greeting' => 'Hello, this is Almax Predictions. How can we help you today?',
    'limit_message' => 'You have reached today\'s automated reply limit. We have saved your messages and a member of our team can continue assisting you.',
    'fallback_message' => 'We have saved your message, but we cannot complete the automated check right now. A member of our team will assist you.',
    'text_only_message' => 'Please send your feedback or question as a text message so we can assist you.',
];
