<?php
return [
    'app_url' => 'https://binprof.puskesau.com',
    'timezone' => 'Asia/Jakarta',
    'rewrite' => true,
    'root_mode_verified' => false,
    'db_dsn' => 'mysql:host=localhost;dbname=YOUR_DATABASE;charset=utf8mb4',
    'db_user' => 'YOUR_DATABASE_USER',
    'db_password' => '',
    'key_id' => 'primary',
    'keys' => ['primary' => ''],
    'lookup_pepper' => '',
    'rate_pepper' => '',
    'setup_token_hash' => '',
    'idle_seconds' => 1800,
    'absolute_seconds' => 28800,
    'whatsapp' => [
        'enabled' => false,
        'business_account_id' => '',
        'phone_number_id' => '',
        'display_phone' => '', // E.164 digits without a plus sign (optional portal link)
        'graph_version' => '', // A supported version shown in the Meta developer dashboard
        'app_secret' => getenv('BINPROF_WA_APP_SECRET') ?: '',
        'verify_token' => getenv('BINPROF_WA_VERIFY_TOKEN') ?: '',
        'access_token' => getenv('BINPROF_WA_ACCESS_TOKEN') ?: '',
        'map_template' => '', // Approved Meta template without variables
        'template_language' => 'id',
    ],
];
