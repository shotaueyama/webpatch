<?php

require __DIR__ . '/_app.php';
require $GLOBALS['webpatch_app_root'] . '/layout.php';

$user = require_user();
$error = '';
$activeRegisterMode = (string) ($_POST['register_mode'] ?? 'zip') === 'url' ? 'url' : 'zip';

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
            $message = 'URLから' . (int) $result['imported'] . 'ページを登録しました。';
            if ($skippedCount > 0) {
                $reasonCounts = [];
                foreach ($skipped as $skip) {
                    $reason = (string) ($skip['reason'] ?? '不明');
                    $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                }
                $reasonSummary = [];
                foreach ($reasonCounts as $reason => $count) {
                    $reasonSummary[] = $reason . ' ' . $count . '件';
                }
                $message .= ' スキップ: ' . $skippedCount . '件（' . implode('、', $reasonSummary) . '）。';
            }
            set_flash('success', $message);
            $projectId = (int) $result['project_id'];
        } else {
            $projectId = upload_project($_FILES['site_zip'] ?? [], (string) ($_POST['title'] ?? ''), (int) $user['id']);
            set_flash('success', 'HTMLサイトを登録しました。');
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
    <p class="eyebrow">Dashboard</p>
    <h1>サイト一覧</h1>
    <p>HTMLとアセットを含むZIPをサイト登録して、プロジェクトとして管理します。</p>
  </div>
  <button class="primary-button summary-action" type="button" data-site-modal-open>新規登録</button>
</section>

<div class="upload-modal-backdrop" data-site-modal <?= $error === '' ? 'hidden' : '' ?>>
  <div class="upload-modal" role="dialog" aria-modal="true" aria-labelledby="site-modal-title">
    <div class="upload-modal-header">
      <div>
        <p class="eyebrow">New Site</p>
        <h2 id="site-modal-title">新しいHTMLサイトを登録</h2>
        <p>ZIPアップロード、またはCSVのURLリストからサイトを登録します。</p>
      </div>
      <button class="icon-button" type="button" data-site-modal-close aria-label="閉じる">×</button>
    </div>
    <?php if ($error !== ''): ?>
      <div class="notice error"><?= h($error) ?></div>
    <?php endif; ?>
    <div class="register-tabs" role="tablist" aria-label="登録方法">
      <button class="register-tab <?= $activeRegisterMode === 'zip' ? 'active' : '' ?>" type="button" role="tab" aria-selected="<?= $activeRegisterMode === 'zip' ? 'true' : 'false' ?>" data-register-tab="zip">ZIPアップロード</button>
      <button class="register-tab <?= $activeRegisterMode === 'url' ? 'active' : '' ?>" type="button" role="tab" aria-selected="<?= $activeRegisterMode === 'url' ? 'true' : 'false' ?>" data-register-tab="url">URLから登録</button>
    </div>
    <form class="upload-form register-panel <?= $activeRegisterMode === 'zip' ? 'active' : '' ?>" action="<?= h(base_url('dashboard.php')) ?>" method="post" enctype="multipart/form-data" data-register-panel="zip" <?= $activeRegisterMode === 'zip' ? '' : 'hidden' ?>>
      <?= csrf_field() ?>
      <input type="hidden" name="register_mode" value="zip">
      <div class="field">
        <label for="title">プロジェクト名</label>
        <input id="title" name="title" type="text" placeholder="コーポレートサイト改修">
      </div>
      <div class="field">
        <label for="site_zip">HTML+アセットZIP</label>
        <input id="site_zip" name="site_zip" type="file" accept=".zip,application/zip" required>
        <p class="help-text">最大100MB。ZIP内にHTMLファイルが1つ以上必要です。</p>
      </div>
      <button class="primary-button" type="submit">サイト登録</button>
    </form>
    <form class="upload-form register-panel <?= $activeRegisterMode === 'url' ? 'active' : '' ?>" action="<?= h(base_url('dashboard.php')) ?>" method="post" enctype="multipart/form-data" data-register-panel="url" <?= $activeRegisterMode === 'url' ? '' : 'hidden' ?>>
      <?= csrf_field() ?>
      <input type="hidden" name="register_mode" value="url">
      <div class="field">
        <label for="url_title">プロジェクト名</label>
        <input id="url_title" name="url_title" type="text" placeholder="コーポレートサイト確認">
      </div>
      <div class="field">
        <label for="base_url">基準URL</label>
        <input id="base_url" name="base_url" type="url" placeholder="https://example.com/" required>
        <p class="help-text">CSV内のURLは、このURLと同じドメインだけ登録します。</p>
      </div>
      <div class="basic-auth-fields" aria-label="Basic認証">
        <div class="field">
          <label for="basic_auth_username">Basic認証ユーザー名</label>
          <input id="basic_auth_username" name="basic_auth_username" type="text" autocomplete="off" placeholder="必要な場合のみ">
        </div>
        <div class="field">
          <label for="basic_auth_password">Basic認証パスワード</label>
          <input id="basic_auth_password" name="basic_auth_password" type="password" autocomplete="off" placeholder="必要な場合のみ">
        </div>
        <p class="help-text">Basic認証があるサイトはここに入力してください。入力した認証情報は次回以降のURL再取得でも使用します。</p>
      </div>
      <div class="field">
        <label for="url_csv">URLリストCSV</label>
        <input id="url_csv" name="url_csv" type="file" accept=".csv,text/csv" required>
        <p class="help-text">1行1URL。最大50件。別ドメイン、重複、取得失敗ページはスキップします。</p>
      </div>
      <button class="primary-button" type="submit">URLから登録</button>
    </form>
  </div>
</div>

<section class="panel">
  <div class="panel-title-row">
    <h2>サイト一覧</h2>
    <span><?= count($projects) ?>件</span>
  </div>
  <?php if ($projects === []): ?>
    <div class="empty-state">まだサイトが登録されていません。</div>
  <?php else: ?>
    <div class="project-list">
      <?php foreach ($projects as $project): ?>
        <a class="project-row" href="<?= h(base_url(project_path($project))) ?>">
          <span>
            <strong>
              <?= h($project['title']) ?>
              <?php if (($project['source_type'] ?? 'zip') === 'url'): ?>
                <span class="project-badge">URL登録</span>
              <?php endif; ?>
              <?php if (($project['access_role'] ?? 'owner') !== 'owner'): ?>
                <span class="project-badge"><?= ($project['access_role'] ?? 'comment') === 'edit' ? '共有・編集可' : '共有' ?></span>
              <?php endif; ?>
            </strong>
            <small>
              <?= h($project['entry_file']) ?>
              <?php if (($project['access_role'] ?? 'owner') !== 'owner'): ?>
                ・所有者: <?= h($project['owner_name']) ?>
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
render_app_page('ダッシュボード', ob_get_clean());
