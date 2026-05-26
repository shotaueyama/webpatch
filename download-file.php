<?php

require __DIR__ . '/_app.php';

$user = require_user();
$projectRef = (string) ($_GET['id'] ?? '');
$file = (string) ($_GET['file'] ?? '');
$project = find_project_for_user_ref($projectRef, (int) $user['id']);

if ($project === null) {
    http_response_code(404);
    exit('Not found');
}
if (project_is_url_source($project)) {
    http_response_code(403);
    exit('Forbidden');
}
if (!user_owns_project($project, (int) $user['id'])) {
    http_response_code(403);
    exit('Forbidden');
}

try {
    if ($file === '') {
        $file = (string) $project['entry_file'];
    }
    $normalized = normalize_zip_path($file);
    if (!is_html_file($normalized)) {
        throw new RuntimeException('HTMLファイルではありません。');
    }

    $path = safe_project_file($project, $normalized);
    $size = filesize($path);
    if ($size === false) {
        throw new RuntimeException('ファイルサイズを取得できません。');
    }

    $basename = basename($normalized);
    $fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename) ?: 'webpatch.html';

    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . $size);
    header('Content-Disposition: attachment; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($basename));
    readfile($path);
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Download unavailable';
}
