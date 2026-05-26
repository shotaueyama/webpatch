<?php

require __DIR__ . '/_app.php';

$user = require_user();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('リセット対象を読み込めませんでした。');
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
    if (project_is_url_source($project)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'URL登録サイトはリセットできません。']);
        exit;
    }
    if (!user_owns_project($project, (int) $user['id'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => '共有されたサイトは閲覧のみ可能です。']);
        exit;
    }

    reset_project_file_to_original($project, $file);
    reset_comment_ai_checks((int) $project['id'], normalize_zip_path($file));

    echo json_encode(['ok' => true, 'message' => '最初の状態に戻しました。']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
