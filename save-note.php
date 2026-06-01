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

    $noteRef = (string) ($payload['id'] ?? '');
    $title = trim((string) ($payload['title'] ?? ''));
    $markdown = trim((string) ($payload['markdown'] ?? ''));

    if ($title === '' || mb_strlen($title) > 180) {
        throw new RuntimeException('タイトルは1文字以上180文字以内で入力してください。');
    }
    if ($markdown === '' || strlen($markdown) > WEBPATCH_MAX_NOTE_BYTES) {
        throw new RuntimeException('ノート本文は1文字以上2MB以内で入力してください。');
    }

    $note = find_note_for_user_ref($noteRef, (int) $user['id']);
    if ($note === null) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'このノートは編集できません。']);
        exit;
    }

    $stmt = db()->prepare(
        'UPDATE ' . table_name('notes') . '
            SET title = ?, markdown = ?, updated_at = NOW()
          WHERE id = ?'
    );
    $stmt->execute([$title, $markdown, (int) $note['id']]);

    echo json_encode(['ok' => true, 'message' => '保存しました。']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
