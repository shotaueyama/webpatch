<?php

require __DIR__ . '/_app.php';
require $GLOBALS['webpatch_app_root'] . '/layout.php';

$user = require_user();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $notePublicId = upload_note_file($_FILES['note_md'] ?? [], (int) $user['id']);
        set_flash('success', 'ノートを登録しました。');
        redirect_to('note.php?id=' . rawurlencode($notePublicId));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = db()->prepare(
    'SELECT n.id, n.public_id, n.title, n.original_filename, n.created_at, n.updated_at, owner.name AS owner_name,
            CASE WHEN n.user_id = ? THEN \'owner\' ELSE \'view\' END AS access_role
       FROM ' . table_name('notes') . ' n
       INNER JOIN ' . table_name('users') . ' owner ON owner.id = n.user_id
       LEFT JOIN ' . table_name('note_shares') . ' ns ON ns.note_id = n.id AND ns.user_id = ?
      WHERE n.user_id = ? OR ns.user_id = ?
      ORDER BY updated_at DESC, created_at DESC'
);
$stmt->execute([(int) $user['id'], (int) $user['id'], (int) $user['id'], (int) $user['id']]);
$notes = $stmt->fetchAll();

ob_start();
?>
<section class="dashboard-summary dashboard-summary-row">
  <div>
    <p class="eyebrow">Notes</p>
    <h1>ノート</h1>
    <p>Markdownファイルをアップロードして、読みやすいブログ形式で表示します。</p>
  </div>
  <button class="primary-button summary-action" type="button" data-note-modal-open>新規登録</button>
</section>

<div class="upload-modal-backdrop" data-note-modal <?= $error === '' ? 'hidden' : '' ?>>
  <div class="upload-modal" role="dialog" aria-modal="true" aria-labelledby="note-modal-title">
    <div class="upload-modal-header">
      <div>
        <p class="eyebrow">New Note</p>
        <h2 id="note-modal-title">Markdownノートを登録</h2>
        <p>.mdファイルをアップロードすると、HTMLのブログ形式で表示します。</p>
      </div>
      <button class="icon-button" type="button" data-note-modal-close aria-label="閉じる">×</button>
    </div>
    <?php if ($error !== ''): ?>
      <div class="notice error"><?= h($error) ?></div>
    <?php endif; ?>
    <form class="upload-form" action="<?= h(base_url('notes.php')) ?>" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="field">
        <label for="note_md">Markdownファイル</label>
        <input id="note_md" name="note_md" type="file" accept=".md,text/markdown,text/plain" required>
        <p class="help-text">最大2MB。最初のH1見出しをノートタイトルとして使います。</p>
      </div>
      <button class="primary-button" type="submit">ノート登録</button>
    </form>
  </div>
</div>

<section class="panel">
  <div class="panel-title-row">
    <h2>ノート一覧</h2>
    <span><?= count($notes) ?>件</span>
  </div>
  <?php if ($notes === []): ?>
    <div class="empty-state">まだノートが登録されていません。</div>
  <?php else: ?>
    <div class="project-list">
      <?php foreach ($notes as $note): ?>
        <a class="project-row" href="<?= h(base_url(note_path($note))) ?>">
          <span>
            <strong>
              <?= h($note['title']) ?>
              <?php if (($note['access_role'] ?? 'owner') !== 'owner'): ?>
                <span class="project-badge">共有</span>
              <?php endif; ?>
            </strong>
            <small>
              <?= h($note['original_filename']) ?>
              <?php if (($note['access_role'] ?? 'owner') !== 'owner'): ?>
                ・所有者: <?= h($note['owner_name']) ?>
              <?php endif; ?>
            </small>
          </span>
          <span><?= h(date('Y/m/d H:i', strtotime((string) $note['updated_at']))) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<script>
  (() => {
    const openButton = document.querySelector('[data-note-modal-open]');
    const modal = document.querySelector('[data-note-modal]');
    const closeButton = document.querySelector('[data-note-modal-close]');
    const fileInput = document.getElementById('note_md');
    if (!openButton || !modal) {
      return;
    }
    const openModal = () => {
      modal.hidden = false;
      document.body.classList.add('modal-open');
      window.setTimeout(() => fileInput && fileInput.focus(), 0);
    };
    const closeModal = () => {
      modal.hidden = true;
      document.body.classList.remove('modal-open');
    };
    if (!modal.hidden) {
      document.body.classList.add('modal-open');
    }
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
render_app_page('ノート', ob_get_clean());
