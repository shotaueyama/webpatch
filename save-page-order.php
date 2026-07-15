<?php

require __DIR__ . '/_app.php';

$user = require_user();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

function page_order_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        page_order_response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('ページ順を読み込めませんでした。');
    }

    $token = (string) ($payload['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('セッションの有効期限が切れました。もう一度お試しください。');
    }

    $projectRef = (string) ($payload['project_id'] ?? '');
    $project = find_project_for_user_ref($projectRef, (int) $user['id']);
    if ($project === null) {
        throw new RuntimeException('プロジェクトが見つかりません。');
    }
    if (!project_role_allows_edit($project, (int) $user['id'])) {
        page_order_response(['ok' => false, 'message' => '編集権限のあるメンバーのみページ順を変更できます。'], 403);
    }

    $files = $payload['files'] ?? [];
    if (!is_array($files)) {
        throw new RuntimeException('ページ順の形式が不正です。');
    }

    $ordered = save_project_page_order($project, $files);
    page_order_response(['ok' => true, 'message' => 'ページ順を保存しました。', 'files' => $ordered]);
} catch (Throwable $e) {
    page_order_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
