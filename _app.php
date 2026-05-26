<?php

declare(strict_types=1);

$appRoot = realpath(__DIR__ . '/../../webpatch_app') ?: realpath(__DIR__ . '/webpatch_app');
if ($appRoot === false) {
    http_response_code(500);
    exit('WebPatch application is not configured.');
}

$GLOBALS['webpatch_app_root'] = $appRoot;

require_once $appRoot . '/bootstrap.php';

