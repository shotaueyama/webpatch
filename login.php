<?php

require __DIR__ . '/_app.php';
require $GLOBALS['webpatch_app_root'] . '/layout.php';

if (current_user() !== null) {
    redirect_to('dashboard.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $stmt = db()->prepare('SELECT id, password_hash FROM ' . table_name('users') . ' WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new RuntimeException('メールアドレスまたはパスワードが正しくありません。');
        }

        sign_in((int) $user['id']);
        redirect_to('dashboard.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

ob_start();
?>
<?php if ($error !== ''): ?>
  <div class="notice error"><?= h($error) ?></div>
<?php endif; ?>
<form class="auth-form" action="<?= h(base_url('login.php')) ?>" method="post">
  <?= csrf_field() ?>
  <div class="field">
    <label for="email">メールアドレス</label>
    <input id="email" name="email" type="email" autocomplete="email" placeholder="name@example.com" value="<?= h($email) ?>" required>
  </div>
  <div class="field">
    <div class="label-row">
      <label for="password">パスワード</label>
      <a href="<?= h(base_url('reset-password.php')) ?>">パスワードを忘れた方</a>
    </div>
    <input id="password" name="password" type="password" autocomplete="current-password" placeholder="8文字以上" required>
  </div>
  <button class="primary-button" type="submit">ログイン</button>
</form>
<p class="form-switch">アカウントをお持ちでない方は <a href="<?= h(base_url('signup.php')) ?>">新規登録</a></p>
<?php
render_auth_page('ログイン', 'Welcome back', 'ログイン', '登録済みのメールアドレスでワークスペースに入ります。', ob_get_clean());
