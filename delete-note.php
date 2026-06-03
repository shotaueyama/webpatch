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
        throw new RuntimeException('削除データを読み込めませんでした。');
    }

    $token = (string) ($payload['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('セッションの有効期限が切れました。もう一度お試しください。');
    }

    $noteRef = (string) ($payload['id'] ?? '');
    $note = find_note_for_user_ref($noteRef, (int) $user['id']);
    if ($note === null || !user_owns_note($note, (int) $user['id'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'ノート全体の削除は所有者のみ可能です。'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $noteId = (int) $note['id'];
    $pdo = db();
    $pdo->beginTransaction();
    foreach (['note_public_links', 'note_invites', 'note_shares'] as $table) {
        $stmt = $pdo->prepare('DELETE FROM ' . table_name($table) . ' WHERE note_id = ?');
        $stmt->execute([$noteId]);
    }
    $stmt = $pdo->prepare('DELETE FROM ' . table_name('notes') . ' WHERE id = ? AND user_id = ?');
    $stmt->execute([$noteId, (int) $user['id']]);
    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'message' => 'ノートを削除しました。',
        'redirect' => base_url('notes.php'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
