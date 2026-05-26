<?php

require __DIR__ . '/_app.php';

$user = require_user();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

function public_link_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        public_link_response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }

    verify_csrf();

    $projectRef = (string) ($_POST['project_id'] ?? '');
    $action = (string) ($_POST['action'] ?? 'enable');
    $project = find_project_for_user_ref($projectRef, (int) $user['id']);
    if ($project === null || !user_owns_project($project, (int) $user['id'])) {
        throw new RuntimeException('プロジェクトが見つかりません。');
    }

    if ($action === 'disable') {
        disable_public_project_link((int) $project['id']);
        public_link_response(['ok' => true, 'enabled' => false, 'message' => '公開コメントリンクを無効にしました。']);
    }

    $link = $action === 'regenerate'
        ? regenerate_public_project_link((int) $project['id'], (int) $user['id'])
        : ensure_public_project_link((int) $project['id'], (int) $user['id']);

    public_link_response([
        'ok' => true,
        'enabled' => true,
        'url' => public_project_url((string) $link['token'], (string) $project['entry_file']),
        'message' => $action === 'regenerate' ? '公開コメントリンクを再発行しました。' : '公開コメントリンクを有効にしました。',
    ]);
} catch (Throwable $e) {
    public_link_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
