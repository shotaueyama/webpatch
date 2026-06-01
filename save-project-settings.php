<?php

require __DIR__ . '/_app.php';

$user = require_user();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

function project_settings_response(bool $ok, string $message, array $data = [], int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    project_settings_response(false, '不正なリクエストです。', [], 405);
}

try {
    $raw = file_get_contents('php://input') ?: '';
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $token = (string) ($input['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('セッションの有効期限が切れました。もう一度お試しください。');
    }

    $projectRef = (string) ($input['project_id'] ?? '');
    $project = find_project_for_user_ref($projectRef, (int) $user['id']);
    if ($project === null) {
        throw new RuntimeException('プロジェクトが見つかりません。');
    }

    $copyPrompt = (string) ($input['copy_prompt'] ?? '');
    save_project_copy_prompt_for_user((int) $project['id'], (int) $user['id'], $copyPrompt);

    $gitSettings = null;
    if (array_key_exists('git_repository_url', $input) || array_key_exists('git_branch_name', $input)) {
        if (!user_owns_project($project, (int) $user['id'])) {
            throw new RuntimeException('サイト所有者のみGit連携設定を変更できます。');
        }
        $gitSettings = save_project_git_settings(
            (int) $project['id'],
            (string) ($input['git_repository_url'] ?? ''),
            (string) ($input['git_branch_name'] ?? 'main')
        );
    }

    project_settings_response(true, 'コピー設定を保存しました。', [
        'copy_prompt' => trim($copyPrompt),
        'git_settings' => $gitSettings,
    ]);
} catch (Throwable $e) {
    project_settings_response(false, $e->getMessage(), [], 400);
}
