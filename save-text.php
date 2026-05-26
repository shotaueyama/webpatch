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
        throw new RuntimeException('保存データを読み込めませんでした。');
    }

    $token = (string) ($payload['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('セッションの有効期限が切れました。もう一度お試しください。');
    }

    $projectRef = (string) ($payload['id'] ?? '');
    $file = (string) ($payload['file'] ?? '');
    $html = (string) ($payload['html'] ?? '');
    if ($html === '') {
        throw new RuntimeException('保存対象のHTMLが空です。');
    }
    if (strlen($html) > WEBPATCH_MAX_EXTRACTED_BYTES) {
        throw new RuntimeException('保存対象のHTMLが大きすぎます。');
    }

    $project = find_project_for_user_ref($projectRef, (int) $user['id']);
    if ($project === null) {
        throw new RuntimeException('プロジェクトが見つかりません。');
    }
    if (project_is_url_source($project)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'URL登録サイトはコメントのみ可能です。']);
        exit;
    }
    if (!project_role_allows_edit($project, (int) $user['id'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'このサイトはコメントのみ可能です。']);
        exit;
    }

    $path = safe_project_file($project, $file);
    if (!is_html_file($file)) {
        throw new RuntimeException('HTMLファイルではありません。');
    }

    ensure_original_project_snapshot($project);
    file_put_contents($path, $html, LOCK_EX);
    chmod($path, 0640);
    reset_comment_ai_checks((int) $project['id'], normalize_zip_path($file));

    echo json_encode(['ok' => true, 'message' => '保存しました。']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
