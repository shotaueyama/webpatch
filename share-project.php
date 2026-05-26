<?php

require __DIR__ . '/_app.php';

$user = require_user();
$projectRef = (string) ($_POST['project_id'] ?? '');

function share_wants_json(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

function share_response(bool $ok, string $message, array $data = [], int $status = 200): never
{
    if (share_wants_json()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => $ok, 'message' => $message] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    set_flash($ok ? 'success' : 'error', $message);
    redirect_to($GLOBALS['share_project_redirect'] ?? 'dashboard.php');
}

function send_project_share_mail(string $email, string $subject, string $body): bool
{
    $headers = [
        'From: WebPatch <no-reply@cognify.works>',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    return @mail($email, $subject, $body, implode("\r\n", $headers));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    share_response(false, '不正なリクエストです。', [], 405);
}

$GLOBALS['share_project_redirect'] = preg_match('/^[A-Za-z0-9]{12}$/', $projectRef) ? 'project.php?id=' . rawurlencode($projectRef) : 'dashboard.php';

try {
    verify_csrf();

    $project = find_project_for_user_ref($projectRef, (int) $user['id']);
    if ($project === null) {
        throw new RuntimeException('プロジェクトが見つかりません。');
    }
    if (!user_owns_project($project, (int) $user['id'])) {
        throw new RuntimeException('共有されたサイトをさらに共有することはできません。');
    }

    $action = (string) ($_POST['action'] ?? 'share');
    $role = normalize_project_share_role((string) ($_POST['role'] ?? 'comment'));

    if ($action === 'role') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        if ($targetUserId <= 0 || $targetUserId === (int) $user['id']) {
            throw new RuntimeException('権限を変更するメンバーが見つかりません。');
        }
        $stmt = db()->prepare(
            'UPDATE ' . table_name('project_shares') . '
                SET role = ?
              WHERE project_id = ? AND user_id = ?'
        );
        $stmt->execute([$role, (int) $project['id'], $targetUserId]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('共有メンバーが見つかりません。');
        }
        share_response(true, '権限を更新しました。', ['role' => $role]);
    }

    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('共有先のメールアドレスを確認してください。');
    }

    $stmt = db()->prepare('SELECT id, name, email FROM ' . table_name('users') . ' WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $targetUser = $stmt->fetch();

    if ($targetUser) {
        if ((int) $targetUser['id'] === (int) $user['id']) {
            throw new RuntimeException('自分自身には共有できません。');
        }

        $stmt = db()->prepare(
            'INSERT INTO ' . table_name('project_shares') . ' (project_id, user_id, role, created_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE role = VALUES(role)'
        );
        $stmt->execute([(int) $project['id'], (int) $targetUser['id'], $role, (int) $user['id']]);

        $projectUrl = absolute_url(project_path($project));
        $roleLabel = $role === 'edit' ? '編集可能' : 'コメントのみ';
        $mailSent = send_project_share_mail(
            $email,
            'WebPatch project shared',
            $user['name'] . "さんが WebPatch のサイト「" . $project['title'] . "」を共有しました。\n権限: " . $roleLabel . "\n\n" . $projectUrl . "\n"
        );
        $message = $email . ' に共有しました。';
        share_response(true, $message, ['mail_sent' => $mailSent]);
    }

    $token = create_project_invite((int) $project['id'], $email, (int) $user['id'], $role);
    $inviteUrl = absolute_url('signup.php?invite=' . rawurlencode($token));
    $roleLabel = $role === 'edit' ? '編集可能' : 'コメントのみ';
    $mailSent = send_project_share_mail(
        $email,
        'WebPatch invitation',
        $user['name'] . "さんが WebPatch のサイト「" . $project['title'] . "」に招待しました。\n権限: " . $roleLabel . "\n\nアカウント登録後、このサイトを利用できます。\n" . $inviteUrl . "\n\nこの招待リンクは7日間有効です。\n"
    );

    $message = $mailSent
        ? $email . ' に招待メールを送信しました。'
        : '招待リンクを作成しました。メール送信に失敗した場合はリンクを共有してください。';
    share_response(true, $message, ['invite_url' => $inviteUrl, 'mail_sent' => $mailSent]);
} catch (Throwable $e) {
    share_response(false, $e->getMessage(), [], 400);
}
