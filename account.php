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
$appLanguage = app_language_for_user((int) $user['id']);
$gitSettings = git_settings_for_user((int) $user['id']);

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

        if ($action === 'language') {
            $appLanguage = normalize_app_language((string) ($_POST['app_language'] ?? 'ja'));
            save_app_language_for_user((int) $user['id'], $appLanguage);
            set_flash('success', $appLanguage === 'en' ? 'Language setting saved.' : '表示言語を保存しました。');
            redirect_to('account.php');
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
            set_flash('success', $appLanguage === 'en' ? 'Account information updated.' : 'アカウント情報を更新しました。');
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
            set_flash('success', $appLanguage === 'en' ? 'Password updated.' : 'パスワードを更新しました。');
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

            set_flash('success', $appLanguage === 'en' ? 'AI API settings saved.' : 'AI API設定を保存しました。');
            redirect_to('account.php');
        }

        if ($action === 'git_settings') {
            save_git_setting((int) $user['id'], [
                'provider' => 'github',
                'repository_url' => '',
                'branch_name' => 'main',
                'username' => (string) ($_POST['git_username'] ?? ''),
                'access_token' => (string) ($_POST['git_access_token'] ?? ''),
                'clear_access_token' => isset($_POST['git_clear_access_token']),
                'author_name' => (string) ($_POST['git_author_name'] ?? ''),
                'author_email' => (string) ($_POST['git_author_email'] ?? ''),
            ]);
            set_flash('success', $appLanguage === 'en' ? 'Git integration settings saved.' : 'Git連携設定を保存しました。');
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

$accountText = [
    'ja' => [
        'eyebrow' => 'Account',
        'title' => 'アカウント設定',
        'lead' => 'ログインに使う名前、メールアドレス、パスワードを管理します。',
        'language_title' => '表示言語',
        'language_desc' => 'WebPatchの基本UIで使う言語を選択します',
        'language_label' => '言語',
        'language_help' => '保存後、ヘッダーやアカウント設定などの共通UIに反映されます。',
        'language_save' => '言語を保存',
        'japanese' => '日本語',
        'english' => 'English',
        'profile_title' => '基本情報',
        'profile_desc' => '名前とメールアドレスを管理します',
        'name' => '名前',
        'email' => 'メールアドレス',
        'current_password' => '現在のパスワード',
        'profile_password_placeholder' => 'メール変更時のみ入力',
        'profile_password_help' => 'メールアドレスを変更する場合のみ必要です。',
        'save' => '保存',
        'password_title' => 'パスワード変更',
        'password_desc' => 'セキュリティのため定期的な変更を推奨します',
        'new_password' => '新しいパスワード',
        'new_password_placeholder' => '8文字以上',
        'new_password_confirm' => '新しいパスワード確認',
        'password_save' => 'パスワードを更新',
        'ai_title' => 'AI API設定',
        'ai_desc' => 'サポートチャット、シート編集、文字修正、修正確認、ノート執筆で利用します',
        'ai_check_title' => 'AI確認に使うLLM',
        'ai_check_desc' => 'コメントの反映確認では、ここで選んだLLMだけを使います',
        'ai_check_label' => '利用するLLM',
        'api_key_saved_suffix' => ' / APIキー保存済み',
        'api_key_missing_suffix' => ' / APIキー未設定',
        'ai_check_help' => '選択したLLMのAPIキーが未設定の場合、AI確認時にエラーになります。',
        'api_key_saved' => 'APIキー保存済み',
        'api_key_missing' => 'APIキー未設定',
        'model_label' => '利用モデル',
        'api_key_label' => 'APIキー',
        'api_key_change_placeholder' => '変更する場合のみ入力',
        'api_key_input_placeholder' => 'APIキーを入力',
        'clear_api_key' => '保存済みキーを削除する',
        'test_connection' => '接続確認',
        'api_key_help' => 'APIキーは暗号化して保存し、画面には判別用の一部だけを表示します。',
        'ai_save' => 'AI設定を保存',
        'git_title' => 'Git連携',
        'git_desc' => 'GitHub接続に使うユーザー情報とアクセストークンを保存します',
        'git_username' => 'Gitユーザー名',
        'git_username_placeholder' => 'GitHubユーザー名',
        'git_token' => 'アクセストークン',
        'git_token_saved' => 'アクセストークン保存済み',
        'git_token_missing' => 'アクセストークン未設定',
        'git_token_change_placeholder' => '変更する場合のみ入力',
        'git_token_input_placeholder' => 'GitHub Personal Access Token',
        'git_clear_token' => '保存済みトークンを削除する',
        'git_author_name' => 'コミット名',
        'git_author_email' => 'コミットメール',
        'git_test_connection' => '接続確認',
        'git_save' => 'Git設定を保存',
        'git_help' => 'トークンは暗号化して保存します。リポジトリURLとブランチは各サイトの設定で保存します。',
        'git_checking' => '確認中',
        'git_checking_connection' => 'GitHub接続を確認しています...',
        'git_json_error' => 'Git接続確認APIがJSON以外のレスポンスを返しました。ページを再読み込みしてから再度お試しください。',
        'git_connection_failed' => 'Git接続確認に失敗しました。',
        'logout_title' => 'ログアウト',
        'logout_desc' => 'この端末のWebPatchセッションを終了します',
        'logout_button' => 'ログアウト',
        'checking' => '確認中',
        'checking_connection' => '接続を確認しています...',
        'json_error' => '接続確認APIがJSON以外のレスポンスを返しました。ページを再読み込みしてから再度お試しください。',
        'connection_failed' => '接続確認に失敗しました。',
        'model_used' => '使用モデル',
        'page_title' => 'アカウント設定',
    ],
    'en' => [
        'eyebrow' => 'Account',
        'title' => 'Account Settings',
        'lead' => 'Manage the name, email address, and password used to sign in.',
        'language_title' => 'Display Language',
        'language_desc' => 'Choose the language used for the core WebPatch interface',
        'language_label' => 'Language',
        'language_help' => 'After saving, this applies to shared UI such as the header and account settings.',
        'language_save' => 'Save language',
        'japanese' => '日本語',
        'english' => 'English',
        'profile_title' => 'Profile',
        'profile_desc' => 'Manage your name and email address',
        'name' => 'Name',
        'email' => 'Email address',
        'current_password' => 'Current password',
        'profile_password_placeholder' => 'Required only when changing email',
        'profile_password_help' => 'Only required when changing your email address.',
        'save' => 'Save',
        'password_title' => 'Change Password',
        'password_desc' => 'Update your password periodically to keep the account secure',
        'new_password' => 'New password',
        'new_password_placeholder' => 'At least 8 characters',
        'new_password_confirm' => 'Confirm new password',
        'password_save' => 'Update password',
        'ai_title' => 'AI API Settings',
        'ai_desc' => 'Used for support chat, sheet editing, text edits, review checks, and note writing',
        'ai_check_title' => 'LLM for AI checks',
        'ai_check_desc' => 'Comment reflection checks use only the LLM selected here',
        'ai_check_label' => 'LLM',
        'api_key_saved_suffix' => ' / API key saved',
        'api_key_missing_suffix' => ' / API key missing',
        'ai_check_help' => 'If the selected LLM has no API key, AI checks will fail.',
        'api_key_saved' => 'API key saved',
        'api_key_missing' => 'API key missing',
        'model_label' => 'Model',
        'api_key_label' => 'API key',
        'api_key_change_placeholder' => 'Enter only to change the saved key',
        'api_key_input_placeholder' => 'Enter API key',
        'clear_api_key' => 'Remove saved key',
        'test_connection' => 'Test connection',
        'api_key_help' => 'API keys are encrypted before storage. Only a short hint is shown on screen.',
        'ai_save' => 'Save AI settings',
        'git_title' => 'Git Integration',
        'git_desc' => 'Save the GitHub user details and access token used for Git connections',
        'git_username' => 'Git username',
        'git_username_placeholder' => 'GitHub username',
        'git_token' => 'Access token',
        'git_token_saved' => 'Access token saved',
        'git_token_missing' => 'Access token missing',
        'git_token_change_placeholder' => 'Enter only to change the saved token',
        'git_token_input_placeholder' => 'GitHub Personal Access Token',
        'git_clear_token' => 'Remove saved token',
        'git_author_name' => 'Commit name',
        'git_author_email' => 'Commit email',
        'git_test_connection' => 'Test connection',
        'git_save' => 'Save Git settings',
        'git_help' => 'Tokens are encrypted before storage. Repository URLs and branches are saved per site.',
        'git_checking' => 'Checking',
        'git_checking_connection' => 'Checking GitHub connection...',
        'git_json_error' => 'The Git connection test API returned a non-JSON response. Reload the page and try again.',
        'git_connection_failed' => 'Git connection test failed.',
        'logout_title' => 'Log Out',
        'logout_desc' => 'End the current WebPatch session on this device',
        'logout_button' => 'Log out',
        'checking' => 'Checking',
        'checking_connection' => 'Checking connection...',
        'json_error' => 'The connection test API returned a non-JSON response. Reload the page and try again.',
        'connection_failed' => 'Connection test failed.',
        'model_used' => 'Model',
        'page_title' => 'Account Settings',
    ],
][$appLanguage] ?? [];

ob_start();
?>
<section class="account-header">
  <div class="account-avatar"><?= h(mb_substr($profileName, 0, 1)) ?></div>
  <div>
    <p class="eyebrow"><?= h($accountText['eyebrow']) ?></p>
    <h1><?= h($accountText['title']) ?></h1>
    <p><?= h($accountText['lead']) ?></p>
  </div>
</section>

<?php if ($error !== ''): ?>
  <div class="notice error"><?= h($error) ?></div>
<?php endif; ?>

<div class="account-layout">
  <section class="account-section">
    <div class="account-section-header">
      <div class="account-section-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14"/><path d="M5 16h14"/><path d="M9 4c-1.5 2-2.25 4.65-2.25 8S7.5 18 9 20"/><path d="M15 4c1.5 2 2.25 4.65 2.25 8S16.5 18 15 20"/><circle cx="12" cy="12" r="9"/></svg>
      </div>
      <div>
        <h2><?= h($accountText['language_title']) ?></h2>
        <p class="account-section-desc"><?= h($accountText['language_desc']) ?></p>
      </div>
    </div>
    <form class="settings-form" action="<?= h(base_url('account.php')) ?>" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="language">
      <div class="field">
        <label for="app_language"><?= h($accountText['language_label']) ?></label>
        <select id="app_language" name="app_language">
          <option value="ja" <?= $appLanguage === 'ja' ? 'selected' : '' ?>><?= h($accountText['japanese']) ?></option>
          <option value="en" <?= $appLanguage === 'en' ? 'selected' : '' ?>><?= h($accountText['english']) ?></option>
        </select>
        <p class="help-text"><?= h($accountText['language_help']) ?></p>
      </div>
      <div class="account-form-actions">
        <button class="primary-button" type="submit"><?= h($accountText['language_save']) ?></button>
      </div>
    </form>
  </section>

  <section class="account-section">
    <div class="account-section-header">
      <div class="account-section-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </div>
      <div>
        <h2><?= h($accountText['profile_title']) ?></h2>
        <p class="account-section-desc"><?= h($accountText['profile_desc']) ?></p>
      </div>
    </div>
    <form class="settings-form" action="<?= h(base_url('account.php')) ?>" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="profile">
      <div class="account-fields-row">
        <div class="field">
          <label for="name"><?= h($accountText['name']) ?></label>
          <input id="name" name="name" type="text" autocomplete="name" value="<?= h($profileName) ?>" required>
        </div>
        <div class="field">
          <label for="email"><?= h($accountText['email']) ?></label>
          <input id="email" name="email" type="email" autocomplete="email" value="<?= h($profileEmail) ?>" required>
        </div>
      </div>
      <div class="field">
        <label for="profile_current_password"><?= h($accountText['current_password']) ?></label>
        <input id="profile_current_password" name="current_password" type="password" autocomplete="current-password" placeholder="<?= h($accountText['profile_password_placeholder']) ?>">
        <p class="help-text"><?= h($accountText['profile_password_help']) ?></p>
      </div>
      <div class="account-form-actions">
        <button class="primary-button" type="submit"><?= h($accountText['save']) ?></button>
      </div>
    </form>
  </section>

  <section class="account-section">
    <div class="account-section-header">
      <div class="account-section-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      </div>
      <div>
        <h2><?= h($accountText['password_title']) ?></h2>
        <p class="account-section-desc"><?= h($accountText['password_desc']) ?></p>
      </div>
    </div>
    <form class="settings-form" action="<?= h(base_url('account.php')) ?>" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <div class="field">
        <label for="current_password"><?= h($accountText['current_password']) ?></label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
      </div>
      <div class="account-fields-row">
        <div class="field">
          <label for="new_password"><?= h($accountText['new_password']) ?></label>
          <input id="new_password" name="new_password" type="password" autocomplete="new-password" placeholder="<?= h($accountText['new_password_placeholder']) ?>" required minlength="8">
        </div>
        <div class="field">
          <label for="new_password_confirm"><?= h($accountText['new_password_confirm']) ?></label>
          <input id="new_password_confirm" name="new_password_confirm" type="password" autocomplete="new-password" required minlength="8">
        </div>
      </div>
      <div class="account-form-actions">
        <button class="primary-button" type="submit"><?= h($accountText['password_save']) ?></button>
      </div>
    </form>
  </section>

  <section class="account-section">
    <div class="account-section-header">
      <div class="account-section-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z"/></svg>
      </div>
      <div>
        <h2><?= h($accountText['ai_title']) ?></h2>
        <p class="account-section-desc"><?= h($accountText['ai_desc']) ?></p>
      </div>
    </div>
    <form class="settings-form" action="<?= h(base_url('account.php')) ?>" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="ai_settings">
      <section class="ai-provider-card ai-provider-card-highlight">
        <div class="ai-provider-header">
          <div>
            <h3><?= h($accountText['ai_check_title']) ?></h3>
            <p><?= h($accountText['ai_check_desc']) ?></p>
          </div>
        </div>
        <div class="field">
          <label for="ai_check_provider"><?= h($accountText['ai_check_label']) ?></label>
          <select id="ai_check_provider" name="ai_check_provider">
            <?php foreach ($aiProviders as $provider => $definition): ?>
              <option value="<?= h($provider) ?>" <?= $aiCheckProvider === $provider ? 'selected' : '' ?>>
                <?= h($definition['label']) ?>
                <?= !empty($aiSettings[$provider]['has_api_key']) ? h($accountText['api_key_saved_suffix']) : h($accountText['api_key_missing_suffix']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <p class="help-text"><?= h($accountText['ai_check_help']) ?></p>
      </section>
      <div class="ai-settings-list">
        <?php foreach ($aiProviders as $provider => $definition): ?>
          <?php $setting = $aiSettings[$provider] ?? ['model' => $definition['default_model'], 'api_key_hint' => '', 'has_api_key' => false]; ?>
          <section class="ai-provider-card" data-ai-provider-card="<?= h($provider) ?>">
            <div class="ai-provider-header">
              <div>
                <h3><?= h($definition['label']) ?></h3>
                <p><?= ((bool) $setting['has_api_key']) ? h($accountText['api_key_saved']) : h($accountText['api_key_missing']) ?></p>
              </div>
              <?php if ((bool) $setting['has_api_key']): ?>
                <span class="ai-provider-status"><?= h((string) $setting['api_key_hint']) ?></span>
              <?php endif; ?>
            </div>
            <div class="ai-provider-fields">
              <div class="field">
                <label for="<?= h($provider) ?>_model"><?= h($accountText['model_label']) ?></label>
                <select id="<?= h($provider) ?>_model" name="<?= h($provider) ?>_model">
                  <?php foreach ($definition['models'] as $modelId => $modelLabel): ?>
                    <option value="<?= h($modelId) ?>" <?= (string) $setting['model'] === (string) $modelId ? 'selected' : '' ?>><?= h($modelLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="<?= h($provider) ?>_api_key"><?= h($accountText['api_key_label']) ?></label>
                <input id="<?= h($provider) ?>_api_key" name="<?= h($provider) ?>_api_key" type="password" autocomplete="off" placeholder="<?= ((bool) $setting['has_api_key']) ? h($accountText['api_key_change_placeholder']) : h($accountText['api_key_input_placeholder']) ?>">
              </div>
            </div>
            <label class="ai-clear-field">
              <input type="checkbox" name="<?= h($provider) ?>_clear_key" value="1">
              <span><?= h($accountText['clear_api_key']) ?></span>
            </label>
            <div class="ai-provider-actions">
              <button class="secondary-button ai-test-button" type="button" data-ai-test-provider="<?= h($provider) ?>"><?= h($accountText['test_connection']) ?></button>
              <div class="ai-test-result" data-ai-test-result="<?= h($provider) ?>" role="status" aria-live="polite"></div>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
      <p class="help-text"><?= h($accountText['api_key_help']) ?></p>
      <div class="account-form-actions">
        <button class="primary-button" type="submit"><?= h($accountText['ai_save']) ?></button>
      </div>
    </form>
  </section>

  <section class="account-section">
    <div class="account-section-header">
      <div class="account-section-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="18" r="3"/><circle cx="6" cy="6" r="3"/><path d="M6 9v6a3 3 0 0 0 3 3h6"/><path d="M6 9v1a3 3 0 0 0 3 3h2"/></svg>
      </div>
      <div>
        <h2><?= h($accountText['git_title']) ?></h2>
        <p class="account-section-desc"><?= h($accountText['git_desc']) ?></p>
      </div>
    </div>
    <form class="settings-form" action="<?= h(base_url('account.php')) ?>" method="post" data-git-settings-form>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="git_settings">
      <section class="ai-provider-card" data-git-provider-card>
        <div class="ai-provider-header">
          <div>
            <h3>GitHub</h3>
            <p><?= ((bool) $gitSettings['has_access_token']) ? h($accountText['git_token_saved']) : h($accountText['git_token_missing']) ?></p>
          </div>
          <?php if ((bool) $gitSettings['has_access_token']): ?>
            <span class="ai-provider-status"><?= h((string) $gitSettings['access_token_hint']) ?></span>
          <?php endif; ?>
        </div>
        <div class="field">
          <label for="git_username"><?= h($accountText['git_username']) ?></label>
          <input id="git_username" name="git_username" type="text" autocomplete="username" value="<?= h((string) $gitSettings['username']) ?>" placeholder="<?= h($accountText['git_username_placeholder']) ?>">
        </div>
        <div class="field">
          <label for="git_access_token"><?= h($accountText['git_token']) ?></label>
          <input id="git_access_token" name="git_access_token" type="password" autocomplete="off" placeholder="<?= ((bool) $gitSettings['has_access_token']) ? h($accountText['git_token_change_placeholder']) : h($accountText['git_token_input_placeholder']) ?>">
        </div>
        <label class="ai-clear-field">
          <input type="checkbox" name="git_clear_access_token" value="1">
          <span><?= h($accountText['git_clear_token']) ?></span>
        </label>
        <div class="ai-provider-fields">
          <div class="field">
            <label for="git_author_name"><?= h($accountText['git_author_name']) ?></label>
            <input id="git_author_name" name="git_author_name" type="text" autocomplete="name" value="<?= h((string) $gitSettings['author_name']) ?>" placeholder="<?= h($profileName) ?>">
          </div>
          <div class="field">
            <label for="git_author_email"><?= h($accountText['git_author_email']) ?></label>
            <input id="git_author_email" name="git_author_email" type="email" autocomplete="email" value="<?= h((string) $gitSettings['author_email']) ?>" placeholder="<?= h($profileEmail) ?>">
          </div>
        </div>
        <div class="ai-provider-actions">
          <button class="secondary-button ai-test-button" type="button" data-git-test-button><?= h($accountText['git_test_connection']) ?></button>
          <div class="ai-test-result" data-git-test-result role="status" aria-live="polite"></div>
        </div>
      </section>
      <p class="help-text"><?= h($accountText['git_help']) ?></p>
      <div class="account-form-actions">
        <button class="primary-button" type="submit"><?= h($accountText['git_save']) ?></button>
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
          <h2><?= h($accountText['logout_title']) ?></h2>
          <p class="account-section-desc"><?= h($accountText['logout_desc']) ?></p>
        </div>
      </div>
      <form class="settings-form" action="<?= h(base_url('logout.php')) ?>" method="post">
        <?= csrf_field() ?>
        <button class="secondary-button danger" type="submit"><?= h($accountText['logout_button']) ?></button>
      </form>
    </div>
  </section>
</div>
<script>
  (() => {
    const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const endpoint = <?= json_encode(base_url('ai-test-connection.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const copy = <?= json_encode([
        'checking' => $accountText['checking'],
        'checkingConnection' => $accountText['checking_connection'],
        'jsonError' => $accountText['json_error'],
        'connectionFailed' => $accountText['connection_failed'],
        'testConnection' => $accountText['test_connection'],
        'modelUsed' => $accountText['model_used'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
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
        button.textContent = copy.checking;
        setResult(provider, copy.checkingConnection, 'pending');
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
          const responseText = await response.text();
          let result;
          try {
            result = JSON.parse(responseText);
          } catch (error) {
            throw new Error(copy.jsonError);
          }
          if (!response.ok || !result.ok) {
            throw new Error(result.message || copy.connectionFailed);
          }
          setResult(provider, `${result.message} ${copy.modelUsed}: ${result.model || ''}`, 'success');
        } catch (error) {
          setResult(provider, error.message || copy.connectionFailed, 'error');
        } finally {
          button.disabled = false;
          button.textContent = previousText || copy.testConnection;
        }
      });
    });
  })();
  (() => {
    const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const endpoint = <?= json_encode(base_url('git-test-connection.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const copy = <?= json_encode([
        'checking' => $accountText['git_checking'],
        'checkingConnection' => $accountText['git_checking_connection'],
        'jsonError' => $accountText['git_json_error'],
        'connectionFailed' => $accountText['git_connection_failed'],
        'testConnection' => $accountText['git_test_connection'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const button = document.querySelector('[data-git-test-button]');
    const resultBox = document.querySelector('[data-git-test-result]');
    const setResult = (message, state = '') => {
      if (!resultBox) return;
      resultBox.textContent = message || '';
      resultBox.dataset.state = state;
    };
    if (!button) return;
    button.addEventListener('click', async () => {
      const previousText = button.textContent;
      button.disabled = true;
      button.textContent = copy.checking;
      setResult(copy.checkingConnection, 'pending');
      try {
        const response = await fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({
            csrf_token: csrfToken,
            access_token: document.getElementById('git_access_token')?.value || ''
          })
        });
        const responseText = await response.text();
        let result;
        try {
          result = JSON.parse(responseText);
        } catch (error) {
          throw new Error(copy.jsonError);
        }
        if (!response.ok || !result.ok) {
          throw new Error(result.message || copy.connectionFailed);
        }
        setResult(`${result.message} ${result.repository || ''} (${result.branch || ''})`, 'success');
      } catch (error) {
        setResult(error.message || copy.connectionFailed, 'error');
      } finally {
        button.disabled = false;
        button.textContent = previousText || copy.testConnection;
      }
    });
  })();
</script>
<?php
render_app_page($accountText['page_title'], ob_get_clean());
