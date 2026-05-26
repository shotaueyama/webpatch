<?php

require __DIR__ . '/_app.php';

$imageId = (int) ($_GET['id'] ?? 0);
$token = (string) ($_GET['token'] ?? '');

if ($imageId <= 0) {
    http_response_code(404);
    exit('Not found');
}

$stmt = db()->prepare(
    'SELECT ci.*, c.project_id
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
    $allowed = $project !== null && (int) $project['id'] === (int) $image['project_id'];
} else {
    $user = current_user();
    if ($user !== null) {
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
