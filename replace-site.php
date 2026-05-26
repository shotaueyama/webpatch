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
    if (project_is_url_source($project)) {
        throw new RuntimeException('URL登録サイトはZIPで差し替えできません。');
    }
    if (!project_role_allows_edit($project, (int) $user['id'])) {
        throw new RuntimeException('編集権限のあるメンバーのみサイト差し替えできます。');
    }

    $entryFile = replace_project_with_zip($project, $_FILES['site_zip'] ?? []);
    reset_comment_ai_checks((int) $project['id']);
    set_flash('success', 'サイト全体を差し替えました。');
    redirect_to(project_path($project, $entryFile));
} catch (Throwable $e) {
    set_flash('error', $e->getMessage());
}

redirect_to($project !== null ? project_path($project) : 'dashboard.php');
