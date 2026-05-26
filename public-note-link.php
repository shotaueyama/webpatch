<?php

require __DIR__ . '/_app.php';

$user = require_user();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

function public_note_link_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        public_note_link_response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }

    verify_csrf();

    $noteRef = (string) ($_POST['note_id'] ?? '');
    $action = (string) ($_POST['action'] ?? 'enable');
    $note = find_note_for_user_ref($noteRef, (int) $user['id']);
    if ($note === null || !user_owns_note($note, (int) $user['id'])) {
        throw new RuntimeException('ノートが見つかりません。');
    }

    if ($action === 'disable') {
        disable_public_note_link((int) $note['id']);
        public_note_link_response(['ok' => true, 'enabled' => false, 'message' => '公開リンクを無効にしました。']);
    }

    $link = $action === 'regenerate'
        ? regenerate_public_note_link((int) $note['id'], (int) $user['id'])
        : ensure_public_note_link((int) $note['id'], (int) $user['id']);

    public_note_link_response([
        'ok' => true,
        'enabled' => true,
        'url' => public_note_url((string) $link['token']),
        'message' => $action === 'regenerate' ? '公開リンクを再発行しました。' : '公開リンクを有効にしました。',
    ]);
} catch (Throwable $e) {
    public_note_link_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
