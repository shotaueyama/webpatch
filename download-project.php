<?php

require __DIR__ . '/_app.php';

$user = require_user();
$projectRef = (string) ($_GET['id'] ?? '');
$project = find_project_for_user_ref($projectRef, (int) $user['id']);

if ($project === null) {
    http_response_code(404);
    exit('Not found');
}
if (project_is_url_source($project)) {
    http_response_code(403);
    exit('Forbidden');
}

try {
    $root = realpath(project_root($project));
    if ($root === false || !is_dir($root)) {
        throw new RuntimeException('プロジェクトファイルが見つかりません。');
    }

    $zipPath = tempnam(sys_get_temp_dir(), 'webpatch_project_');
    if ($zipPath === false) {
        throw new RuntimeException('ZIPファイルを作成できませんでした。');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
        @unlink($zipPath);
        throw new RuntimeException('ZIPファイルを作成できませんでした。');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $fullPath = $file->getPathname();
        $relative = ltrim(str_replace($root, '', $fullPath), DIRECTORY_SEPARATOR);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        normalize_zip_path($relative);
        if (is_rejected_extension($relative)) {
            continue;
        }
        $zip->addFile($fullPath, $relative);
    }
    $zip->close();

    $size = filesize($zipPath);
    if ($size === false) {
        throw new RuntimeException('ZIPファイルを読み込めませんでした。');
    }

    $title = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $project['title']), '._-');
    if ($title === '') {
        $title = 'webpatch_project_' . (int) $project['id'];
    }
    $downloadName = $title . '.zip';

    header('Content-Type: application/zip');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . $size);
    header('Content-Disposition: attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode((string) $project['title'] . '.zip'));
    readfile($zipPath);
    @unlink($zipPath);
} catch (Throwable $e) {
    if (isset($zipPath) && is_string($zipPath)) {
        @unlink($zipPath);
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Download unavailable';
}
