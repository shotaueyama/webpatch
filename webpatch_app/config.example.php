<?php

return [
    'base_url' => '/webpatch',
    'storage_root' => '/var/www/cognify/webpatch_storage',
    'key_encryption_secret' => 'change-me-to-a-random-64-character-secret',
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=quadra_cognify;charset=utf8mb4',
        'user' => 'webpatch_app',
        'password' => 'change-me',
        'table_prefix' => 'webpatch_',
    ],
];
