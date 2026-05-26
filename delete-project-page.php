<?php

require __DIR__ . '/_app.php';

$user = require_user();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('削除対象を読み込めませんでした。');
    }

    $token = (string) ($payload['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('セッションの有効期限が切れました。もう一度お試しください。');
    }

    $projectRef = (string) ($payload['id'] ?? '');
    $file = (string) ($payload['file'] ?? '');
    $project = find_project_for_user_ref($projectRef, (int) $user['id']);
    if ($project === null) {
        throw new RuntimeException('プロジェクトが見つかりません。');
    }
    if (!project_role_allows_edit($project, (int) $user['id'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => '編集権限のあるメンバーのみページを削除できます。'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $result = delete_project_page($project, $file);
    $redirectUrl = base_url(project_path($project, (string) $result['next_file']));

    echo json_encode([
        'ok' => true,
        'message' => 'ページを削除しました。',
        'deleted_file' => $result['deleted_file'],
        'next_file' => $result['next_file'],
        'redirect_url' => $redirectUrl,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
