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
    $file = normalize_zip_path((string) ($_GET['file'] ?? ''));
    if (is_html_file($file)) {
        redirect_to('preview.php?id=' . rawurlencode(project_public_ref($project)) . '&file=' . rawurlencode($file));
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
        $css = preg_replace_callback('/url\\(([^)]+)\\)/i', static function (array $matches) use ($project, $file): string {
            $raw = trim($matches[1], " \t\n\r\0\x0B'\"");
            if ($raw === '' || preg_match('/^(?:https?:|data:|blob:|#)/i', $raw)) {
                return $matches[0];
            }
            $resolved = resolve_relative_file($file, $raw);
            return 'url("' . base_url('asset.php?id=' . rawurlencode(project_public_ref($project)) . '&file=' . rawurlencode($resolved)) . '")';
        }, $css) ?? $css;
        echo $css;
        exit;
    }

    $size = filesize($path);
    if ($size === false) {
        throw new RuntimeException('ファイルサイズを取得できません。');
    }

    header('Accept-Ranges: bytes');

    $start = 0;
    $end = $size - 1;
    $status = 200;

    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if (is_string($range) && preg_match('/bytes=(\\d*)-(\\d*)/', $range, $matches)) {
        if ($matches[1] === '' && $matches[2] !== '') {
            $suffixLength = min((int) $matches[2], $size);
            $start = $size - $suffixLength;
        } elseif ($matches[1] !== '') {
            $start = (int) $matches[1];
        }

        if ($matches[2] !== '') {
            $end = min((int) $matches[2], $size - 1);
        }

        if ($start > $end || $start < 0 || $end >= $size) {
            header('HTTP/1.1 416 Range Not Satisfiable');
            header('Content-Range: bytes */' . $size);
            exit;
        }

        $status = 206;
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    $length = $end - $start + 1;
    header('Content-Length: ' . $length);

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('ファイルを読み込めません。');
    }
    fseek($handle, $start);

    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunkSize = min(8192, $remaining);
        echo fread($handle, $chunkSize);
        $remaining -= $chunkSize;
        flush();
    }
    fclose($handle);
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Asset unavailable';
}
