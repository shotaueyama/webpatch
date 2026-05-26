<?php

require __DIR__ . '/_app.php';
require $GLOBALS['webpatch_app_root'] . '/layout.php';

$inviteToken = (string) ($_GET['invite'] ?? $_POST['invite'] ?? '');
$projectInvite = $inviteToken !== '' ? pending_project_invite($inviteToken) : null;
$noteInvite = $inviteToken !== '' && $projectInvite === null ? pending_note_invite($inviteToken) : null;
$invite = $projectInvite ?? $noteInvite;
$currentUser = current_user();
if ($currentUser !== null) {
    if ($invite !== null && mb_strtolower((string) $currentUser['email']) === (string) $invite['email']) {
        accept_pending_invites_for_user((string) $currentUser['email'], (int) $currentUser['id']);
        redirect_to(isset($invite['project_id']) ? project_path_by_id((int) $invite['project_id']) : note_path_by_id((int) $invite['note_id']));
    }
    redirect_to('dashboard.php');
}

$error = $inviteToken !== '' && $invite === null ? '招待リンクが無効、または期限切れです。' : '';
$name = '';
$email = $invite !== null ? (string) $invite['email'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $inviteToken = (string) ($_POST['invite'] ?? '');
        $projectInvite = $inviteToken !== '' ? pending_project_invite($inviteToken) : null;
        $noteInvite = $inviteToken !== '' && $projectInvite === null ? pending_note_invite($inviteToken) : null;
        $invite = $projectInvite ?? $noteInvite;
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $terms = isset($_POST['terms']);

        if ($inviteToken !== '' && $invite === null) {
            throw new RuntimeException('招待リンクが無効、または期限切れです。');
        }
        if ($name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('名前を入力してください。');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('有効なメールアドレスを入力してください。');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('パスワードは8文字以上で入力してください。');
        }
        if (!$terms) {
            throw new RuntimeException('利用規約とプライバシーポリシーへの同意が必要です。');
        }
        if ($invite !== null && $email !== (string) $invite['email']) {
            throw new RuntimeException('招待されたメールアドレスで登録してください。');
        }

        ensure_user_capacity();

        $stmt = db()->prepare('INSERT INTO ' . table_name('users') . ' (name, email, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);

        $userId = (int) db()->lastInsertId();
        accept_pending_invites_for_user($email, $userId);
        sign_in($userId);
        redirect_to($invite !== null ? (isset($invite['project_id']) ? project_path_by_id((int) $invite['project_id']) : note_path_by_id((int) $invite['note_id'])) : 'dashboard.php');
    } catch (PDOException $e) {
        $error = $e->getCode() === '23000' ? 'このメールアドレスはすでに登録されています。ログインしてください。' : '登録に失敗しました。';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

ob_start();
?>
<?php if ($error !== ''): ?>
  <div class="notice error"><?= h($error) ?></div>
<?php endif; ?>
<form class="auth-form" action="<?= h(base_url('signup.php')) ?>" method="post">
  <?= csrf_field() ?>
  <?php if ($inviteToken !== ''): ?>
    <input type="hidden" name="invite" value="<?= h($inviteToken) ?>">
  <?php endif; ?>
  <div class="field">
    <label for="name">名前</label>
    <input id="name" name="name" type="text" autocomplete="name" placeholder="山田 太郎" value="<?= h($name) ?>" required>
  </div>
  <div class="field">
    <label for="email">メールアドレス</label>
    <input id="email" name="email" type="email" autocomplete="email" placeholder="name@example.com" value="<?= h($email) ?>" required>
  </div>
  <div class="field">
    <label for="password">パスワード</label>
    <input id="password" name="password" type="password" autocomplete="new-password" placeholder="8文字以上" required minlength="8">
  </div>
  <label class="checkbox-field" for="terms">
    <input id="terms" name="terms" type="checkbox" required>
    <span>利用規約とプライバシーポリシーに同意します。</span>
  </label>
  <button class="primary-button" type="submit">登録する</button>
</form>
<p class="form-switch">すでにアカウントをお持ちの方は <a href="<?= h(base_url('login.php')) ?>">ログイン</a></p>
<?php
$lead = $invite !== null
    ? '招待されたコンテンツを開くため、アカウントを作成してください。'
    : 'はじめに管理用アカウントを作成してください。';
render_auth_page('新規登録', 'Create account', '新規登録', $lead, ob_get_clean());
