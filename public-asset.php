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
    $file = normalize_zip_path((string) ($_GET['file'] ?? ''));
    if (is_html_file($file)) {
        header('Location: ' . base_url('public-preview.php?token=' . rawurlencode($token) . '&file=' . rawurlencode($file)), true, 302);
        exit;
    }

    $path = safe_project_file($project, $file);
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimeMap = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'mjs' => 'application/javascript; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $mimeMap[$extension] ?? ($finfo->file($path) ?: 'application/octet-stream');

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');

    if ($extension === 'css') {
        $css = (string) file_get_contents($path);
        $css = preg_replace_callback('/url\\(([^)]+)\\)/i', static function (array $matches) use ($project, $file, $token): string {
            $raw = trim($matches[1], " \t\n\r\0\x0B'\"");
            if ($raw === '' || preg_match('/^(?:https?:|data:|blob:|#)/i', $raw)) {
                return $matches[0];
            }
            $resolved = resolve_relative_file($file, $raw);
            return 'url("' . base_url('public-asset.php?token=' . rawurlencode($token) . '&file=' . rawurlencode($resolved)) . '")';
        }, $css) ?? $css;
        echo $css;
        exit;
    }

    readfile($path);
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Asset unavailable';
}
