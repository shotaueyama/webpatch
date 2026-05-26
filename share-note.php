<?php

require __DIR__ . '/_app.php';

$user = require_user();
$noteRef = (string) ($_POST['note_id'] ?? '');

function note_share_wants_json(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

function note_share_response(bool $ok, string $message, array $data = [], int $status = 200): never
{
    if (note_share_wants_json()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => $ok, 'message' => $message] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    set_flash($ok ? 'success' : 'error', $message);
    redirect_to($GLOBALS['share_note_redirect'] ?? 'notes.php');
}

function send_note_share_mail(string $email, string $subject, string $body): bool
{
    $headers = [
        'From: WebPatch <no-reply@cognify.works>',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    return @mail($email, $subject, $body, implode("\r\n", $headers));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    note_share_response(false, '不正なリクエストです。', [], 405);
}

$GLOBALS['share_note_redirect'] = preg_match('/^[A-Za-z0-9]{12}$/', $noteRef) ? 'note.php?id=' . rawurlencode($noteRef) : 'notes.php';

try {
    verify_csrf();

    $note = find_note_for_user_ref($noteRef, (int) $user['id']);
    if ($note === null) {
        throw new RuntimeException('ノートが見つかりません。');
    }
    if (!user_owns_note($note, (int) $user['id'])) {
        throw new RuntimeException('共有されたノートをさらに共有することはできません。');
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
            'INSERT INTO ' . table_name('note_shares') . ' (note_id, user_id, created_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE created_by = VALUES(created_by)'
        );
        $stmt->execute([(int) $note['id'], (int) $targetUser['id'], (int) $user['id']]);

        $noteUrl = absolute_url(note_path($note));
        $mailSent = send_note_share_mail(
            $email,
            'WebPatch note shared',
            $user['name'] . "さんが WebPatch のノート「" . $note['title'] . "」を共有しました。\n\n" . $noteUrl . "\n"
        );
        note_share_response(true, $email . ' に共有しました。', ['mail_sent' => $mailSent]);
    }

    $token = create_note_invite((int) $note['id'], $email, (int) $user['id']);
    $inviteUrl = absolute_url('signup.php?invite=' . rawurlencode($token));
    $mailSent = send_note_share_mail(
        $email,
        'WebPatch note invitation',
        $user['name'] . "さんが WebPatch のノート「" . $note['title'] . "」に招待しました。\n\nアカウント登録後、このノートを閲覧できます。\n" . $inviteUrl . "\n\nこの招待リンクは7日間有効です。\n"
    );

    $message = $mailSent
        ? $email . ' に招待メールを送信しました。'
        : '招待リンクを作成しました。メール送信に失敗した場合はリンクを共有してください。';
    note_share_response(true, $message, ['invite_url' => $inviteUrl, 'mail_sent' => $mailSent]);
} catch (Throwable $e) {
    note_share_response(false, $e->getMessage(), [], 400);
}
