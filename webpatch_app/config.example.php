<?php

return [
    'base_url' => '/webpatch',
    'storage_root' => '/var/www/webpatch_storage',
    'key_encryption_secret' => 'use-a-long-random-secret-string',
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=webpatch;charset=utf8mb4',
        'user' => 'webpatch_user',
        'password' => 'database-password',
        'table_prefix' => 'webpatch_',
    ],
];
