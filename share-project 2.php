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
    if (!user_owns_project($project, (int) $user['id'])) {
        throw new RuntimeException('共有されたサイトをさらに共有することはできません。');
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('共有先のメールアドレスを確認してください。');
    }

    $stmt = db()->prepare('SELECT id, name, email FROM ' . table_name('users') . ' WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $targetUser = $stmt->fetch();
    if (!$targetUser) {
        throw new RuntimeException('このメールアドレスのユーザーは登録されていません。');
    }
    if ((int) $targetUser['id'] === (int) $user['id']) {
        throw new RuntimeException('自分自身には共有できません。');
    }

    $stmt = db()->prepare(
        'INSERT IGNORE INTO ' . table_name('project_shares') . ' (project_id, user_id, created_by)
         VALUES (?, ?, ?)'
    );
    $stmt->execute([(int) $project['id'], (int) $targetUser['id'], (int) $user['id']]);

    if ($stmt->rowCount() === 0) {
        set_flash('success', 'すでに共有済みのメンバーです。');
    } else {
        set_flash('success', $targetUser['email'] . ' に共有しました。');
    }
} catch (Throwable $e) {
    set_flash('error', $e->getMessage());
}

redirect_to($project !== null ? project_path($project) : 'dashboard.php');
