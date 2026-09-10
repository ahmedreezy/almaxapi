<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mobile_money' => [
        'api_url'       => env('MOBILE_MONEY_API_URL', env('JPESA_API_URL', '')),
        'api_key'       => env('MOBILE_MONEY_API_KEY', env('JPESA_API_KEY', '')),
        'api_secret'    => env('MOBILE_MONEY_API_SECRET', ''),
        'webhook_secret'=> env('MOBILE_MONEY_WEBHOOK_SECRET', ''),
        'callback_url'  => env('MOBILE_MONEY_CALLBACK_URL', env('JPESA_CALLBACK_URL', '')),
        'agent_commission' => [
            'enabled'          => env('JPESA_AGENT_COMMISSION_ENABLED', false),
            'ratio'            => env('JPESA_AGENT_COMMISSION_RATIO', 0.2),
            'recipient_type'   => env('JPESA_AGENT_COMMISSION_RECIPIENT_TYPE', 'business'),
            'recipient_email'  => env('JPESA_AGENT_COMMISSION_RECIPIENT_EMAIL', ''),
            'recipient_mobile' => env('JPESA_AGENT_COMMISSION_RECIPIENT_MOBILE', ''),
            'transfer_action'  => env('JPESA_AGENT_COMMISSION_TRANSFER_ACTION', 'debit'),
            'transfer_pt'      => env('JPESA_AGENT_COMMISSION_TRANSFER_PT', 'gwallet'),
            'currency'         => env('JPESA_AGENT_COMMISSION_CURRENCY', 'UGX'),
        ],
    ],

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID', ''),
        'auth_token' => env('TWILIO_AUTH_TOKEN', ''),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM', ''),
        'inbound_webhook_url' => env('TWILIO_INBOUND_WEBHOOK_URL', ''),
        'status_webhook_url' => env('TWILIO_STATUS_WEBHOOK_URL', ''),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),
        'model' => env('OPENAI_MODEL', 'gpt-5.4-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('OPENAI_TIMEOUT_SECONDS', 45),
    ],

];
