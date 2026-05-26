<?php

require __DIR__ . '/_app.php';

$user = require_user();
$projectRef = (string) ($_POST['project_id'] ?? '');
$file = (string) ($_POST['file'] ?? '');
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
        throw new RuntimeException('URL登録サイトは上書きアップロードできません。');
    }
    if (!project_role_allows_edit($project, (int) $user['id'])) {
        throw new RuntimeException('編集権限のあるメンバーのみ上書きアップロードできます。');
    }

    $normalized = normalize_zip_path($file);
    if (!is_html_file($normalized)) {
        throw new RuntimeException('上書き対象はHTMLファイルのみです。');
    }

    $upload = $_FILES['page_html'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('上書きするHTMLファイルを選択してください。');
    }
    if (($upload['size'] ?? 0) > WEBPATCH_MAX_UPLOAD_BYTES) {
        throw new RuntimeException('アップロードできるHTMLは100MBまでです。');
    }
    if (!is_html_file((string) ($upload['name'] ?? ''))) {
        throw new RuntimeException('HTMLファイル（.html / .htm）を選択してください。');
    }

    $tmpName = (string) ($upload['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('アップロードファイルを読み込めませんでした。');
    }

    $contents = file_get_contents($tmpName);
    if (!is_string($contents) || trim($contents) === '') {
        throw new RuntimeException('アップロードされたHTMLが空です。');
    }

    ensure_original_project_snapshot($project);
    $target = safe_project_file($project, $normalized);
    file_put_contents($target, $contents, LOCK_EX);
    chmod($target, 0640);
    reset_comment_ai_checks((int) $project['id'], $normalized);

    set_flash('success', 'ページを上書きしました。');
} catch (Throwable $e) {
    set_flash('error', $e->getMessage());
}

$redirectFile = $file !== '' ? '&file=' . rawurlencode($file) : '';
redirect_to($project !== null ? project_path($project, $file) : 'dashboard.php');
