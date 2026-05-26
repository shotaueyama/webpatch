<?php

require __DIR__ . '/_app.php';

$user = require_user();
$projectRef = (string) ($_POST['project_id'] ?? '');
$project = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('dashboard.php');
}

try {
    verify_csrf();

    $project = find_project_for_user_ref($projectRef, (int) $user['id']);
    if ($project === null) {
        throw new RuntimeException('プロジェクトが見つかりません。');
    }
    if (!project_is_url_source($project)) {
        throw new RuntimeException('URL登録サイトではありません。');
    }
    if (!project_role_allows_edit($project, (int) $user['id'])) {
        throw new RuntimeException('編集権限のあるメンバーのみURL再取得できます。');
    }

    $result = refresh_url_project($project, [
        'username' => (string) ($_POST['basic_auth_username'] ?? ''),
        'password' => (string) ($_POST['basic_auth_password'] ?? ''),
    ]);
    reset_comment_ai_checks((int) $project['id']);

    $skipped = is_array($result['skipped'] ?? null) ? $result['skipped'] : [];
    $message = 'URLから' . (int) ($result['updated'] ?? 0) . 'ページを再取得しました。';
    if ((int) ($result['removed'] ?? 0) > 0) {
        $message .= ' 存在しないページを' . (int) $result['removed'] . '件除外しました。';
    }
    if ($skipped !== []) {
        $message .= ' スキップ: ' . count($skipped) . '件。';
    }
    set_flash('success', $message);
    redirect_to(project_path($project, (string) ($project['entry_file'] ?? '')));
} catch (Throwable $e) {
    set_flash('error', $e->getMessage());
}

redirect_to($project !== null ? project_path($project) : 'dashboard.php');
