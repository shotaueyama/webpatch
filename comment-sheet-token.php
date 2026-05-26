<?php

require __DIR__ . '/_app.php';

$user = require_user();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

function sheet_token_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sheet_token_response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('リクエストを読み込めませんでした。');
    }
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($payload['csrf_token'] ?? ''))) {
        throw new RuntimeException('セッションの有効期限が切れました。もう一度お試しください。');
    }

    $projectRef = (string) ($payload['project_ref'] ?? '');
    $project = find_project_for_user_ref($projectRef, (int) $user['id']);
    if ($project === null || !user_owns_project($project, (int) $user['id'])) {
        throw new RuntimeException('APIを管理する権限がありません。');
    }

    $action = (string) ($payload['action'] ?? '');
    if ($action === 'issue') {
        $token = issue_comment_sheet_api_token((int) $project['id'], (int) $user['id']);
        sheet_token_response([
            'ok' => true,
            'enabled' => true,
            'token' => $token['token'],
            'token_prefix' => $token['token_prefix'],
            'last_used_at' => $token['last_used_at'],
            'created_at' => $token['created_at'],
        ]);
    }

    if ($action === 'disable') {
        disable_comment_sheet_api_token((int) $project['id']);
        sheet_token_response([
            'ok' => true,
            'enabled' => false,
            'token_prefix' => '',
            'last_used_at' => null,
            'created_at' => null,
        ]);
    }

    throw new RuntimeException('未対応の操作です。');
} catch (Throwable $e) {
    sheet_token_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
