<?php

require __DIR__ . '/_app.php';

$user = require_user();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

function client_link_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function client_link_payload(array $project): array
{
    $links = array_map(
        static fn (array $link): array => client_link_row_payload($link, $project),
        client_links_for_project((int) $project['id'])
    );
    return ['links' => $links];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        client_link_response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }

    verify_csrf();

    $projectRef = (string) ($_POST['project_id'] ?? '');
    $project = find_project_for_user_ref($projectRef, (int) $user['id']);
    if ($project === null || (int) $project['user_id'] !== (int) $user['id']) {
        throw new RuntimeException('プロジェクトが見つからないか、操作権限がありません。');
    }

    $action = (string) ($_POST['action'] ?? 'create');
    $linkId = (int) ($_POST['link_id'] ?? 0);

    if ($action === 'create') {
        create_project_client_link((int) $project['id'], (string) ($_POST['label'] ?? ''), (int) $user['id']);
        client_link_response(['ok' => true, 'message' => 'クライアント共有リンクを作成しました。'] + client_link_payload($project));
    }

    if ($linkId <= 0) {
        throw new RuntimeException('クライアント共有リンクが見つかりません。');
    }

    if ($action === 'regenerate') {
        regenerate_project_client_link($linkId, (int) $project['id'], (int) $user['id']);
        client_link_response(['ok' => true, 'message' => 'クライアント共有リンクを再発行しました。'] + client_link_payload($project));
    }

    if ($action === 'disable') {
        disable_project_client_link($linkId, (int) $project['id']);
        client_link_response(['ok' => true, 'message' => 'クライアント共有リンクを無効化しました。'] + client_link_payload($project));
    }

    if ($action === 'delete') {
        delete_project_client_link($linkId, (int) $project['id']);
        client_link_response(['ok' => true, 'message' => 'クライアント共有リンクを削除しました。'] + client_link_payload($project));
    }

    throw new RuntimeException('不明な操作です。');
} catch (Throwable $e) {
    client_link_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
