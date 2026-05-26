<?php

require __DIR__ . '/_app.php';

$user = require_user();
$projectRef = (string) ($_GET['id'] ?? '');
$project = find_project_for_user_ref($projectRef, (int) $user['id']);

if ($project === null) {
    http_response_code(404);
    exit('Not found');
}

try {
    $file = (string) ($_GET['file'] ?? $project['entry_file']);
    $path = safe_project_file($project, $file);
    if (!is_html_file($file)) {
        throw new RuntimeException('HTMLファイルではありません。');
    }

    header('Content-Type: text/html; charset=UTF-8');
    header("Content-Security-Policy: default-src 'self' https: data: blob:; img-src 'self' https: data: blob:; style-src 'self' https: 'unsafe-inline'; font-src 'self' https: data:; media-src 'self' https: data: blob:; script-src 'self' https: 'unsafe-inline' 'unsafe-eval'; connect-src 'self' https:; base-uri 'self'");
    header('X-Content-Type-Options: nosniff');

    echo rewrite_html_for_preview((string) file_get_contents($path), $project, normalize_zip_path($file));
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Preview unavailable';
}
