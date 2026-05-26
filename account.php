<?php

require __DIR__ . '/_app.php';
require $GLOBALS['webpatch_app_root'] . '/layout.php';

$user = require_user();
$error = '';
$profileName = (string) $user['name'];
$profileEmail = (string) $user['email'];
$aiProviders = ai_provider_definitions();
$aiSettings = ai_settings_for_user((int) $user['id']);
$aiCheckProvider = ai_check_provider_for_user((int) $user['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? '');

        $stmt = db()->prepare('SELECT id, name, email, password_hash FROM ' . table_name('users') . ' WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
        $account = $stmt->fetch();
        if (!$account) {
            throw new RuntimeException('アカウントが見つかりません。');
        }

        if ($action === 'profile') {
            $profileName = trim((string) ($_POST['name'] ?? ''));
            $profileEmail = trim((string) ($_POST['email'] ?? ''));
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $emailChanged = strcasecmp($profileEmail, (string) $account['email']) !== 0;

            if ($profileName === '' || mb_strlen($profileName) > 120) {
                throw new RuntimeException('名前を入力してください。');
            }
            if (!filter_var($profileEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('有効なメールアドレスを入力してください。');
            }
            if ($emailChanged && !password_verify($currentPassword, (string) $account['password_hash'])) {
                throw new RuntimeException('メールアドレスを変更するには現在のパスワードを入力してください。');
            }

            $stmt = db()->prepare('UPDATE ' . table_name('users') . ' SET name = ?, email = ? WHERE id = ?');
            $stmt->execute([$profileName, $profileEmail, (int) $user['id']]);
            set_flash('success', 'アカウント情報を更新しました。');
            redirect_to('account.php');
        }

        if ($action === 'password') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

            if (!password_verify($currentPassword, (string) $account['password_hash'])) {
                throw new RuntimeException('現在のパスワードが正しくありません。');
            }
            if (strlen($newPassword) < 8) {
                throw new RuntimeException('新しいパスワードは8文字以上で入力してください。');
            }
            if ($newPassword !== $newPasswordConfirm) {
                throw new RuntimeException('新しいパスワードが一致しません。');
            }

            $stmt = db()->prepare('UPDATE ' . table_name('users') . ' SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $user['id']]);
            set_flash('success', 'パスワードを更新しました。');
            redirect_to('account.php');
        }

        if ($action === 'ai_settings') {
            foreach ($aiProviders as $provider => $definition) {
                $model = (string) ($_POST[$provider . '_model'] ?? $definition['default_model']);
                $apiKey = trim((string) ($_POST[$provider . '_api_key'] ?? ''));
                $clearKey = isset($_POST[$provider . '_clear_key']);
                save_ai_setting((int) $user['id'], $provider, $model, $apiKey, $clearKey);
            }
            save_ai_check_provider_for_user((int) $user['id'], (string) ($_POST['ai_check_provider'] ?? $aiCheckProvider));

            set_flash('success', 'AI API設定を保存しました。');
            redirect_to('account.php');
        }

        if ($action === 'logout') {
            redirect_to('logout.php');
        }

        throw new RuntimeException('更新内容が不正です。');
    } catch (PDOException $e) {
        $error = $e->getCode() === '23000' ? 'このメールアドレスはすでに登録されています。' : 'アカウント情報を更新できませんでした。';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

ob_start();
?>
<section class="account-header">
  <div class="account-avatar"><?= h(mb_substr($profileName, 0, 1)) ?></div>
  <div>
    <p class="eyebrow">Account</p>
    <h1>アカウント設定</h1>
    <p>ログインに使う名前、メールアドレス、パスワードを管理します。</p>
  </div>
</section>

<?php if ($error !== ''): ?>
  <div class="notice error"><?= h($error) ?></div>
<?php endif; ?>

<div class="account-layout">
  <section class="account-section">
    <div class="account-section-header">
      <div class="account-section-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </div>
      <div>
        <h2>基本情報</h2>
        <p class="account-section-desc">名前とメールアドレスを管理します</p>
      </div>
    </div>
    <form class="settings-form" action="<?= h(base_url('account.php')) ?>" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="profile">
      <div class="account-fields-row">
        <div class="field">
          <label for="name">名前</label>
          <input id="name" name="name" type="text" autocomplete="name" value="<?= h($profileName) ?>" required>
        </div>
        <div class="field">
          <label for="email">メールアドレス</label>
          <input id="email" name="email" type="email" autocomplete="email" value="<?= h($profileEmail) ?>" required>
        </div>
      </div>
      <div class="field">
        <label for="profile_current_password">現在のパスワード</label>
        <input id="profile_current_password" name="current_password" type="password" autocomplete="current-password" placeholder="メール変更時のみ入力">
        <p class="help-text">メールアドレスを変更する場合のみ必要です。</p>
      </div>
      <div class="account-form-actions">
        <button class="primary-button" type="submit">保存</button>
      </div>
    </form>
  </section>

  <section class="account-section">
    <div class="account-section-header">
      <div class="account-section-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      </div>
      <div>
        <h2>パスワード変更</h2>
        <p class="account-section-desc">セキュリティのため定期的な変更を推奨します</p>
      </div>
    </div>
    <form class="settings-form" action="<?= h(base_url('account.php')) ?>" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <div class="field">
        <label for="current_password">現在のパスワード</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
      </div>
      <div class="account-fields-row">
        <div class="field">
          <label for="new_password">新しいパスワード</label>
          <input id="new_password" name="new_password" type="password" autocomplete="new-password" placeholder="8文字以上" required minlength="8">
        </div>
        <div class="field">
          <label for="new_password_confirm">新しいパスワード確認</label>
          <input id="new_password_confirm" name="new_password_confirm" type="password" autocomplete="new-password" required minlength="8">
        </div>
      </div>
      <div class="account-form-actions">
        <button class="primary-button" type="submit">パスワードを更新</button>
      </div>
    </form>
  </section>

  <section class="account-section">
    <div class="account-section-header">
      <div class="account-section-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z"/></svg>
      </div>
      <div>
        <h2>AI API設定</h2>
        <p class="account-section-desc">サポートチャット、シート編集、文字修正、修正確認、ノート執筆で利用します</p>
      </div>
    </div>
    <form class="settings-form" action="<?= h(base_url('account.php')) ?>" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="ai_settings">
      <section class="ai-provider-card ai-provider-card-highlight">
        <div class="ai-provider-header">
          <div>
            <h3>AI確認に使うLLM</h3>
            <p>コメントの反映確認では、ここで選んだLLMだけを使います</p>
          </div>
        </div>
        <div class="field">
          <label for="ai_check_provider">利用するLLM</label>
          <select id="ai_check_provider" name="ai_check_provider">
            <?php foreach ($aiProviders as $provider => $definition): ?>
              <option value="<?= h($provider) ?>" <?= $aiCheckProvider === $provider ? 'selected' : '' ?>>
                <?= h($definition['label']) ?>
                <?= !empty($aiSettings[$provider]['has_api_key']) ? ' / APIキー保存済み' : ' / APIキー未設定' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <p class="help-text">選択したLLMのAPIキーが未設定の場合、AI確認時にエラーになります。</p>
      </section>
      <div class="ai-settings-list">
        <?php foreach ($aiProviders as $provider => $definition): ?>
          <?php $setting = $aiSettings[$provider] ?? ['model' => $definition['default_model'], 'api_key_hint' => '', 'has_api_key' => false]; ?>
          <section class="ai-provider-card" data-ai-provider-card="<?= h($provider) ?>">
            <div class="ai-provider-header">
              <div>
                <h3><?= h($definition['label']) ?></h3>
                <p><?= ((bool) $setting['has_api_key']) ? 'APIキー保存済み' : 'APIキー未設定' ?></p>
              </div>
              <?php if ((bool) $setting['has_api_key']): ?>
                <span class="ai-provider-status"><?= h((string) $setting['api_key_hint']) ?></span>
              <?php endif; ?>
            </div>
            <div class="ai-provider-fields">
              <div class="field">
                <label for="<?= h($provider) ?>_model">利用モデル</label>
                <select id="<?= h($provider) ?>_model" name="<?= h($provider) ?>_model">
                  <?php foreach ($definition['models'] as $modelId => $modelLabel): ?>
                    <option value="<?= h($modelId) ?>" <?= (string) $setting['model'] === (string) $modelId ? 'selected' : '' ?>><?= h($modelLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="<?= h($provider) ?>_api_key">APIキー</label>
                <input id="<?= h($provider) ?>_api_key" name="<?= h($provider) ?>_api_key" type="password" autocomplete="off" placeholder="<?= ((bool) $setting['has_api_key']) ? '変更する場合のみ入力' : 'APIキーを入力' ?>">
              </div>
            </div>
            <label class="ai-clear-field">
              <input type="checkbox" name="<?= h($provider) ?>_clear_key" value="1">
              <span>保存済みキーを削除する</span>
            </label>
            <div class="ai-provider-actions">
              <button class="secondary-button ai-test-button" type="button" data-ai-test-provider="<?= h($provider) ?>">接続確認</button>
              <div class="ai-test-result" data-ai-test-result="<?= h($provider) ?>" role="status" aria-live="polite"></div>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
      <p class="help-text">APIキーは暗号化して保存し、画面には判別用の一部だけを表示します。</p>
      <div class="account-form-actions">
        <button class="primary-button" type="submit">AI設定を保存</button>
      </div>
    </form>
  </section>

  <section class="account-section account-section-subtle">
    <div class="account-logout-row">
      <div class="account-section-header">
        <div class="account-section-icon account-section-icon-danger">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </div>
        <div>
          <h2>ログアウト</h2>
          <p class="account-section-desc">この端末のWebPatchセッションを終了します</p>
        </div>
      </div>
      <form class="settings-form" action="<?= h(base_url('logout.php')) ?>" method="post">
        <?= csrf_field() ?>
        <button class="secondary-button danger" type="submit">ログアウト</button>
      </form>
    </div>
  </section>
</div>
<script>
  (() => {
    const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const endpoint = <?= json_encode(base_url('ai-test-connection.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const buttons = Array.from(document.querySelectorAll('[data-ai-test-provider]'));
    const setResult = (provider, message, state = '') => {
      const box = document.querySelector(`[data-ai-test-result="${provider}"]`);
      if (!box) return;
      box.textContent = message || '';
      box.dataset.state = state;
    };

    buttons.forEach((button) => {
      button.addEventListener('click', async () => {
        const provider = button.dataset.aiTestProvider || '';
        const card = button.closest('[data-ai-provider-card]');
        const model = card ? card.querySelector(`[name="${provider}_model"]`) : null;
        const apiKey = card ? card.querySelector(`[name="${provider}_api_key"]`) : null;
        const previousText = button.textContent;
        button.disabled = true;
        button.textContent = '確認中';
        setResult(provider, '接続を確認しています...', 'pending');
        try {
          const response = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
              csrf_token: csrfToken,
              provider,
              model: model ? model.value : '',
              api_key: apiKey ? apiKey.value : ''
            })
          });
          const text = await response.text();
          let result;
          try {
            result = JSON.parse(text);
          } catch (error) {
            throw new Error('接続確認APIがJSON以外のレスポンスを返しました。ページを再読み込みしてから再度お試しください。');
          }
          if (!response.ok || !result.ok) {
            throw new Error(result.message || '接続確認に失敗しました。');
          }
          setResult(provider, `${result.message} 使用モデル: ${result.model || ''}`, 'success');
        } catch (error) {
          setResult(provider, error.message || '接続確認に失敗しました。', 'error');
        } finally {
          button.disabled = false;
          button.textContent = previousText || '接続確認';
        }
      });
    });
  })();
</script>
<?php
render_app_page('アカウント設定', ob_get_clean());
