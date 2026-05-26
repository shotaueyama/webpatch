<?php

require __DIR__ . '/_app.php';

$token = (string) ($_GET['token'] ?? '');
$project = public_project_for_token($token);

header('X-Robots-Tag: noindex, nofollow, noarchive');

if ($project === null) {
    http_response_code(404);
    exit('Not found');
}

try {
    $file = normalize_zip_path((string) ($_GET['file'] ?? $project['entry_file']));
    $path = safe_project_file($project, $file);
    if (!is_html_file($file)) {
        throw new RuntimeException('HTMLファイルではありません。');
    }

    $route = static function (int|string $projectId, string $assetFile, bool $isLink) use ($token): string {
        $path = is_html_file($assetFile) && $isLink ? 'public-preview.php' : 'public-asset.php';
        return base_url($path . '?token=' . rawurlencode($token) . '&file=' . rawurlencode($assetFile));
    };

    header('Content-Type: text/html; charset=UTF-8');
    header("Content-Security-Policy: default-src 'self' https: data: blob:; img-src 'self' https: data: blob:; style-src 'self' https: 'unsafe-inline'; font-src 'self' https: data:; media-src 'self' https: data: blob:; script-src 'self' https: 'unsafe-inline' 'unsafe-eval'; connect-src 'self' https:; base-uri 'self'");
    header('X-Content-Type-Options: nosniff');

    echo rewrite_html_for_preview((string) file_get_contents($path), $project, $file, $route);
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Preview unavailable';
}
