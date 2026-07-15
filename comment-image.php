<?php

require __DIR__ . '/_app.php';

$imageId = (int) ($_GET['id'] ?? 0);
$token = (string) ($_GET['token'] ?? '');
$clientToken = (string) ($_GET['client_token'] ?? '');

if ($imageId <= 0) {
    http_response_code(404);
    exit('Not found');
}

$stmt = db()->prepare(
    'SELECT ci.*, c.project_id, c.client_share_id
       FROM ' . table_name('comment_images') . ' ci
       INNER JOIN ' . table_name('comments') . ' c ON c.id = ci.comment_id
      WHERE ci.id = ?
      LIMIT 1'
);
$stmt->execute([$imageId]);
$image = $stmt->fetch();

if (!$image) {
    http_response_code(404);
    exit('Not found');
}

$allowed = false;
if ($token !== '') {
    $project = public_project_for_token($token);
    $allowed = $project !== null
        && (int) $project['id'] === (int) $image['project_id']
        && ($image['client_share_id'] === null || $image['client_share_id'] === '');
} elseif ($clientToken !== '') {
    $project = client_project_for_token($clientToken);
    $allowed = $project !== null
        && (int) $project['id'] === (int) $image['project_id']
        && (int) $project['client_share_id'] === (int) ($image['client_share_id'] ?? 0);
} else {
    $apiToken = trim((string) ($_SERVER['HTTP_X_WEBPATCH_API_TOKEN'] ?? ''));
    if ($apiToken === '') {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', (string) $header, $matches)) {
            $apiToken = trim($matches[1]);
        }
    }
    if ($apiToken === '') {
        $apiToken = trim((string) ($_GET['api_token'] ?? ''));
    }
    if ($apiToken !== '') {
        $project = project_for_comment_sheet_api_token($apiToken);
        $allowed = $project !== null && (int) $project['id'] === (int) $image['project_id'];
    }

    $user = current_user();
    if (!$allowed && $user !== null) {
        $project = find_project_for_user((int) $image['project_id'], (int) $user['id']);
        $allowed = $project !== null;
    }
}

if (!$allowed) {
    http_response_code(403);
    exit('Forbidden');
}

$path = storage_root() . '/' . ltrim((string) $image['storage_path'], '/');
$storageRoot = realpath(storage_root());
$realPath = realpath($path);
if ($storageRoot === false || $realPath === false || !str_starts_with($realPath, $storageRoot . '/comment_images/')) {
    http_response_code(404);
    exit('Not found');
}

$mime = (string) $image['mime_type'];
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . filesize($realPath));
readfile($realPath);
