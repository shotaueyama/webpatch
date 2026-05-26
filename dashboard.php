<?php

require __DIR__ . '/_app.php';
require $GLOBALS['webpatch_app_root'] . '/layout.php';

$user = require_user();
$error = '';
$activeRegisterMode = (string) ($_POST['register_mode'] ?? 'zip') === 'url' ? 'url' : 'zip';
$appLanguage = app_language_for_user((int) $user['id']);
$text = [
    'ja' => [
        'page_title' => 'ダッシュボード',
        'eyebrow' => 'Dashboard',
        'title' => 'サイト一覧',
        'lead' => 'HTMLとアセットを含むZIPをサイト登録して、プロジェクトとして管理します。',
        'new_button' => '新規登録',
        'modal_eyebrow' => 'New Site',
        'modal_title' => '新しいHTMLサイトを登録',
        'modal_lead' => 'ZIPアップロード、またはCSVのURLリストからサイトを登録します。',
        'close' => '閉じる',
        'register_method' => '登録方法',
        'zip_tab' => 'ZIPアップロード',
        'url_tab' => 'URLから登録',
        'project_name' => 'プロジェクト名',
        'zip_placeholder' => 'コーポレートサイト改修',
        'zip_label' => 'HTML+アセットZIP',
        'zip_help' => '最大100MB。ZIP内にHTMLファイルが1つ以上必要です。',
        'zip_submit' => 'サイト登録',
        'url_placeholder' => 'コーポレートサイト確認',
        'base_url' => '基準URL',
        'base_url_help' => 'CSV内のURLは、このURLと同じドメインだけ登録します。',
        'basic_auth' => 'Basic認証',
        'basic_auth_username' => 'Basic認証ユーザー名',
        'basic_auth_password' => 'Basic認証パスワード',
        'basic_auth_placeholder' => '必要な場合のみ',
        'basic_auth_help' => 'Basic認証があるサイトはここに入力してください。入力した認証情報は次回以降のURL再取得でも使用します。',
        'csv_label' => 'URLリストCSV',
        'csv_help' => '1行1URL。最大50件。別ドメイン、重複、取得失敗ページはスキップします。',
        'url_submit' => 'URLから登録',
        'list_title' => 'サイト一覧',
        'count_suffix' => '件',
        'empty' => 'まだサイトが登録されていません。',
        'url_badge' => 'URL登録',
        'shared' => '共有',
        'shared_edit' => '共有・編集可',
        'owner' => '所有者',
        'zip_created' => 'HTMLサイトを登録しました。',
        'url_created_prefix' => 'URLから',
        'url_created_suffix' => 'ページを登録しました。',
        'skipped' => 'スキップ',
        'unknown' => '不明',
    ],
    'en' => [
        'page_title' => 'Dashboard',
        'eyebrow' => 'Dashboard',
        'title' => 'Sites',
        'lead' => 'Register ZIP files containing HTML and assets, then manage them as projects.',
        'new_button' => 'New site',
        'modal_eyebrow' => 'New Site',
        'modal_title' => 'Register New HTML Site',
        'modal_lead' => 'Register a site by uploading a ZIP file or importing a CSV URL list.',
        'close' => 'Close',
        'register_method' => 'Registration method',
        'zip_tab' => 'ZIP upload',
        'url_tab' => 'Import from URL',
        'project_name' => 'Project name',
        'zip_placeholder' => 'Corporate site update',
        'zip_label' => 'HTML + assets ZIP',
        'zip_help' => 'Maximum 100MB. The ZIP must contain at least one HTML file.',
        'zip_submit' => 'Register site',
        'url_placeholder' => 'Corporate site review',
        'base_url' => 'Base URL',
        'base_url_help' => 'Only URLs on the same domain as this URL will be registered.',
        'basic_auth' => 'Basic authentication',
        'basic_auth_username' => 'Basic auth username',
        'basic_auth_password' => 'Basic auth password',
        'basic_auth_placeholder' => 'Only if required',
        'basic_auth_help' => 'Enter credentials here for sites protected by Basic authentication. Saved credentials are also used for later URL refreshes.',
        'csv_label' => 'URL list CSV',
        'csv_help' => 'One URL per line. Maximum 50. Other domains, duplicates, and failed fetches are skipped.',
        'url_submit' => 'Import from URL',
        'list_title' => 'Sites',
        'count_suffix' => '',
        'empty' => 'No sites have been registered yet.',
        'url_badge' => 'URL import',
        'shared' => 'Shared',
        'shared_edit' => 'Shared / editable',
        'owner' => 'Owner',
        'zip_created' => 'HTML site registered.',
        'url_created_prefix' => 'Imported ',
        'url_created_suffix' => ' pages from URL.',
        'skipped' => 'Skipped',
        'unknown' => 'Unknown',
    ],
][$appLanguage] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        if ($activeRegisterMode === 'url') {
            $result = upload_url_project(
                $_FILES['url_csv'] ?? [],
                (string) ($_POST['url_title'] ?? ''),
                (string) ($_POST['base_url'] ?? ''),
                (int) $user['id'],
                [
                    'username' => (string) ($_POST['basic_auth_username'] ?? ''),
                    'password' => (string) ($_POST['basic_auth_password'] ?? ''),
                ]
            );
            $skipped = is_array($result['skipped'] ?? null) ? $result['skipped'] : [];
            $skippedCount = count($skipped);
            $message = $text['url_created_prefix'] . (int) $result['imported'] . $text['url_created_suffix'];
            if ($skippedCount > 0) {
                $reasonCounts = [];
                foreach ($skipped as $skip) {
                    $reason = (string) ($skip['reason'] ?? $text['unknown']);
                    $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                }
                $reasonSummary = [];
                foreach ($reasonCounts as $reason => $count) {
                    $reasonSummary[] = $appLanguage === 'en' ? $reason . ' ' . $count : $reason . ' ' . $count . '件';
                }
                $message .= $appLanguage === 'en'
                    ? ' ' . $text['skipped'] . ': ' . $skippedCount . ' (' . implode(', ', $reasonSummary) . ').'
                    : ' ' . $text['skipped'] . ': ' . $skippedCount . '件（' . implode('、', $reasonSummary) . '）。';
            }
            set_flash('success', $message);
            $projectId = (int) $result['project_id'];
        } else {
            $projectId = upload_project($_FILES['site_zip'] ?? [], (string) ($_POST['title'] ?? ''), (int) $user['id']);
            set_flash('success', $text['zip_created']);
        }
        redirect_to(project_path_by_id($projectId));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = db()->prepare(
    'SELECT p.*, owner.name AS owner_name, CASE WHEN p.user_id = ? THEN \'owner\' ELSE COALESCE(ps.role, \'comment\') END AS access_role
       FROM ' . table_name('projects') . ' p
       INNER JOIN ' . table_name('users') . ' owner ON owner.id = p.user_id
       LEFT JOIN ' . table_name('project_shares') . ' ps ON ps.project_id = p.id AND ps.user_id = ?
      WHERE p.user_id = ? OR ps.user_id = ?
      ORDER BY p.updated_at DESC, p.created_at DESC'
);
$stmt->execute([(int) $user['id'], (int) $user['id'], (int) $user['id'], (int) $user['id']]);
$projects = $stmt->fetchAll();

ob_start();
?>
<section class="dashboard-summary dashboard-summary-row">
  <div>
    <p class="eyebrow"><?= h($text['eyebrow']) ?></p>
    <h1><?= h($text['title']) ?></h1>
    <p><?= h($text['lead']) ?></p>
  </div>
  <button class="primary-button summary-action" type="button" data-site-modal-open><?= h($text['new_button']) ?></button>
</section>

<div class="upload-modal-backdrop" data-site-modal <?= $error === '' ? 'hidden' : '' ?>>
  <div class="upload-modal" role="dialog" aria-modal="true" aria-labelledby="site-modal-title">
    <div class="upload-modal-header">
      <div>
        <p class="eyebrow"><?= h($text['modal_eyebrow']) ?></p>
        <h2 id="site-modal-title"><?= h($text['modal_title']) ?></h2>
        <p><?= h($text['modal_lead']) ?></p>
      </div>
      <button class="icon-button" type="button" data-site-modal-close aria-label="<?= h($text['close']) ?>">×</button>
    </div>
    <?php if ($error !== ''): ?>
      <div class="notice error"><?= h($error) ?></div>
    <?php endif; ?>
    <div class="register-tabs" role="tablist" aria-label="<?= h($text['register_method']) ?>">
      <button class="register-tab <?= $activeRegisterMode === 'zip' ? 'active' : '' ?>" type="button" role="tab" aria-selected="<?= $activeRegisterMode === 'zip' ? 'true' : 'false' ?>" data-register-tab="zip"><?= h($text['zip_tab']) ?></button>
      <button class="register-tab <?= $activeRegisterMode === 'url' ? 'active' : '' ?>" type="button" role="tab" aria-selected="<?= $activeRegisterMode === 'url' ? 'true' : 'false' ?>" data-register-tab="url"><?= h($text['url_tab']) ?></button>
    </div>
    <form class="upload-form register-panel <?= $activeRegisterMode === 'zip' ? 'active' : '' ?>" action="<?= h(base_url('dashboard.php')) ?>" method="post" enctype="multipart/form-data" data-register-panel="zip" <?= $activeRegisterMode === 'zip' ? '' : 'hidden' ?>>
      <?= csrf_field() ?>
      <input type="hidden" name="register_mode" value="zip">
      <div class="field">
        <label for="title"><?= h($text['project_name']) ?></label>
        <input id="title" name="title" type="text" placeholder="<?= h($text['zip_placeholder']) ?>">
      </div>
      <div class="field">
        <label for="site_zip"><?= h($text['zip_label']) ?></label>
        <input id="site_zip" name="site_zip" type="file" accept=".zip,application/zip" required>
        <p class="help-text"><?= h($text['zip_help']) ?></p>
      </div>
      <button class="primary-button" type="submit"><?= h($text['zip_submit']) ?></button>
    </form>
    <form class="upload-form register-panel <?= $activeRegisterMode === 'url' ? 'active' : '' ?>" action="<?= h(base_url('dashboard.php')) ?>" method="post" enctype="multipart/form-data" data-register-panel="url" <?= $activeRegisterMode === 'url' ? '' : 'hidden' ?>>
      <?= csrf_field() ?>
      <input type="hidden" name="register_mode" value="url">
      <div class="field">
        <label for="url_title"><?= h($text['project_name']) ?></label>
        <input id="url_title" name="url_title" type="text" placeholder="<?= h($text['url_placeholder']) ?>">
      </div>
      <div class="field">
        <label for="base_url"><?= h($text['base_url']) ?></label>
        <input id="base_url" name="base_url" type="url" placeholder="https://example.com/" required>
        <p class="help-text"><?= h($text['base_url_help']) ?></p>
      </div>
      <div class="basic-auth-fields" aria-label="<?= h($text['basic_auth']) ?>">
        <div class="field">
          <label for="basic_auth_username"><?= h($text['basic_auth_username']) ?></label>
          <input id="basic_auth_username" name="basic_auth_username" type="text" autocomplete="off" placeholder="<?= h($text['basic_auth_placeholder']) ?>">
        </div>
        <div class="field">
          <label for="basic_auth_password"><?= h($text['basic_auth_password']) ?></label>
          <input id="basic_auth_password" name="basic_auth_password" type="password" autocomplete="off" placeholder="<?= h($text['basic_auth_placeholder']) ?>">
        </div>
        <p class="help-text"><?= h($text['basic_auth_help']) ?></p>
      </div>
      <div class="field">
        <label for="url_csv"><?= h($text['csv_label']) ?></label>
        <input id="url_csv" name="url_csv" type="file" accept=".csv,text/csv" required>
        <p class="help-text"><?= h($text['csv_help']) ?></p>
      </div>
      <button class="primary-button" type="submit"><?= h($text['url_submit']) ?></button>
    </form>
  </div>
</div>

<section class="panel">
  <div class="panel-title-row">
    <h2><?= h($text['list_title']) ?></h2>
    <span><?= count($projects) ?><?= h($text['count_suffix']) ?></span>
  </div>
  <?php if ($projects === []): ?>
    <div class="empty-state"><?= h($text['empty']) ?></div>
  <?php else: ?>
    <div class="project-list">
      <?php foreach ($projects as $project): ?>
        <a class="project-row" href="<?= h(base_url(project_path($project))) ?>">
          <span>
            <strong>
              <?= h($project['title']) ?>
              <?php if (($project['source_type'] ?? 'zip') === 'url'): ?>
                <span class="project-badge"><?= h($text['url_badge']) ?></span>
              <?php endif; ?>
              <?php if (($project['access_role'] ?? 'owner') !== 'owner'): ?>
                <span class="project-badge"><?= h(($project['access_role'] ?? 'comment') === 'edit' ? $text['shared_edit'] : $text['shared']) ?></span>
              <?php endif; ?>
            </strong>
            <small>
              <?= h($project['entry_file']) ?>
              <?php if (($project['access_role'] ?? 'owner') !== 'owner'): ?>
                ・<?= h($text['owner']) ?>: <?= h($project['owner_name']) ?>
              <?php endif; ?>
            </small>
          </span>
          <span><?= h(date('Y/m/d H:i', strtotime((string) $project['created_at']))) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<script>
  (() => {
    const openButton = document.querySelector('[data-site-modal-open]');
    const modal = document.querySelector('[data-site-modal]');
    const closeButton = document.querySelector('[data-site-modal-close]');
    const titleInput = document.getElementById('title');
    const tabs = Array.from(document.querySelectorAll('[data-register-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-register-panel]'));
    if (!openButton || !modal) {
      return;
    }

    const openModal = () => {
      modal.hidden = false;
      document.body.classList.add('modal-open');
      window.setTimeout(() => titleInput && titleInput.focus(), 0);
    };
    const closeModal = () => {
      modal.hidden = true;
      document.body.classList.remove('modal-open');
    };

    if (!modal.hidden) {
      document.body.classList.add('modal-open');
    }
    const setRegisterTab = (name) => {
      tabs.forEach((tab) => {
        const active = tab.dataset.registerTab === name;
        tab.classList.toggle('active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      panels.forEach((panel) => {
        const active = panel.dataset.registerPanel === name;
        panel.classList.toggle('active', active);
        panel.hidden = !active;
      });
    };
    tabs.forEach((tab) => tab.addEventListener('click', () => setRegisterTab(tab.dataset.registerTab || 'zip')));
    openButton.addEventListener('click', openModal);
    if (closeButton) {
      closeButton.addEventListener('click', closeModal);
    }
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
<?php
render_app_page($text['page_title'], ob_get_clean());
