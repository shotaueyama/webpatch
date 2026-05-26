<?php

require __DIR__ . '/_app.php';
require $GLOBALS['webpatch_app_root'] . '/layout.php';

$error = '';
$email = '';
$resetUrl = '';
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();

        if ($token !== '') {
            $password = (string) ($_POST['password'] ?? '');
            if (strlen($password) < 8) {
                throw new RuntimeException('パスワードは8文字以上で入力してください。');
            }

            $stmt = db()->prepare('SELECT * FROM ' . table_name('password_reset_tokens') . ' WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()');
            $stmt->execute([hash('sha256', $token)]);
            $record = $stmt->fetch();
            if (!$record) {
                throw new RuntimeException('再発行リンクが無効または期限切れです。');
            }

            db()->beginTransaction();
            $stmt = db()->prepare('UPDATE ' . table_name('users') . ' SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $record['user_id']]);
            $stmt = db()->prepare('UPDATE ' . table_name('password_reset_tokens') . ' SET used_at = NOW() WHERE id = ?');
            $stmt->execute([$record['id']]);
            db()->commit();

            set_flash('success', 'パスワードを更新しました。新しいパスワードでログインしてください。');
            redirect_to('login.php');
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('有効なメールアドレスを入力してください。');
        }

        $stmt = db()->prepare('SELECT id FROM ' . table_name('users') . ' WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $plainToken = bin2hex(random_bytes(32));
            $stmt = db()->prepare('INSERT INTO ' . table_name('password_reset_tokens') . ' (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))');
            $stmt->execute([$user['id'], hash('sha256', $plainToken)]);
            $resetUrl = base_url('reset-password.php?token=' . rawurlencode($plainToken));
        }
    } catch (Throwable $e) {
        try {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
        } catch (Throwable $ignored) {
        }
        $error = $e->getMessage();
    }
}

ob_start();
?>
<?php if ($error !== ''): ?>
  <div class="notice error"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($resetUrl !== ''): ?>
  <div class="notice success">
    開発確認用の再発行リンクです。<br>
    <a href="<?= h($resetUrl) ?>"><?= h($resetUrl) ?></a>
  </div>
<?php endif; ?>
<?php if ($token !== ''): ?>
  <form class="auth-form" action="<?= h(base_url('reset-password.php')) ?>" method="post" data-reset-confirm-form data-confirm-title="パスワードを更新しますか？" data-confirm-message="現在のパスワードではログインできなくなります。新しいパスワードで更新します。" data-confirm-action="更新する">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= h($token) ?>">
    <div class="field">
      <label for="password">新しいパスワード</label>
      <input id="password" name="password" type="password" autocomplete="new-password" placeholder="8文字以上" required minlength="8">
    </div>
    <button class="primary-button" type="submit">パスワードを更新</button>
  </form>
<?php else: ?>
  <form class="auth-form" action="<?= h(base_url('reset-password.php')) ?>" method="post" data-reset-confirm-form data-confirm-title="再発行リンクを発行しますか？" data-confirm-message="入力したメールアドレスに対して、パスワード再発行用のリンクを発行します。" data-confirm-action="発行する">
    <?= csrf_field() ?>
    <div class="field">
      <label for="email">メールアドレス</label>
      <input id="email" name="email" type="email" autocomplete="email" placeholder="name@example.com" value="<?= h($email) ?>" required>
    </div>
    <button class="primary-button" type="submit">再発行リンクを発行</button>
  </form>
<?php endif; ?>
<div class="modal-backdrop" data-reset-modal hidden>
  <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="reset-confirm-title" aria-describedby="reset-confirm-message">
    <div class="confirm-modal-header">
      <h3 id="reset-confirm-title">確認</h3>
      <button class="icon-button" type="button" data-reset-cancel aria-label="閉じる">×</button>
    </div>
    <p id="reset-confirm-message"></p>
    <div class="modal-actions">
      <button class="modal-button secondary" type="button" data-reset-cancel>キャンセル</button>
      <button class="modal-button primary" type="button" data-reset-confirm>実行する</button>
    </div>
  </div>
</div>
<script>
  (() => {
    const form = document.querySelector('[data-reset-confirm-form]');
    const modal = document.querySelector('[data-reset-modal]');
    const title = document.getElementById('reset-confirm-title');
    const message = document.getElementById('reset-confirm-message');
    const confirmButton = document.querySelector('[data-reset-confirm]');
    const cancelButtons = Array.from(document.querySelectorAll('[data-reset-cancel]'));
    let confirmed = false;
    let lastFocused = null;

    if (!form || !modal || !title || !message || !confirmButton) {
      return;
    }

    const closeModal = () => {
      modal.hidden = true;
      document.body.classList.remove('modal-open');
      if (lastFocused) {
        lastFocused.focus();
      }
    };

    const openModal = () => {
      lastFocused = document.activeElement;
      title.textContent = form.dataset.confirmTitle || '確認';
      message.textContent = form.dataset.confirmMessage || 'この操作を実行します。';
      confirmButton.textContent = form.dataset.confirmAction || '実行する';
      modal.hidden = false;
      document.body.classList.add('modal-open');
      confirmButton.focus();
    };

    form.addEventListener('submit', (event) => {
      if (confirmed) {
        return;
      }
      if (!form.reportValidity()) {
        return;
      }
      event.preventDefault();
      openModal();
    });

    confirmButton.addEventListener('click', () => {
      confirmed = true;
      closeModal();
      form.requestSubmit();
    });

    cancelButtons.forEach((button) => {
      button.addEventListener('click', closeModal);
    });

    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        closeModal();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (!modal.hidden && event.key === 'Escape') {
        closeModal();
      }
    });
  })();
</script>
<p class="form-switch">パスワードを思い出した方は <a href="<?= h(base_url('login.php')) ?>">ログインへ戻る</a></p>
<?php
render_auth_page('パスワード再発行', 'Reset password', 'パスワード再発行', '登録済みのメールアドレスに再発行用リンクを発行します。', ob_get_clean());
