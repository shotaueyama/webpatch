<?php

require __DIR__ . '/_app.php';
require $GLOBALS['webpatch_app_root'] . '/layout.php';

$user = require_user();
$projectRef = (string) ($_GET['id'] ?? '');
$project = find_project_for_user_ref($projectRef, (int) $user['id']);

if ($project === null) {
    http_response_code(404);
    render_app_page('プロジェクトが見つかりません', '<div class="notice error">プロジェクトが見つかりません。</div>');
    exit;
}

$canManageProject = user_owns_project($project, (int) $user['id']);
$isUrlSource = project_is_url_source($project);
$canEdit = !$isUrlSource && project_role_allows_edit($project, (int) $user['id']);
$canRefreshUrl = $isUrlSource && project_role_allows_edit($project, (int) $user['id']);
$sharedUsers = $canManageProject ? shared_users_for_project((int) $project['id']) : [];
$publicLink = $canManageProject ? public_link_for_project((int) $project['id']) : null;
$copyPrompt = project_copy_prompt_for_user((int) $project['id'], (int) $user['id']);
$projectGitSettings = project_git_settings($project);
$savedBasicAuth = null;
if ($isUrlSource) {
    $savedBasicAuth = url_project_saved_basic_auth(url_project_map($project));
}

$files = [];
$root = project_root($project);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile()) {
        $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
        if (is_html_file($relative)) {
            $files[] = $relative;
        }
    }
}
sort($files);
$fileTitles = project_file_display_titles($project, $files);
$fileCopyTargets = project_file_copy_targets($project, $files);
$pageCommentMarkerStates = page_comment_marker_states_for_project((int) $project['id']);

$activeFile = (string) ($_GET['file'] ?? $project['entry_file']);
if (!in_array($activeFile, $files, true)) {
    $activeFile = $project['entry_file'];
}
$canDeletePage = project_role_allows_edit($project, (int) $user['id']) && count($files) > 1;
$canDeleteProject = user_owns_project($project, (int) $user['id']);
$activeFileTitle = $fileTitles[$activeFile] ?? (pathinfo($activeFile, PATHINFO_FILENAME) ?: $activeFile);
$publicLinkUrl = ($publicLink !== null && (int) $publicLink['enabled'] === 1)
    ? public_project_url((string) $publicLink['token'], $activeFile)
    : '';
$projectPublicRef = project_public_ref($project);
$previewUrl = base_url('preview.php?id=' . rawurlencode($projectPublicRef) . '&file=' . rawurlencode($activeFile));
$iconReset = '<svg class="header-button-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7.35 8.15A6.65 6.65 0 1 1 6.2 13.9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M7.35 4.65v3.5h-3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

ob_start();
?>
<section class="project-layout">
  <section class="preview-panel">
    <div class="preview-stage" data-preview-stage data-viewport="desktop">
      <iframe class="site-preview" title="<?= h($project['title']) ?> プレビュー" sandbox="allow-same-origin allow-scripts allow-forms" src="<?= h($previewUrl) ?>"></iframe>
    </div>
  </section>
  <aside class="file-panel" aria-label="右サイドバー">
    <div class="file-panel-header">
      <div class="viewport-controls" aria-label="プレビュー幅">
        <button class="viewport-button active" type="button" data-preview-mode="desktop" aria-pressed="true" title="デスクトップ表示">
          <span class="device-icon desktop-icon" aria-hidden="true"></span>
          <span class="sr-only">デスクトップ</span>
        </button>
        <button class="viewport-button" type="button" data-preview-mode="tablet" aria-pressed="false" title="タブレット表示">
          <span class="device-icon tablet-icon" aria-hidden="true"></span>
          <span class="sr-only">タブレット</span>
        </button>
        <button class="viewport-button" type="button" data-preview-mode="mobile" aria-pressed="false" title="スマホ表示">
          <span class="device-icon mobile-icon" aria-hidden="true"></span>
          <span class="sr-only">スマホ</span>
        </button>
        <a class="viewport-button fullscreen-preview-button" href="<?= h($previewUrl) ?>" target="_blank" rel="noopener noreferrer" title="全画面表示" aria-label="サイトを新しいタブで全画面表示">
          <span class="fullscreen-icon" aria-hidden="true"></span>
          <span class="sr-only">全画面表示</span>
        </a>
      </div>
      <div class="sidebar-tabs" role="tablist" aria-label="右サイドバー表示">
        <button class="sidebar-tab active" type="button" role="tab" aria-selected="true" aria-controls="pages-tab" id="pages-tab-button" data-sidebar-tab="pages">ページ</button>
        <button class="sidebar-tab" type="button" role="tab" aria-selected="false" aria-controls="comments-tab" id="comments-tab-button" data-sidebar-tab="comments">コメント</button>
      </div>
    </div>
    <section class="sidebar-tab-panel active" id="pages-tab" role="tabpanel" aria-labelledby="pages-tab-button" data-sidebar-panel="pages">
      <div class="project-summary">
        <p class="eyebrow">Pages</p>
        <h1>
          <?= h($project['title']) ?>
          <?php if (!$canManageProject): ?>
            <span class="project-badge"><?= $canEdit ? '共有・編集可' : '共有' ?></span>
          <?php elseif ($isUrlSource): ?>
            <span class="project-badge">URL登録</span>
          <?php endif; ?>
        </h1>
        <div class="active-page-meta">
          <strong><?= h($activeFileTitle) ?></strong>
          <span><?= h($activeFile) ?></span>
        </div>
      </div>
      <div class="file-list">
        <?php foreach ($files as $file): ?>
          <?php $pageMarker = $pageCommentMarkerStates[$file] ?? null; ?>
          <a class="<?= $file === $activeFile ? 'active' : '' ?>" href="<?= h(base_url(project_path($project, $file))) ?>">
            <span class="file-list-title">
              <span><?= h($fileTitles[$file] ?? $file) ?></span>
              <?php if ($pageMarker !== null): ?>
                <?php
                  $markerState = (string) ($pageMarker['state'] ?? 'attention');
                  $markerCount = (int) ($pageMarker['count'] ?? 0);
                  $markerLabel = $markerState === 'pending' ? '確認待ちコメント' : '未対応コメント';
                ?>
                <span class="page-comment-dot <?= $markerState === 'pending' ? 'pending' : 'attention' ?>" title="<?= h($markerLabel . ' ' . $markerCount . '件') ?>" aria-label="<?= h($markerLabel . ' ' . $markerCount . '件') ?>"></span>
              <?php endif; ?>
            </span>
            <span class="file-list-path"><?= h($file) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if ($canRefreshUrl): ?>
        <div class="url-refresh-panel">
          <h2>URLを再取得</h2>
          <p>登録済みのURL一覧をもう一度取得して、保存済みHTMLを更新します。既存コメントは同じページに残ります。</p>
          <form class="url-refresh-form" action="<?= h(base_url('refresh-url-project.php')) ?>" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="project_id" value="<?= h($projectPublicRef) ?>">
            <div class="basic-auth-fields">
              <div class="field">
                <label for="refresh_basic_auth_username">Basic認証ユーザー名</label>
                <input id="refresh_basic_auth_username" name="basic_auth_username" type="text" autocomplete="off" placeholder="必要な場合のみ">
              </div>
              <div class="field">
                <label for="refresh_basic_auth_password">Basic認証パスワード</label>
                <input id="refresh_basic_auth_password" name="basic_auth_password" type="password" autocomplete="off" placeholder="必要な場合のみ">
              </div>
              <p class="help-text">
                <?php if ($savedBasicAuth !== null): ?>
                  保存済みのBasic認証（ユーザー名: <?= h((string) $savedBasicAuth['username']) ?>）を使用します。変更する場合だけ、ユーザー名とパスワードを再入力してください。
                <?php else: ?>
                  Basic認証があるサイトはここに入力してください。入力した認証情報は次回以降のURL再取得でも使用します。
                <?php endif; ?>
              </p>
            </div>
            <button class="secondary-button" type="submit">URLを再取得</button>
          </form>
        </div>
      <?php endif; ?>
      <?php if ($canEdit && !$isUrlSource): ?>
        <div class="page-upload-panel">
          <h2>このページを上書き</h2>
          <form class="page-upload-form" action="<?= h(base_url('overwrite-page.php')) ?>" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="project_id" value="<?= h($projectPublicRef) ?>">
            <input type="hidden" name="file" value="<?= h($activeFile) ?>">
            <div class="field">
              <label for="page_html">HTMLファイル</label>
              <input id="page_html" name="page_html" type="file" accept=".html,.htm,text/html" required>
              <p class="help-text">現在選択中のページだけを上書きします。</p>
            </div>
            <button class="secondary-button" type="submit">上書きアップロード</button>
          </form>
        </div>
        <div class="site-replace-panel">
          <h2>サイト全体を差し替え</h2>
          <form class="site-replace-form" action="<?= h(base_url('replace-site.php')) ?>" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="project_id" value="<?= h($projectPublicRef) ?>">
            <div class="field">
              <label for="site_replace_zip">HTML+アセットZIP</label>
              <input id="site_replace_zip" name="site_zip" type="file" accept=".zip,application/zip" required>
              <p class="help-text">最大100MB。現在のサイトファイル一式を、新しいZIPの内容に差し替えます。</p>
            </div>
            <button class="secondary-button danger" type="submit">サイト全体を差し替え</button>
          </form>
        </div>
        <div class="page-actions-panel">
          <button class="reset-button sidebar-reset-button" type="button" data-reset-page><?= $iconReset ?><span>初期化</span></button>
        </div>
      <?php endif; ?>
    </section>
    <section class="sidebar-tab-panel" id="comments-tab" role="tabpanel" aria-labelledby="comments-tab-button" data-sidebar-panel="comments" hidden>
      <div class="comment-panel">
        <div class="comment-panel-heading">
          <h2>コメント</h2>
          <div class="comment-panel-actions">
            <button class="comment-sheet-button" type="button" data-ai-check-comments>AI確認</button>
            <a class="comment-sheet-button" href="<?= h(base_url('comment-sheet.php?id=' . rawurlencode($projectPublicRef))) ?>" target="_blank" rel="noopener noreferrer">シート</a>
          </div>
        </div>
        <div class="comment-list" data-comment-list aria-label="コメント一覧"></div>
      </div>
    </section>
  </aside>
</section>
<?php if ($canManageProject): ?>
<div class="share-modal-backdrop" data-share-modal hidden>
  <div class="share-modal" role="dialog" aria-modal="true" aria-labelledby="share-modal-title">
    <div class="share-modal-header">
      <div>
        <p class="eyebrow">Members</p>
        <h2 id="share-modal-title">メンバー共有</h2>
        <p>登録済みアカウントには即時共有し、未登録メールには招待リンクを送ります。</p>
      </div>
      <button class="icon-button" type="button" data-share-modal-close aria-label="閉じる">×</button>
    </div>
    <form class="share-form" data-share-form action="<?= h(base_url('share-project.php')) ?>" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="project_id" value="<?= h($projectPublicRef) ?>">
      <div class="field">
        <label for="share_email">メールアドレス</label>
        <input id="share_email" name="email" type="email" placeholder="member@example.com" autocomplete="email" required>
      </div>
      <div class="field">
        <label for="share_role">権限</label>
        <select id="share_role" name="role" required>
          <option value="comment">コメントのみ</option>
          <option value="edit">編集可能</option>
        </select>
      </div>
      <button class="primary-button" type="submit">共有する</button>
    </form>
    <div class="share-result" data-share-result aria-live="polite"></div>
    <div class="public-share-panel">
      <h3>公開コメントリンク</h3>
      <p>URLを知っているゲストはログインなしで閲覧・コメントできます。検索エンジンには表示されない設定です。</p>
      <div class="public-link-row">
        <input type="text" value="<?= h($publicLinkUrl) ?>" data-public-link-url readonly placeholder="まだ公開コメントリンクは有効ではありません">
        <button class="secondary-button" type="button" data-public-link-copy>コピー</button>
      </div>
      <div class="public-link-actions">
        <button class="secondary-button" type="button" data-public-link-action="enable">有効化</button>
        <button class="secondary-button" type="button" data-public-link-action="regenerate">再発行</button>
        <button class="secondary-button danger" type="button" data-public-link-action="disable">無効化</button>
      </div>
    </div>
    <div class="share-members">
      <h3>共有中のメンバー</h3>
      <?php if ($sharedUsers === []): ?>
        <p class="share-empty">まだ共有メンバーはいません。</p>
      <?php else: ?>
        <div class="member-list" aria-label="共有メンバー">
          <?php foreach ($sharedUsers as $sharedUser): ?>
            <div class="member-row">
              <span>
                <strong><?= h($sharedUser['name']) ?></strong>
                <small><?= h($sharedUser['email']) ?></small>
              </span>
              <label class="member-role-control">
                <span class="sr-only"><?= h($sharedUser['name']) ?> の権限</span>
                <select data-share-role-user="<?= (int) $sharedUser['id'] ?>">
                  <option value="comment" <?= (($sharedUser['role'] ?? 'comment') === 'comment') ? 'selected' : '' ?>>コメントのみ</option>
                  <option value="edit" <?= (($sharedUser['role'] ?? 'comment') === 'edit') ? 'selected' : '' ?>>編集可能</option>
                </select>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<div class="project-settings-modal-backdrop" data-project-settings-modal hidden>
  <div class="project-settings-modal" role="dialog" aria-modal="true" aria-labelledby="project-settings-title">
    <div class="project-settings-modal-header">
      <div>
        <h2 id="project-settings-title">設定</h2>
        <p class="eyebrow">Copy Settings</p>
        <h3 class="project-settings-section-title">コピー時の追加プロンプト</h3>
        <p>コメントをコピーするとき、ここに入力したプロンプトを末尾へ追加します。この設定はこのサイト内でユーザー別に保存されます。</p>
      </div>
      <button class="icon-button" type="button" data-project-settings-close aria-label="閉じる">×</button>
    </div>
    <form class="project-settings-form" data-project-settings-form>
      <div class="field">
        <label for="project_copy_prompt">追加プロンプト</label>
        <textarea id="project_copy_prompt" name="copy_prompt" rows="8" maxlength="5000" data-project-copy-prompt placeholder="例: 上記コメントを踏まえて、該当HTML/CSSの修正案を提示してください。"><?= h($copyPrompt) ?></textarea>
        <p class="field-hint">空欄で保存すると、追加プロンプトは付与されません。</p>
      </div>
      <?php if ($canManageProject): ?>
        <div class="project-settings-subsection">
          <h3 class="project-settings-section-title">Git連携</h3>
          <p>このサイトに紐づけるGitHubリポジトリとブランチを保存します。GitHubトークンはアカウント設定のものを使用します。</p>
        </div>
        <div class="field">
          <label for="project_git_repository_url">リポジトリURL</label>
          <input id="project_git_repository_url" name="git_repository_url" type="text" autocomplete="off" data-project-git-repository-url value="<?= h((string) $projectGitSettings['repository_url']) ?>" placeholder="https://github.com/owner/repo">
        </div>
        <div class="field">
          <label for="project_git_branch_name">ブランチ</label>
          <input id="project_git_branch_name" name="git_branch_name" type="text" autocomplete="off" data-project-git-branch-name value="<?= h((string) $projectGitSettings['branch_name']) ?>" placeholder="main">
          <p class="field-hint">サイトごとに保存されます。過去データとの比較やGit反映ではこのブランチを基準にします。</p>
        </div>
        <div class="project-settings-inline-actions">
          <button class="modal-button secondary" type="button" data-project-git-test>GitHub接続確認</button>
          <span class="project-settings-test-result" data-project-git-test-result role="status" aria-live="polite"></span>
        </div>
      <?php endif; ?>
      <div class="modal-actions">
        <button class="modal-button secondary" type="button" data-project-settings-close>キャンセル</button>
        <button class="modal-button primary" type="submit">保存する</button>
      </div>
      <?php if ($canDeleteProject): ?>
        <div class="project-settings-danger-zone">
          <div>
            <h3>サイト削除</h3>
            <p>このサイト全体を削除します。登録済みページ、コメント、共有設定、公開リンクも削除されます。</p>
          </div>
          <button class="modal-button danger" type="button" data-delete-project>このサイトを削除</button>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>
<div class="comment-modal-backdrop" data-comment-modal hidden>
  <div class="comment-modal" role="dialog" aria-modal="true" aria-labelledby="comment-modal-title">
    <div class="comment-modal-header">
      <div>
        <p class="eyebrow">Comment</p>
        <h2 id="comment-modal-title">コメント</h2>
        <p data-comment-modal-selector></p>
      </div>
      <div class="comment-modal-actions">
        <button class="comment-pending-button" type="button" data-comment-pending-toggle hidden>確認待ち</button>
        <button class="comment-resolve-button" type="button" data-comment-resolve-toggle hidden>解決済みにする</button>
        <button class="icon-button" type="button" data-comment-modal-close aria-label="閉じる">×</button>
      </div>
    </div>
    <div class="comment-thread" data-comment-thread></div>
    <form class="comment-reply-form" data-comment-reply-form>
      <div class="field">
        <label id="comment_reply_label" for="comment_reply">返信</label>
        <textarea id="comment_reply" name="body" rows="3" required></textarea>
      </div>
      <div class="field">
        <label for="comment_images">画像</label>
        <input id="comment_images" name="images[]" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple data-comment-images>
        <p class="field-hint">JPEG、PNG、GIF、WebPを最大8枚まで添付できます。</p>
      </div>
      <button class="primary-button" type="submit" data-comment-submit>返信する</button>
    </form>
  </div>
</div>
<div class="comment-delete-modal-backdrop" data-comment-delete-modal hidden>
  <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="comment-delete-title" aria-describedby="comment-delete-message">
    <div class="confirm-modal-header">
      <h3 id="comment-delete-title">コメントを削除しますか？</h3>
      <button class="icon-button" type="button" data-comment-delete-cancel aria-label="閉じる">×</button>
    </div>
    <p id="comment-delete-message">このコメントだけを削除します。返信がある場合、返信は残ります。</p>
    <div class="modal-actions">
      <button class="modal-button secondary" type="button" data-comment-delete-cancel>キャンセル</button>
      <button class="modal-button danger" type="button" data-comment-delete-confirm>削除する</button>
    </div>
  </div>
</div>
<?php if ($canDeletePage): ?>
<div class="page-delete-modal-backdrop" data-page-delete-modal hidden>
  <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="page-delete-title" aria-describedby="page-delete-message">
    <div class="confirm-modal-header">
      <h3 id="page-delete-title">このページを削除しますか？</h3>
      <button class="icon-button" type="button" data-page-delete-cancel aria-label="閉じる">×</button>
    </div>
    <p id="page-delete-message">
      <strong><?= h($activeFileTitle) ?></strong><br>
      <span><?= h($activeFile) ?></span><br>
      このページをページリストから削除します。このページに紐づくコメントも削除されます。
    </p>
    <div class="modal-actions">
      <button class="modal-button secondary" type="button" data-page-delete-cancel>キャンセル</button>
      <button class="modal-button danger" type="button" data-page-delete-confirm>削除する</button>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($canDeleteProject): ?>
<div class="project-delete-modal-backdrop" data-project-delete-modal hidden>
  <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="project-delete-title" aria-describedby="project-delete-message">
    <div class="confirm-modal-header">
      <h3 id="project-delete-title">このサイトを削除しますか？</h3>
      <button class="icon-button" type="button" data-project-delete-cancel aria-label="閉じる">×</button>
    </div>
    <p id="project-delete-message">
      <strong><?= h($project['title']) ?></strong><br>
      このサイト全体を削除します。ページ、コメント、共有、公開リンクは元に戻せません。
    </p>
    <div class="modal-actions">
      <button class="modal-button secondary" type="button" data-project-delete-cancel>キャンセル</button>
      <button class="modal-button danger" type="button" data-project-delete-confirm>削除する</button>
    </div>
  </div>
</div>
<?php endif; ?>
<div class="toast" data-action-toast role="status" aria-live="polite" aria-atomic="true"></div>
<script>
  (() => {
    const filePanel = document.querySelector('.file-panel');
    const headerMenu = document.querySelector('[data-header-menu]');
    const mobileQuery = window.matchMedia('(max-width: 860px)');

    if (!filePanel || !headerMenu) {
      return;
    }

    const originalParent = filePanel.parentNode;
    const originalNextSibling = filePanel.nextSibling;

    const scrollActivePageIntoView = () => {
      const activePage = filePanel.querySelector('.file-list a.active');
      if (!activePage) {
        return;
      }
      const panelRect = filePanel.getBoundingClientRect();
      const itemRect = activePage.getBoundingClientRect();
      if (panelRect.height <= 0 || itemRect.height <= 0) {
        return;
      }
      const panelHeader = filePanel.querySelector('.file-panel-header');
      const headerHeight = panelHeader ? panelHeader.getBoundingClientRect().height : 0;
      const visibleTop = panelRect.top + headerHeight;
      const visibleHeight = Math.max(1, filePanel.clientHeight - headerHeight);
      const offset = itemRect.top - visibleTop - (visibleHeight / 2) + (itemRect.height / 2);
      filePanel.scrollTop += offset;
    };

    const scheduleActivePageScroll = () => {
      window.requestAnimationFrame(() => {
        scrollActivePageIntoView();
        window.requestAnimationFrame(scrollActivePageIntoView);
      });
    };

    const syncFilePanelPlacement = () => {
      if (mobileQuery.matches) {
        if (filePanel.parentNode !== headerMenu) {
          headerMenu.append(filePanel);
        }
        scheduleActivePageScroll();
        return;
      }

      if (filePanel.parentNode !== originalParent) {
        originalParent.insertBefore(filePanel, originalNextSibling);
      }
      scheduleActivePageScroll();
    };

    syncFilePanelPlacement();
    window.addEventListener('webpatch:pages-tab-active', scheduleActivePageScroll);
    const headerMenuButton = document.querySelector('[data-header-menu-toggle]');
    if (headerMenuButton) {
      headerMenuButton.addEventListener('click', () => window.setTimeout(scheduleActivePageScroll, 0));
    }
    if (typeof mobileQuery.addEventListener === 'function') {
      mobileQuery.addEventListener('change', syncFilePanelPlacement);
    } else {
      mobileQuery.addListener(syncFilePanelPlacement);
    }
  })();

  (() => {
    const stage = document.querySelector('[data-preview-stage]');
    const previewPanel = stage ? stage.closest('.preview-panel') : null;
    const buttons = Array.from(document.querySelectorAll('[data-preview-mode]'));
    const modes = ['desktop', 'tablet', 'mobile'];
    const deviceSizes = {
      tablet: { width: 768, height: 1024 },
      mobile: { width: 390, height: 693 }
    };
    const storageKey = 'webpatch-preview-mode';

    if (!stage || buttons.length === 0) {
      return;
    }

    const resizeDeviceFrame = () => {
      const mode = stage.dataset.viewport;
      const size = deviceSizes[mode];
      if (!size || !previewPanel) {
        stage.style.removeProperty('--preview-width');
        stage.style.removeProperty('--preview-height');
        return;
      }

      const panelRect = previewPanel.getBoundingClientRect();
      const header = document.querySelector('.app-header');
      const headerHeight = header ? header.getBoundingClientRect().height : 76;
      const margin = window.matchMedia('(max-width: 860px)').matches ? 32 : 36;
      const availableWidth = Math.max(240, panelRect.width - margin);
      const availableHeight = Math.max(360, window.innerHeight - headerHeight - margin);
      const scale = Math.min(1, availableWidth / size.width, availableHeight / size.height);

      stage.style.setProperty('--preview-width', `${Math.floor(size.width * scale)}px`);
      stage.style.setProperty('--preview-height', `${Math.floor(size.height * scale)}px`);
    };

    const setMode = (mode) => {
      const nextMode = modes.includes(mode) ? mode : 'desktop';
      stage.dataset.viewport = nextMode;
      buttons.forEach((button) => {
        const isActive = button.dataset.previewMode === nextMode;
        button.classList.toggle('active', isActive);
        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
      });
      try {
        localStorage.setItem(storageKey, nextMode);
      } catch (error) {
      }
      resizeDeviceFrame();
      window.dispatchEvent(new CustomEvent('webpatch:preview-mode-change', { detail: { mode: nextMode } }));
    };

    window.webpatchGetPreviewMode = () => stage.dataset.viewport || 'desktop';
    window.webpatchSetPreviewMode = setMode;

    buttons.forEach((button) => {
      button.addEventListener('click', () => setMode(button.dataset.previewMode));
    });

    try {
      setMode(localStorage.getItem(storageKey) || 'desktop');
    } catch (error) {
      setMode('desktop');
    }

    window.addEventListener('resize', resizeDeviceFrame);
  })();

  (() => {
    const toast = document.querySelector('[data-action-toast]');
    let toastTimer = null;
    window.webpatchShowToast = (message, type = 'success') => {
      if (!toast || !message) {
        return;
      }
      window.clearTimeout(toastTimer);
      toast.textContent = message;
      toast.dataset.state = type;
      toast.classList.add('show');
      toastTimer = window.setTimeout(() => {
        toast.classList.remove('show');
      }, 3200);
    };
  })();

  (() => {
    const deleteButtons = Array.from(document.querySelectorAll('[data-delete-page]'));
    const modal = document.querySelector('[data-page-delete-modal]');
    const confirmButton = document.querySelector('[data-page-delete-confirm]');
    const cancelButtons = Array.from(document.querySelectorAll('[data-page-delete-cancel]'));
    const projectId = <?= json_encode($projectPublicRef, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const activeFile = <?= json_encode($activeFile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    if (deleteButtons.length === 0 || !modal || !confirmButton) {
      return;
    }

    let lastDeleteTrigger = deleteButtons[0];

    const showToast = (message, type = 'success') => {
      if (window.webpatchShowToast) {
        window.webpatchShowToast(message, type);
      }
    };
    const openModal = (event) => {
      lastDeleteTrigger = event && event.currentTarget ? event.currentTarget : deleteButtons[0];
      modal.hidden = false;
      document.body.classList.add('modal-open');
      confirmButton.focus();
    };
    const closeModal = () => {
      modal.hidden = true;
      document.body.classList.remove('modal-open');
      lastDeleteTrigger && lastDeleteTrigger.focus();
    };

    deleteButtons.forEach((button) => button.addEventListener('click', openModal));
    cancelButtons.forEach((button) => button.addEventListener('click', closeModal));
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

    confirmButton.addEventListener('click', async () => {
      confirmButton.disabled = true;
      deleteButtons.forEach((button) => {
        button.disabled = true;
      });
      try {
        const response = await fetch('<?= h(base_url('delete-project-page.php')) ?>', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            csrf_token: csrfToken,
            id: projectId,
            file: activeFile
          })
        });
        const responseText = await response.text();
        let result;
        try {
          result = JSON.parse(responseText);
        } catch (parseError) {
          throw new Error(response.redirected ? 'ログイン状態を確認してから、もう一度削除してください。' : 'ページ削除APIがJSONを返しませんでした。');
        }
        if (!response.ok || !result.ok) {
          throw new Error(result.message || 'ページを削除できませんでした。');
        }
        showToast(result.message || 'ページを削除しました。', 'success');
        window.location.href = result.redirect_url || '<?= h(base_url(project_path($project))) ?>';
      } catch (error) {
        showToast(error.message || 'ページを削除できませんでした。', 'error');
        confirmButton.disabled = false;
        deleteButtons.forEach((button) => {
          button.disabled = false;
        });
      }
    });
  })();

  (() => {
    const deleteButton = document.querySelector('[data-delete-project]');
    const modal = document.querySelector('[data-project-delete-modal]');
    const confirmButton = document.querySelector('[data-project-delete-confirm]');
    const cancelButtons = Array.from(document.querySelectorAll('[data-project-delete-cancel]'));
    const projectId = <?= json_encode($projectPublicRef, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    if (!deleteButton || !modal || !confirmButton) {
      return;
    }

    const showToast = (message, type = 'success') => {
      if (window.webpatchShowToast) {
        window.webpatchShowToast(message, type);
      }
    };
    const openModal = () => {
      modal.hidden = false;
      document.body.classList.add('modal-open');
      confirmButton.focus();
    };
    const closeModal = () => {
      modal.hidden = true;
      document.body.classList.remove('modal-open');
      deleteButton.focus();
    };

    deleteButton.addEventListener('click', openModal);
    cancelButtons.forEach((button) => button.addEventListener('click', closeModal));
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

    confirmButton.addEventListener('click', async () => {
      confirmButton.disabled = true;
      deleteButton.disabled = true;
      try {
        const response = await fetch('<?= h(base_url('delete-project.php')) ?>', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            csrf_token: csrfToken,
            id: projectId
          })
        });
        const responseText = await response.text();
        let result;
        try {
          result = JSON.parse(responseText);
        } catch (parseError) {
          throw new Error(response.redirected ? 'ログイン状態を確認してから、もう一度削除してください。' : 'サイト削除APIがJSONを返しませんでした。');
        }
        if (!response.ok || !result.ok) {
          throw new Error(result.message || 'サイトを削除できませんでした。');
        }
        showToast(result.message || 'サイトを削除しました。', 'success');
        window.location.href = result.redirect_url || '<?= h(base_url('dashboard.php')) ?>';
      } catch (error) {
        showToast(error.message || 'サイトを削除できませんでした。', 'error');
        confirmButton.disabled = false;
        deleteButton.disabled = false;
      }
    });
  })();

  (() => {
    const tabs = Array.from(document.querySelectorAll('[data-sidebar-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-sidebar-panel]'));
    if (tabs.length === 0 || panels.length === 0) {
      return;
    }

    const setTab = (name) => {
      tabs.forEach((tab) => {
        const isActive = tab.dataset.sidebarTab === name;
        tab.classList.toggle('active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
      });
      panels.forEach((panel) => {
        const isActive = panel.dataset.sidebarPanel === name;
        panel.classList.toggle('active', isActive);
        panel.hidden = !isActive;
      });
    };

    window.webpatchSetSidebarTab = setTab;
    tabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        const nextTab = tab.dataset.sidebarTab;
        setTab(nextTab);
        if (nextTab === 'pages') {
          window.dispatchEvent(new CustomEvent('webpatch:pages-tab-active'));
        }
        if (nextTab === 'comments' && typeof window.webpatchSetCommentMode === 'function') {
          window.webpatchSetCommentMode(true);
        }
      });
    });
  })();

  (() => {
    const iframe = document.querySelector('.site-preview');
    const commentToggle = document.querySelector('[data-comment-toggle]');
    const list = document.querySelector('[data-comment-list]');
    const modal = document.querySelector('[data-comment-modal]');
    const closeButton = document.querySelector('[data-comment-modal-close]');
    const modalTitle = document.getElementById('comment-modal-title');
    const modalSelector = document.querySelector('[data-comment-modal-selector]');
    const threadBody = document.querySelector('[data-comment-thread]');
    const replyForm = document.querySelector('[data-comment-reply-form]');
    const replyLabel = document.getElementById('comment_reply_label');
    const submitButton = document.querySelector('[data-comment-submit]');
    const resolveButton = document.querySelector('[data-comment-resolve-toggle]');
    const pendingButton = document.querySelector('[data-comment-pending-toggle]');
    const aiCheckButton = document.querySelector('[data-ai-check-comments]');
    const deleteModal = document.querySelector('[data-comment-delete-modal]');
    const deleteConfirmButton = document.querySelector('[data-comment-delete-confirm]');
    const deleteCancelButtons = Array.from(document.querySelectorAll('[data-comment-delete-cancel]'));
    const projectId = <?= json_encode($projectPublicRef, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const activeFile = <?= json_encode($activeFile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const fileCopyTargets = <?= json_encode($fileCopyTargets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const availableFiles = new Set(<?= json_encode(array_values($files), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
    const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const commentImageBaseUrl = <?= json_encode(base_url('comment-image.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    window.webpatchCopyPromptAddon = <?= json_encode($copyPrompt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const requestedCommentId = Number(new URLSearchParams(window.location.search).get('comment') || 0);
    let threads = [];
    let activeThreadId = null;
    let selectedThreadId = requestedCommentId > 0 ? requestedCommentId : null;
    let pendingFocusThreadId = requestedCommentId > 0 ? requestedCommentId : null;
    let draftSelector = null;
    let editCommentId = null;
    let editCommentFile = activeFile;
    let aiCheckPolling = false;
    let editFocusThreadId = null;
    let commentMode = false;
    let pendingDeleteComment = null;
    let hoverTarget = null;

    if (!iframe || !commentToggle || !list || !modal || !replyForm) {
      return;
    }

    const showToast = (message, type = 'success') => {
      if (window.webpatchShowToast) {
        window.webpatchShowToast(message, type);
      }
    };

    const pathWithoutRoot = (file) => {
      const parts = String(file || '').split('/').filter(Boolean);
      return parts.length > 1 ? parts.slice(1).join('/') : parts.join('/');
    };
    const resolveThreadFile = (thread) => {
      const file = thread.file_path || activeFile;
      if (availableFiles.has(file)) {
        return file;
      }
      const comparable = pathWithoutRoot(file);
      if (!comparable) {
        return '';
      }
      const matches = Array.from(availableFiles).filter((availableFile) => pathWithoutRoot(availableFile) === comparable);
      return matches.length === 1 ? matches[0] : '';
    };
    const threadById = (id) => threads.find((thread) => Number(thread.id) === Number(id));
    const isCurrentFileThread = (thread) => resolveThreadFile(thread) === activeFile;
    const threadFileExists = (thread) => Boolean(resolveThreadFile(thread));
    const threadNumber = (thread) => {
      const index = threads.findIndex((item) => Number(item.id) === Number(thread.id));
      return index >= 0 ? index + 1 : Number(thread.id);
    };
    const threadNumberLabel = (thread) => `#${threadNumber(thread)}`;
    const normalizePreviewMode = (mode) => ['desktop', 'tablet', 'mobile'].includes(mode) ? mode : 'desktop';
    const currentPreviewMode = () => normalizePreviewMode(
      typeof window.webpatchGetPreviewMode === 'function'
        ? window.webpatchGetPreviewMode()
        : (document.querySelector('[data-preview-stage]')?.dataset.viewport || 'desktop')
    );
    const comparablePreviewUrl = (url) => {
      try {
        const parsed = new URL(url, window.location.href);
        parsed.hash = '';
        parsed.search = '';
        let value = parsed.toString();
        return value.endsWith('/') ? value.slice(0, -1) : value;
      } catch (error) {
        return '';
      }
    };
    const previewUrlToFile = (url) => {
      const comparable = comparablePreviewUrl(url);
      if (!comparable) {
        return '';
      }
      for (const [file, targetUrl] of Object.entries(fileCopyTargets)) {
        if (availableFiles.has(file) && comparablePreviewUrl(targetUrl) === comparable) {
          return file;
        }
      }
      return '';
    };
    const currentPreviewFile = () => {
      try {
        const docFile = iframe.contentDocument?.documentElement?.getAttribute('data-webpatch-file') || '';
        if (availableFiles.has(docFile)) {
          return docFile;
        }
      } catch (error) {
      }
      try {
        const previewUrl = new URL(iframe.contentWindow.location.href);
        const file = previewUrl.searchParams.get('file') || '';
        if (availableFiles.has(file)) {
          return file;
        }
        return previewUrlToFile(previewUrl.href);
      } catch (error) {
        return '';
      }
    };
    const threadViewportMode = (thread) => normalizePreviewMode(thread && thread.viewport_mode ? thread.viewport_mode : 'desktop');
    const hasThreadViewport = (thread) => Boolean(thread && thread.viewport_mode);
    const threadMatchesCurrentViewport = (thread) => !hasThreadViewport(thread) || threadViewportMode(thread) === currentPreviewMode();
    const switchToThreadViewport = (thread) => {
      if (!hasThreadViewport(thread) || threadMatchesCurrentViewport(thread) || typeof window.webpatchSetPreviewMode !== 'function') {
        return false;
      }
      if (threadViewportMode(thread) === 'desktop' && currentPreviewMode() !== 'desktop') {
        return false;
      }
      window.webpatchSetPreviewMode(threadViewportMode(thread));
      return true;
    };
    const projectPageUrl = (file, commentId = '') => {
      const params = new URLSearchParams({ id: String(projectId), file });
      if (commentId) {
        params.set('comment', String(commentId));
      }
      return `<?= h(base_url('project.php')) ?>?${params.toString()}`;
    };
    const navigateToThreadPage = (thread) => {
      const file = resolveThreadFile(thread);
      if (!file) {
        showToast(`コメント ${threadNumberLabel(thread)} のページが現在のサイト内に見つかりません。`, 'error');
        return true;
      }
      if (file !== activeFile) {
        window.location.href = projectPageUrl(file, thread.id);
        return true;
      }
      return false;
    };
    const baseName = (file) => {
      const parts = String(file || '').split('/');
      return parts[parts.length - 1] || file;
    };
    const displayFileLabel = (file) => pathWithoutRoot(file) || baseName(file);

    const escapeCss = (value) => {
      if (window.CSS && typeof window.CSS.escape === 'function') {
        return window.CSS.escape(value);
      }
      return value.replace(/[^A-Za-z0-9_-]/g, '\\$&');
    };

    const commentTargetElement = (element) => {
      if (!element || element.nodeType !== 1) {
        return element;
      }
      const mediaTags = new Set(['img', 'picture', 'video', 'canvas', 'svg']);
      const tag = element.tagName ? element.tagName.toLowerCase() : '';
      if (!mediaTags.has(tag)) {
        return element;
      }
      const parent = element.parentElement;
      if (!parent || parent === element.ownerDocument.body || parent === element.ownerDocument.documentElement) {
        return element;
      }
      return parent;
    };

    const selectorForElement = (element, doc) => {
      element = commentTargetElement(element);
      if (element.id) {
        return `#${escapeCss(element.id)}`;
      }

      const parts = [];
      let node = element;
      while (node && node.nodeType === 1 && node !== doc.body && node !== doc.documentElement) {
        const tag = node.tagName.toLowerCase();
        const siblings = Array.from(node.parentElement ? node.parentElement.children : []);
        const sameTag = siblings.filter((sibling) => sibling.tagName.toLowerCase() === tag);
        const index = sameTag.indexOf(node) + 1;
        let part = tag;
        const stableClasses = Array.from(node.classList).filter((className) => !className.startsWith('webpatch-'));
        if (stableClasses.length > 0) {
          part += `.${escapeCss(stableClasses[0])}`;
        }
        if (sameTag.length > 1) {
          part += `:nth-of-type(${index})`;
        }
        parts.unshift(part);
        const candidate = parts.join(' > ');
        try {
          if (doc.querySelectorAll(candidate).length === 1) {
            return candidate;
          }
        } catch (error) {
        }
        node = node.parentElement;
      }

      return parts.length > 0 ? parts.join(' > ') : 'body';
    };

    const formatCommentTime = (value) => {
      const text = String(value || '').trim();
      if (!text) {
        return '';
      }
      const normalized = text.includes('T') ? text : text.replace(' ', 'T');
      const date = new Date(normalized);
      if (Number.isNaN(date.getTime())) {
        return text;
      }
      return new Intl.DateTimeFormat('ja-JP', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
      }).format(date);
    };

    const buildMessage = (comment, thread) => {
      const item = document.createElement('div');
      item.className = 'comment-message';
      item.classList.toggle('copyable', !comment.is_own);
      const meta = document.createElement('div');
      meta.className = 'comment-meta';
      const metaText = document.createElement('span');
      metaText.className = 'comment-meta-main';
      const author = document.createElement('span');
      author.className = 'comment-author';
      author.textContent = comment.user_name || 'ゲスト';
      const time = document.createElement('time');
      time.className = 'comment-time';
      time.dateTime = comment.created_at || '';
      time.textContent = formatCommentTime(comment.created_at);
      metaText.append(author);
      if (time.textContent) {
        metaText.append(time);
      }
      const actions = document.createElement('div');
      actions.className = 'comment-message-actions';
      meta.append(metaText);
      if (comment.is_own) {
        const editButton = document.createElement('button');
        editButton.className = 'comment-message-action comment-message-edit-button';
        editButton.type = 'button';
        editButton.textContent = '編集';
        editButton.addEventListener('click', (event) => {
          event.stopPropagation();
          openEditModal(comment, thread || comment);
        });
        actions.append(editButton);
      }
      if (comment.can_delete) {
        const deleteButton = document.createElement('button');
        deleteButton.className = 'comment-message-action comment-delete-button';
        deleteButton.type = 'button';
        deleteButton.textContent = '削除';
        deleteButton.addEventListener('click', (event) => {
          event.stopPropagation();
          deleteComment(comment);
        });
        actions.append(deleteButton);
      }
      if (actions.children.length > 0) {
        meta.append(actions);
      }
      const body = document.createElement('p');
      body.textContent = comment.body;
      item.append(meta, body);
      if (Array.isArray(comment.images) && comment.images.length > 0) {
        const gallery = document.createElement('div');
        gallery.className = 'comment-image-gallery';
        comment.images.forEach((image) => {
          const link = document.createElement('a');
          link.href = `${commentImageBaseUrl}?id=${encodeURIComponent(image.id)}`;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          link.addEventListener('click', (event) => event.stopPropagation());
          const img = document.createElement('img');
          img.src = link.href;
          img.alt = image.filename || 'コメント画像';
          img.loading = 'lazy';
          link.append(img);
          gallery.append(link);
        });
        item.append(gallery);
      }
      if (!comment.is_own) {
        item.addEventListener('click', () => copyCommentReference(comment, thread || comment));
      }
      return item;
    };

    const renderModal = (thread, options = {}) => {
      const preserveScroll = options.preserveScroll !== false;
      const previewWindow = iframe.contentWindow;
      const previewScrollX = previewWindow ? previewWindow.scrollX : 0;
      const previewScrollY = previewWindow ? previewWindow.scrollY : 0;
      const pageScrollX = window.scrollX;
      const pageScrollY = window.scrollY;
      activeThreadId = thread.id;
      markThreadRead(thread);
      draftSelector = null;
      editCommentId = null;
      editCommentFile = thread.file_path || activeFile;
      editFocusThreadId = null;
      modalTitle.textContent = `コメント ${threadNumberLabel(thread)}`;
      modalSelector.textContent = `${thread.file_path || activeFile} / ${thread.selector || ''}`;
      threadBody.replaceChildren(buildMessage(thread, thread), ...thread.replies.map((reply) => buildMessage(reply, thread)));
      threadBody.classList.toggle('resolved', Boolean(thread.is_resolved));
      replyForm.reset();
      if (replyLabel) {
        replyLabel.textContent = '返信';
      }
      if (submitButton) {
        submitButton.textContent = '返信する';
      }
      if (resolveButton) {
        resolveButton.hidden = !thread.can_resolve;
        resolveButton.classList.toggle('resolved', Boolean(thread.is_resolved));
        resolveButton.textContent = thread.is_resolved ? '解決済みを解除' : '解決済みにする';
      }
      if (pendingButton) {
        pendingButton.hidden = !thread.can_resolve;
        pendingButton.classList.toggle('pending', Boolean(thread.is_confirmation_pending));
        pendingButton.textContent = thread.is_confirmation_pending ? '確認待ち解除' : '確認待ち';
      }
      modal.hidden = false;
      requestAnimationFrame(() => {
        if (preserveScroll && previewWindow) {
          previewWindow.scrollTo(previewScrollX, previewScrollY);
        }
        if (preserveScroll) {
          window.scrollTo(pageScrollX, pageScrollY);
        }
        replyForm.querySelector('textarea').focus({ preventScroll: true });
      });
    };

    const openCreateModal = (selector) => {
      const previewWindow = iframe.contentWindow;
      const previewScrollX = previewWindow ? previewWindow.scrollX : 0;
      const previewScrollY = previewWindow ? previewWindow.scrollY : 0;
      const pageScrollX = window.scrollX;
      const pageScrollY = window.scrollY;
      activeThreadId = null;
      draftSelector = selector;
      editCommentId = null;
      editCommentFile = currentPreviewFile();
      editFocusThreadId = null;
      modalTitle.textContent = 'コメントを追加';
      modalSelector.textContent = selector;
      threadBody.replaceChildren();
      threadBody.classList.remove('resolved');
      replyForm.reset();
      if (replyLabel) {
        replyLabel.textContent = 'コメント';
      }
      if (submitButton) {
        submitButton.textContent = 'コメントする';
      }
      if (resolveButton) {
        resolveButton.hidden = true;
        resolveButton.classList.remove('resolved');
      }
      if (pendingButton) {
        pendingButton.hidden = true;
        pendingButton.classList.remove('pending');
      }
      modal.hidden = false;
      requestAnimationFrame(() => {
        if (previewWindow) {
          previewWindow.scrollTo(previewScrollX, previewScrollY);
        }
        window.scrollTo(pageScrollX, pageScrollY);
        replyForm.querySelector('textarea').focus({ preventScroll: true });
      });
    };

    const openEditModal = (comment, thread) => {
      const previewWindow = iframe.contentWindow;
      const previewScrollX = previewWindow ? previewWindow.scrollX : 0;
      const previewScrollY = previewWindow ? previewWindow.scrollY : 0;
      const pageScrollX = window.scrollX;
      const pageScrollY = window.scrollY;
      activeThreadId = null;
      draftSelector = null;
      editCommentId = comment.id;
      editCommentFile = resolveThreadFile(thread || comment) || comment.file_path || activeFile;
      editFocusThreadId = thread ? thread.id : (comment.parent_id || comment.id);
      modalTitle.textContent = 'コメントを編集';
      modalSelector.textContent = `${editCommentFile} / ${(thread && thread.selector) || comment.selector || ''}`;
      threadBody.replaceChildren();
      threadBody.classList.remove('resolved');
      replyForm.reset();
      replyForm.elements.body.value = comment.body || '';
      if (replyLabel) {
        replyLabel.textContent = 'コメント';
      }
      if (submitButton) {
        submitButton.textContent = '保存する';
      }
      if (resolveButton) {
        resolveButton.hidden = true;
        resolveButton.classList.remove('resolved');
      }
      if (pendingButton) {
        pendingButton.hidden = true;
        pendingButton.classList.remove('pending');
      }
      modal.hidden = false;
      requestAnimationFrame(() => {
        if (previewWindow) {
          previewWindow.scrollTo(previewScrollX, previewScrollY);
        }
        window.scrollTo(pageScrollX, pageScrollY);
        replyForm.querySelector('textarea').focus({ preventScroll: true });
      });
    };

    const closeModal = () => {
      modal.hidden = true;
      activeThreadId = null;
      draftSelector = null;
      editCommentId = null;
      editCommentFile = activeFile;
      editFocusThreadId = null;
    };

    const setCommentMode = (enabled) => {
      commentMode = enabled;
      commentToggle.classList.toggle('active', commentMode);
      commentToggle.setAttribute('aria-pressed', commentMode ? 'true' : 'false');
      let doc;
      try {
        doc = iframe.contentDocument;
      } catch (error) {
        doc = null;
      }
      if (doc && doc.documentElement) {
        doc.documentElement.classList.toggle('webpatch-comment-mode', commentMode);
        if (commentMode) {
          renderMarkers();
        } else {
          clearMarkers(doc);
          hideHoverBox(doc);
        }
      }
      if (commentMode) {
        if (window.webpatchSetSidebarTab) {
          window.webpatchSetSidebarTab('comments');
        }
        showToast('コメントしたい要素をクリックしてください。', 'success');
      }
    };

    window.webpatchSetCommentMode = setCommentMode;

    const scrollToThread = (thread) => {
      if (!thread || !thread.selector) {
        return false;
      }
      if (!isCurrentFileThread(thread)) {
        if (!threadFileExists(thread)) {
          showToast(`コメント ${threadNumberLabel(thread)} のページが現在のサイト内に見つかりません。`, 'error');
          return false;
        }
        window.location.href = projectPageUrl(resolveThreadFile(thread), thread.id);
        return true;
      }

      if (switchToThreadViewport(thread)) {
        [120, 320, 700].forEach((delay) => {
          window.setTimeout(() => scrollToThread(thread), delay);
        });
        return true;
      }

      let doc;
      try {
        doc = iframe.contentDocument;
      } catch (error) {
        doc = null;
      }
      if (!doc) {
        return false;
      }

      let target = null;
      try {
        target = doc.querySelector(thread.selector);
      } catch (error) {
        target = null;
      }
      if (!target) {
        showToast(`コメント ${threadNumberLabel(thread)} の対象要素が現在のHTML内に見つかりません。`, 'error');
        renderList();
        return false;
      }
      target = commentTargetElement(target);

      const previewWindow = iframe.contentWindow;
      if (!previewWindow) {
        return false;
      }
      const rect = target.getBoundingClientRect();
      const nextTop = Math.max(0, previewWindow.scrollY + rect.top + rect.height / 2 - previewWindow.innerHeight / 2);
      previewWindow.scrollTo({ left: previewWindow.scrollX, top: nextTop, behavior: 'smooth' });
      [0, 120, 320, 700].forEach((delay) => {
        window.setTimeout(() => {
          if (commentMode) {
            renderMarkers();
          }
        }, delay);
      });
      return true;
    };

    const threadTargetExists = (thread) => {
      if (!isCurrentFileThread(thread) || !thread.selector) {
        return false;
      }
      if (!threadMatchesCurrentViewport(thread)) {
        return true;
      }
      let doc;
      try {
        doc = iframe.contentDocument;
      } catch (error) {
        doc = null;
      }
      if (!doc) {
        return true;
      }
      try {
        return Boolean(doc.querySelector(thread.selector));
      } catch (error) {
        return false;
      }
    };

    const focusCommentListItem = (thread) => {
      if (!thread || !list) {
        return;
      }
      selectedThreadId = Number(thread.id);
      if (commentMode) {
        renderMarkers();
      }
      if (window.webpatchSetSidebarTab) {
        window.webpatchSetSidebarTab('comments');
      }
      const item = list.querySelector(`[data-thread-id="${thread.id}"]`);
      if (!item) {
        return;
      }
      list.querySelectorAll('.comment-list-item.active').forEach((activeItem) => {
        activeItem.classList.remove('active');
      });
      item.classList.add('active');
      item.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    const commentClipboardText = (comment, thread) => {
      const file = thread.file_path || activeFile;
      const target = `${fileCopyTargets[file] || file} の ${thread.selector || ''}`;
      const baseText = `#対象 : ${target}\n#コメント : ${comment.body || ''}`;
      const prompt = String(window.webpatchCopyPromptAddon || '').trim();
      return prompt ? `${baseText}\n\n${prompt}` : baseText;
    };

    const copyTextToClipboard = async (text) => {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return;
      }
      const textarea = document.createElement('textarea');
      textarea.value = text;
      textarea.setAttribute('readonly', '');
      textarea.style.position = 'fixed';
      textarea.style.left = '-9999px';
      textarea.style.top = '0';
      document.body.append(textarea);
      textarea.select();
      document.execCommand('copy');
      textarea.remove();
    };

    const copyCommentReference = async (comment, thread) => {
      try {
        await copyTextToClipboard(commentClipboardText(comment, thread));
        showToast('コメント情報をコピーしました。', 'success');
      } catch (error) {
        showToast('クリップボードへコピーできませんでした。', 'error');
      }
    };

    const markThreadRead = async (thread) => {
      if (!thread || !thread.id) {
        return;
      }
      thread.has_unread_activity = false;
      renderList();
      try {
        const response = await fetch('<?= h(base_url('comments.php')) ?>', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            csrf_token: csrfToken,
            id: projectId,
            file: resolveThreadFile(thread) || activeFile,
            action: 'mark_read',
            comment_id: thread.id
          })
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
          throw new Error(result.message || '既読状態を更新できませんでした。');
        }
      } catch (error) {
      }
    };

    const activateCommentFromList = async (thread) => {
      if (navigateToThreadPage(thread)) {
        return;
      }
      focusCommentListItem(thread);
      markThreadRead(thread);
      if (!scrollToThread(thread)) {
        renderList();
      }
    };

    const renderList = () => {
      list.replaceChildren();
      if (threads.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'share-empty';
        empty.textContent = 'まだコメントはありません。';
        list.append(empty);
        return;
      }

      threads.forEach((thread, index) => {
        const button = document.createElement('article');
        button.className = 'comment-list-item';
        const currentMissingTarget = !thread.is_resolved && hasThreadViewport(thread) && isCurrentFileThread(thread) && !threadTargetExists(thread);
        button.classList.toggle('resolved', Boolean(thread.is_resolved));
        button.classList.toggle('confirmation-pending', Boolean(thread.is_confirmation_pending));
        button.classList.toggle('missing-target', currentMissingTarget);
        button.classList.toggle('unread-activity', Boolean(thread.has_unread_activity));
        button.classList.toggle('active', Number(thread.id) === Number(selectedThreadId));
        button.setAttribute('role', 'button');
        button.tabIndex = 0;
        button.dataset.threadId = thread.id;
        const label = document.createElement('strong');
        label.textContent = `${threadNumberLabel(thread)} ${thread.selector}`;
        if (!thread.is_resolved && !thread.is_confirmation_pending) {
          const newDot = document.createElement('span');
          newDot.className = 'comment-new-dot';
          newDot.title = '新しいコメント';
          newDot.setAttribute('aria-label', '新しいコメント');
          label.prepend(newDot);
        } else if (thread.is_confirmation_pending) {
          const pendingDot = document.createElement('span');
          pendingDot.className = 'comment-pending-dot';
          pendingDot.title = '確認待ち';
          pendingDot.setAttribute('aria-label', '確認待ち');
          label.prepend(pendingDot);
        }
        const page = document.createElement('span');
        page.className = 'comment-page-label';
        page.textContent = displayFileLabel(thread.file_path || activeFile);
        const body = document.createElement('small');
        body.textContent = thread.body;
        const count = document.createElement('span');
        count.textContent = `${thread.replies.length}件の返信`;
        const actions = document.createElement('div');
        actions.className = 'comment-card-actions';
        const openButton = document.createElement('button');
        openButton.className = 'comment-card-action-button comment-open-button';
        openButton.type = 'button';
        openButton.setAttribute('aria-label', `コメント ${threadNumber(thread)} をポップアップで開く`);
        openButton.title = 'ポップアップで開く';
        openButton.innerHTML = '<svg class="comment-card-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6.75 5.5h10.5A3.25 3.25 0 0 1 20.5 8.75v5.5a3.25 3.25 0 0 1-3.25 3.25h-3.9l-3.82 2.86a.72.72 0 0 1-1.15-.58V17.5H6.75A3.25 3.25 0 0 1 3.5 14.25v-5.5A3.25 3.25 0 0 1 6.75 5.5Z" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linejoin="round"/><path d="M8.25 10h7.5M8.25 13h5.25" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round"/></svg>';
        openButton.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          if (navigateToThreadPage(thread)) {
            return;
          }
          focusCommentListItem(thread);
          renderModal(thread);
        });
        actions.append(openButton);
        if (thread.is_resolved || currentMissingTarget) {
          const status = document.createElement('span');
          status.className = 'comment-status-label';
          status.textContent = thread.is_resolved ? '解決済み' : 'ピン未検出';
          button.append(label, status, page, body, count, actions);
        } else {
          button.append(label, page, body, count, actions);
        }
        button.addEventListener('click', () => {
          activateCommentFromList(thread);
        });
        button.addEventListener('keydown', (event) => {
          if (event.key !== 'Enter' && event.key !== ' ') {
            return;
          }
          event.preventDefault();
          activateCommentFromList(thread);
        });
        list.append(button);
      });
    };

    const clearMarkers = (doc) => {
      doc.querySelectorAll('[data-webpatch-comment-marker]').forEach((marker) => marker.remove());
      const layer = doc.getElementById('webpatch-comment-marker-layer');
      if (layer) {
        layer.remove();
      }
      doc.querySelectorAll('.webpatch-comment-hover').forEach((element) => {
        element.classList.remove('webpatch-comment-hover');
      });
      doc.querySelectorAll('.webpatch-comment-focus').forEach((element) => {
        element.classList.remove('webpatch-comment-focus');
      });
      doc.querySelectorAll('.webpatch-comment-target-active').forEach((element) => {
        element.classList.remove('webpatch-comment-target-active');
      });
      doc.querySelectorAll('[data-webpatch-comment-target], [data-webpatch-marker-host]').forEach((element) => {
        if (element.hasAttribute('data-webpatch-original-position')) {
          const originalPosition = element.getAttribute('data-webpatch-original-position') || '';
          if (originalPosition === '__missing__') {
            element.style.removeProperty('position');
          } else {
            element.style.setProperty('position', originalPosition);
          }
          element.removeAttribute('data-webpatch-original-position');
        }
        element.removeAttribute('data-webpatch-comment-target');
        element.removeAttribute('data-webpatch-marker-host');
        element.classList.remove('webpatch-comment-target');
      });
    };

    const ensureMarkerStyle = (doc) => {
      if (doc.getElementById('webpatch-comment-style')) {
        return;
      }
      const style = doc.createElement('style');
      style.id = 'webpatch-comment-style';
      style.textContent = `
        .webpatch-comment-target { outline: 2px solid rgba(37, 99, 235, .55) !important; outline-offset: 2px !important; }
        .webpatch-comment-target-active { outline: 3px solid rgba(249, 115, 22, .95) !important; outline-offset: 3px !important; box-shadow: 0 0 0 6px rgba(249, 115, 22, .16) !important; }
        .webpatch-comment-hover { outline: 2px solid rgba(37, 99, 235, .9) !important; outline-offset: 2px !important; box-shadow: inset 0 0 0 9999px rgba(37, 99, 235, .08) !important; }
        .webpatch-comment-mode, .webpatch-comment-mode * { cursor: crosshair !important; }
        #webpatch-comment-marker-layer { display: none !important; }
      `;
      (doc.head || doc.documentElement).append(style);
    };

    const setImportantStyles = (element, styles) => {
      Object.entries(styles).forEach(([property, value]) => {
        element.style.setProperty(property, value, 'important');
      });
    };

    const ensureHoverBox = (doc) => {
      let box = doc.getElementById('webpatch-hover-box');
      if (box) {
        return box;
      }
      box = doc.createElement('webpatch-hover-box');
      box.id = 'webpatch-hover-box';
      box.setAttribute('aria-hidden', 'true');
      setImportantStyles(box, {
        'background': 'rgba(37, 99, 235, .08)',
        'border': '2px solid rgba(37, 99, 235, .9)',
        'box-sizing': 'border-box',
        'display': 'none',
        'left': '0',
        'margin': '0',
        'min-height': '0',
        'min-width': '0',
        'pointer-events': 'none',
        'position': 'fixed',
        'top': '0',
        'transform': 'translate3d(0, 0, 0)',
        'z-index': '2147483646'
      });
      (doc.body || doc.documentElement).append(box);
      return box;
    };

    const hideHoverBox = (doc) => {
      if (hoverTarget) {
        hoverTarget.classList.remove('webpatch-comment-hover');
      }
      hoverTarget = null;
      const box = doc && doc.getElementById('webpatch-hover-box');
      if (box) {
        box.style.setProperty('display', 'none', 'important');
      }
    };

    const updateHoverBox = (target) => {
      if (!commentMode || !target || !target.ownerDocument || target.closest('[data-webpatch-comment-marker]')) {
        return;
      }
      target = commentTargetElement(target);
      const doc = target.ownerDocument;
      const rect = target.getBoundingClientRect();
      if (rect.width <= 0 || rect.height <= 0) {
        hideHoverBox(doc);
        return;
      }
      if (hoverTarget && hoverTarget !== target) {
        hoverTarget.classList.remove('webpatch-comment-hover');
      }
      hoverTarget = target;
      target.classList.add('webpatch-comment-hover');
      const box = doc.getElementById('webpatch-hover-box');
      if (box) {
        box.style.setProperty('display', 'none', 'important');
      }
    };

    const markerLayer = (doc) => {
      let layer = doc.getElementById('webpatch-comment-marker-layer');
      if (layer) {
        return layer;
      }
      layer = doc.createElement('webpatch-comment-marker-layer');
      layer.id = 'webpatch-comment-marker-layer';
      layer.setAttribute('aria-hidden', 'true');
      setImportantStyles(layer, {
        'position': 'fixed',
        'inset': '0',
        'overflow': 'visible',
        'pointer-events': 'none',
        'z-index': '2147483646'
      });
      (doc.body || doc.documentElement).append(layer);
      return layer;
    };

    const markerOffset = (slotIndex, slotTotal) => (slotIndex - (slotTotal - 1) / 2) * 28;

    const ensureMarkerHost = (target) => {
      const host = target;
      if (!host || host.hasAttribute('data-webpatch-marker-host')) {
        return host;
      }
      const view = host.ownerDocument.defaultView || window;
      const position = view.getComputedStyle(host).position;
      if (position === 'static') {
        host.setAttribute('data-webpatch-original-position', host.style.position || '__missing__');
        host.style.setProperty('position', 'relative', 'important');
      }
      host.setAttribute('data-webpatch-marker-host', 'true');
      return host;
    };

    const placeMarker = (marker, target, slotIndex = 0, slotTotal = 1) => {
      const offsetX = markerOffset(slotIndex, slotTotal);
      marker.style.setProperty('left', `calc(50% + ${offsetX}px)`, 'important');
      marker.style.setProperty('top', '0', 'important');
    };

    const appendActiveTargetBox = (layer, target) => {
      const rect = target.getBoundingClientRect();
      if (rect.width <= 0 || rect.height <= 0) {
        return;
      }
      const view = target.ownerDocument.defaultView || window;
      const box = target.ownerDocument.createElement('webpatch-comment-active-box');
      box.setAttribute('aria-hidden', 'true');
      setImportantStyles(box, {
        'position': 'absolute',
        'left': `${view.scrollX + rect.left - 3}px`,
        'top': `${view.scrollY + rect.top - 3}px`,
        'width': `${rect.width + 6}px`,
        'height': `${rect.height + 6}px`,
        'border': '2px solid rgba(249, 115, 22, .95)',
        'border-radius': '6px',
        'box-shadow': '0 0 0 4px rgba(249, 115, 22, .18)',
        'box-sizing': 'border-box',
        'display': 'block',
        'margin': '0',
        'overflow': 'visible',
        'padding': '0',
        'pointer-events': 'none',
        'z-index': '2147483645'
      });
      layer.append(box);
    };

    const applyMarkerStyle = (marker) => {
      const styles = {
        'align-items': 'center',
        'background': '#2563eb',
        'border': '2px solid #fff',
        'border-radius': '999px',
        'box-shadow': '0 8px 24px rgba(18,25,38,.18)',
        'box-sizing': 'border-box',
        'color': '#fff',
        'cursor': 'pointer',
        'display': 'inline-flex',
        'font': '600 12px -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
        'font-size': '12px',
        'font-weight': '600',
        'font-family': '-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
        'font-style': 'normal',
        'font-variant': 'normal',
        'height': '24px',
        'justify-content': 'center',
        'left': '50%',
        'line-height': '1',
        'margin': '0',
        'mix-blend-mode': 'normal',
        'min-height': '0',
        'min-width': '0',
        'opacity': '1',
        'overflow': 'visible',
        'padding': '0',
        'pointer-events': 'auto',
        'position': 'absolute',
        'text-align': 'center',
        'text-decoration': 'none',
        'text-indent': '0',
        'text-shadow': 'none',
        'text-transform': 'none',
        'top': '0',
        'transform': 'translate(-50%, 0)',
        'visibility': 'visible',
        '-webkit-background-clip': 'border-box',
        'background-clip': 'border-box',
        '-webkit-text-fill-color': '#fff',
        'width': '24px',
        'z-index': '2147483647'
      };
      Object.entries(styles).forEach(([property, value]) => {
        marker.style.setProperty(property, value, 'important');
      });
    };

    const renderMarkers = () => {
      let doc;
      try {
        doc = iframe.contentDocument;
      } catch (error) {
        return;
      }
      if (!doc || !doc.body) {
        return;
      }
      clearMarkers(doc);
      ensureMarkerStyle(doc);
      const markerSelectorTotals = new Map();
      const markerSelectorOffsets = new Map();
      threads.forEach((thread) => {
        if (thread.is_resolved || !isCurrentFileThread(thread) || !threadMatchesCurrentViewport(thread) || !thread.selector) {
          return;
        }
        markerSelectorTotals.set(thread.selector, (markerSelectorTotals.get(thread.selector) || 0) + 1);
      });

      threads.forEach((thread, index) => {
        if (thread.is_resolved) {
          return;
        }
        if (!isCurrentFileThread(thread)) {
          return;
        }
        if (!threadMatchesCurrentViewport(thread)) {
          return;
        }
        let target = null;
        try {
          target = doc.querySelector(thread.selector);
        } catch (error) {
          target = null;
        }
        if (!target) {
          return;
        }
        const markerTarget = commentTargetElement(target);
        markerTarget.classList.add('webpatch-comment-target');
        markerTarget.classList.toggle('webpatch-comment-target-active', Number(thread.id) === Number(selectedThreadId));
        markerTarget.setAttribute('data-webpatch-comment-target', String(thread.id));
        const markerHost = ensureMarkerHost(markerTarget);
        if (!markerHost) {
          return;
        }
        const marker = doc.createElement('webpatch-comment-pin');
        marker.setAttribute('role', 'button');
        marker.setAttribute('tabindex', '0');
        const markerNumber = threadNumber(thread);
        marker.setAttribute('aria-label', `コメント ${markerNumber}`);
        marker.setAttribute('data-webpatch-comment-marker', String(thread.id));
        marker.contentEditable = 'false';
        applyMarkerStyle(marker);
        if (Number(thread.id) === Number(selectedThreadId)) {
          marker.style.setProperty('background', '#f97316', 'important');
          marker.style.setProperty('border-color', '#fff', 'important');
          marker.style.setProperty('box-shadow', '0 0 0 4px rgba(249, 115, 22, .22), 0 8px 24px rgba(18, 25, 38, .18)', 'important');
        } else if (thread.is_confirmation_pending) {
          marker.style.setProperty('background', '#16a34a', 'important');
          marker.style.setProperty('border-color', '#fff', 'important');
          marker.style.setProperty('box-shadow', '0 0 0 4px rgba(22, 163, 74, .22), 0 8px 24px rgba(18, 25, 38, .18)', 'important');
        }
        const markerLabel = doc.createElement('span');
        markerLabel.textContent = String(markerNumber);
        const labelStyles = {
          'background': 'transparent',
          'color': '#fff',
          'align-items': 'center',
          'display': 'inline-flex',
          'font': '600 12px -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
          'font-size': '12px',
          'font-style': 'normal',
          'font-family': '-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
          'font-variant': 'normal',
          'font-weight': '600',
          'height': '100%',
          'justify-content': 'center',
          'letter-spacing': '0',
          'line-height': '1',
          'margin': '0',
          'mix-blend-mode': 'normal',
          'opacity': '1',
          'overflow': 'visible',
          'padding': '0',
          'position': 'static',
          'text-align': 'center',
          'text-decoration': 'none',
          'text-indent': '0',
          'text-shadow': 'none',
          'text-transform': 'none',
          'transform': 'none',
          'visibility': 'visible',
          '-webkit-background-clip': 'border-box',
          'background-clip': 'border-box',
          '-webkit-text-fill-color': '#fff',
          'width': '100%'
        };
        Object.entries(labelStyles).forEach(([property, value]) => {
          markerLabel.style.setProperty(property, value, 'important');
        });
        marker.append(markerLabel);
        marker.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          const nextThread = threadById(thread.id);
          if (nextThread) {
            focusCommentListItem(nextThread);
            renderModal(nextThread);
          }
        });
        marker.addEventListener('keydown', (event) => {
          if (event.key !== 'Enter' && event.key !== ' ') {
            return;
          }
          event.preventDefault();
          const nextThread = threadById(thread.id);
          if (nextThread) {
            focusCommentListItem(nextThread);
            renderModal(nextThread);
          }
        });
        const selectorSlot = markerSelectorOffsets.get(thread.selector) || 0;
        markerSelectorOffsets.set(thread.selector, selectorSlot + 1);
        markerHost.append(marker);
        placeMarker(marker, markerTarget, selectorSlot, markerSelectorTotals.get(thread.selector) || 1);
      });
    };

    const scheduleMarkerRender = (doc = null) => {
      if (!commentMode) {
        return;
      }
      [0, 120, 320, 700, 1200, 2200].forEach((delay) => {
        window.setTimeout(() => {
          if (commentMode) {
            renderMarkers();
          }
        }, delay);
      });
      if (doc && doc.fonts && doc.fonts.ready) {
        doc.fonts.ready.then(() => {
          if (commentMode) {
            renderMarkers();
          }
        }).catch(() => {});
      }
      if (doc) {
        doc.querySelectorAll('img').forEach((image) => {
          if (!image.complete) {
            image.addEventListener('load', () => commentMode && renderMarkers(), { once: true });
            image.addEventListener('error', () => commentMode && renderMarkers(), { once: true });
          }
        });
        doc.querySelectorAll('video').forEach((video) => {
          video.addEventListener('loadedmetadata', () => commentMode && renderMarkers(), { once: true });
        });
      }
    };

    window.addEventListener('webpatch:preview-mode-change', () => {
      renderList();
      scheduleMarkerRender();
    });

    const loadComments = async () => {
      const params = new URLSearchParams({ id: String(projectId), file: activeFile });
      const response = await fetch(`<?= h(base_url('comments.php')) ?>?${params.toString()}`, {
        headers: { 'Accept': 'application/json' }
      });
      const result = await response.json();
      if (!response.ok || !result.ok) {
        throw new Error(result.message || 'コメントを読み込めませんでした。');
      }
      threads = (result.threads || []).filter(isCurrentFileThread);
      renderList();
      if (commentMode) {
        scheduleMarkerRender();
      }
      if (activeThreadId) {
        const nextThread = threadById(activeThreadId);
        if (nextThread) {
          focusCommentListItem(nextThread);
          renderModal(nextThread);
        }
      }
      if (pendingFocusThreadId) {
        const nextThread = threadById(pendingFocusThreadId);
        if (nextThread) {
          window.webpatchSetCommentMode(true);
          if (scrollToThread(nextThread)) {
            pendingFocusThreadId = null;
          }
          focusCommentListItem(nextThread);
        } else {
          pendingFocusThreadId = null;
        }
      }
    };

    const parseAiCheckResponse = async (response) => {
      const text = await response.text();
      let result;
      try {
        result = JSON.parse(text);
      } catch (error) {
        throw new Error('AI確認APIがJSON以外のレスポンスを返しました。ページを再読み込みしてから再度お試しください。');
      }
      if (!response.ok || !result.ok) {
        throw new Error(result.message || 'AI確認に失敗しました。');
      }
      return result;
    };

    const aiCheckDetailText = (result) => {
      const counts = result.counts || {};
      return [
        `確認 ${result.checked || result.processed || 0}/${result.total || 0}件`,
        `対象外 ${counts.not_applicable || 0}件`,
        `反映済み ${counts.reflected || 0}件`,
        `未反映 ${counts.not_reflected || 0}件`,
        `不明 ${counts.uncertain || 0}件`,
        `エラー ${counts.error || 0}件`
      ].join(' / ');
    };

    const pollAiCheck = async (jobId, previousText) => {
      if (!aiCheckButton || !aiCheckPolling) {
        return;
      }
      const params = new URLSearchParams({
        job_id: jobId,
        project_id: projectId,
        batch: '1'
      });
      const response = await fetch(`<?= h(base_url('ai-check-comments.php')) ?>?${params.toString()}`, {
        headers: { 'Accept': 'application/json' }
      });
      const result = await parseAiCheckResponse(response);
      aiCheckButton.textContent = `確認中 ${result.processed || 0}/${result.total || 0}`;

      if (result.done) {
        aiCheckPolling = false;
        await loadComments();
        const counts = result.counts || {};
        const isError = result.status === 'error' || (counts.error || 0) > 0;
        showToast(`${result.message || 'AI確認を完了しました。'} ${aiCheckDetailText(result)}`, isError ? 'error' : 'success');
        aiCheckButton.disabled = false;
        aiCheckButton.textContent = previousText || 'AI確認';
        return;
      }

      window.setTimeout(() => {
        pollAiCheck(jobId, previousText).catch((error) => {
          aiCheckPolling = false;
          showToast(error.message || 'AI確認に失敗しました。', 'error');
          aiCheckButton.disabled = false;
          aiCheckButton.textContent = previousText || 'AI確認';
        });
      }, 1200);
    };

    const runAiCheck = async () => {
      if (!aiCheckButton) {
        return;
      }
      if (aiCheckPolling) {
        return;
      }
      const previousText = aiCheckButton.textContent;
      aiCheckButton.disabled = true;
      aiCheckButton.textContent = '開始中';
      aiCheckPolling = true;
      try {
        const response = await fetch('<?= h(base_url('ai-check-comments.php')) ?>', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({
            csrf_token: csrfToken,
            project_id: projectId
          })
        });
        const result = await parseAiCheckResponse(response);
        aiCheckButton.textContent = `確認中 ${result.processed || 0}/${result.total || 0}`;
        if (result.done) {
          aiCheckPolling = false;
          showToast(`${result.message || 'AI確認を完了しました。'} ${aiCheckDetailText(result)}`, 'success');
          aiCheckButton.disabled = false;
          aiCheckButton.textContent = previousText || 'AI確認';
          return;
        }
        showToast(result.message || 'AI確認を開始しました。', 'success');
        await pollAiCheck(result.job_id, previousText);
      } catch (error) {
        aiCheckPolling = false;
        showToast(error.message || 'AI確認に失敗しました。', 'error');
        aiCheckButton.disabled = false;
        aiCheckButton.textContent = previousText || 'AI確認';
      }
    };

    const postComment = async (payload) => {
      const targetFile = currentPreviewFile();
      if (!targetFile) {
        throw new Error('現在の表示ページを特定できないため、コメントを保存できません。ページを再読み込みしてください。');
      }
      const imageInput = replyForm.querySelector('[data-comment-images]');
      const imageFiles = imageInput ? Array.from(imageInput.files || []) : [];
      const hasImages = imageFiles.length > 0;
      const requestOptions = {
        method: 'POST',
        headers: { 'Accept': 'application/json' }
      };

      if (hasImages) {
        const formData = new FormData();
        Object.entries({
          csrf_token: csrfToken,
          id: projectId,
          file: targetFile,
          viewport_mode: currentPreviewMode(),
          ...payload
        }).forEach(([key, value]) => formData.append(key, value == null ? '' : String(value)));
        imageFiles.forEach((file) => formData.append('images[]', file));
        requestOptions.body = formData;
      } else {
        requestOptions.headers['Content-Type'] = 'application/json';
        requestOptions.body = JSON.stringify({
          csrf_token: csrfToken,
          id: projectId,
          file: targetFile,
          viewport_mode: currentPreviewMode(),
          ...payload
        });
      }

      const response = await fetch('<?= h(base_url('comments.php')) ?>', requestOptions);
      const result = await response.json();
      if (!response.ok || !result.ok) {
        throw new Error(result.message || 'コメントを保存できませんでした。');
      }
      threads = (result.threads || []).filter(isCurrentFileThread);
      renderList();
      if (commentMode) {
        renderMarkers();
      }
      if (imageInput) {
        imageInput.value = '';
      }
      return result;
    };

    const openDeleteModal = (comment) => {
      if (!comment || !comment.id) {
        return;
      }
      pendingDeleteComment = comment;
      if (!deleteModal) {
        performDeleteComment(comment);
        return;
      }
      deleteModal.hidden = false;
      deleteConfirmButton && deleteConfirmButton.focus();
    };

    const closeDeleteModal = () => {
      if (deleteModal) {
        deleteModal.hidden = true;
      }
      pendingDeleteComment = null;
    };

    const performDeleteComment = async (comment) => {
      try {
        const response = await fetch('<?= h(base_url('comments.php')) ?>', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            csrf_token: csrfToken,
            id: projectId,
            file: activeFile,
            action: 'delete',
            comment_id: comment.id
          })
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
          throw new Error(result.message || 'コメントを削除できませんでした。');
        }
        const currentThreadId = result.focus_id || activeThreadId;
        if (result.focus_id) {
          activeThreadId = result.focus_id;
          selectedThreadId = result.focus_id;
        }
        threads = (result.threads || []).filter(isCurrentFileThread);
        if (selectedThreadId && !threadById(selectedThreadId)) {
          selectedThreadId = null;
        }
        renderList();
        if (commentMode) {
          renderMarkers();
        } else {
          let doc;
          try {
            doc = iframe.contentDocument;
          } catch (error) {
            doc = null;
          }
          if (doc) {
            clearMarkers(doc);
          }
        }
        if (currentThreadId) {
          const nextThread = threadById(currentThreadId);
          if (nextThread) {
            renderModal(nextThread);
          } else {
            closeModal();
          }
        }
        showToast('コメントを削除しました。', 'success');
      } catch (error) {
        showToast(error.message || 'コメントを削除できませんでした。', 'error');
      }
    };

    const toggleResolved = async () => {
      if (!activeThreadId || !resolveButton) {
        return;
      }
      resolveButton.disabled = true;
      try {
        const response = await fetch('<?= h(base_url('comments.php')) ?>', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            csrf_token: csrfToken,
            id: projectId,
            file: activeFile,
            action: 'resolve',
            comment_id: activeThreadId
          })
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
          throw new Error(result.message || 'コメント状態を更新できませんでした。');
        }
        threads = (result.threads || []).filter(isCurrentFileThread);
        renderList();
        if (commentMode) {
          renderMarkers();
        }
        const nextThread = threadById(activeThreadId);
        if (nextThread) {
          focusCommentListItem(nextThread);
          renderModal(nextThread);
        }
        showToast(result.resolved ? 'コメントを解決済みにしました。' : '解決済みを解除しました。', 'success');
      } catch (error) {
        showToast(error.message || 'コメント状態を更新できませんでした。', 'error');
      } finally {
        resolveButton.disabled = false;
      }
    };

    const toggleConfirmationPending = async () => {
      if (!activeThreadId || !pendingButton) {
        return;
      }
      pendingButton.disabled = true;
      try {
        const response = await fetch('<?= h(base_url('comments.php')) ?>', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            csrf_token: csrfToken,
            id: projectId,
            file: activeFile,
            action: 'confirmation_pending',
            comment_id: activeThreadId
          })
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
          throw new Error(result.message || '確認待ち状態を更新できませんでした。');
        }
        threads = (result.threads || []).filter(isCurrentFileThread);
        renderList();
        if (commentMode) {
          renderMarkers();
        }
        const nextThread = threadById(activeThreadId);
        if (nextThread) {
          focusCommentListItem(nextThread);
          renderModal(nextThread);
        }
        showToast(result.confirmation_pending ? '確認待ちにしました。' : '確認待ちを解除しました。', 'success');
      } catch (error) {
        showToast(error.message || '確認待ち状態を更新できませんでした。', 'error');
      } finally {
        pendingButton.disabled = false;
      }
    };

    const deleteComment = (comment) => {
      openDeleteModal(comment);
    };

    replyForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const body = replyForm.elements.body.value.trim();
      if (!activeThreadId && !draftSelector && !editCommentId) {
        return;
      }
      try {
        if (editCommentId) {
          const result = await postComment({ action: 'update', comment_id: editCommentId, file: editCommentFile, body });
          const nextThread = threadById(result.focus_id || editFocusThreadId || result.updated_id || editCommentId);
          if (nextThread) {
            focusCommentListItem(nextThread);
            renderModal(nextThread);
          } else {
            closeModal();
          }
          showToast('コメントを更新しました。', 'success');
          return;
        }
        const wasDraft = Boolean(draftSelector);
        const result = draftSelector
          ? await postComment({ selector: draftSelector, body })
          : await postComment({ parent_id: activeThreadId, body });
        const nextThread = threadById(result.created_id || activeThreadId);
        if (nextThread) {
          focusCommentListItem(nextThread);
          renderModal(nextThread);
        }
        showToast(wasDraft ? 'コメントを追加しました。' : '返信しました。', 'success');
      } catch (error) {
        showToast(error.message || 'コメントを保存できませんでした。', 'error');
      }
    });

    if (deleteConfirmButton) {
      deleteConfirmButton.addEventListener('click', async () => {
        if (!pendingDeleteComment) {
          closeDeleteModal();
          return;
        }
        const comment = pendingDeleteComment;
        deleteConfirmButton.disabled = true;
        try {
          await performDeleteComment(comment);
          closeDeleteModal();
        } finally {
          deleteConfirmButton.disabled = false;
        }
      });
    }
    deleteCancelButtons.forEach((button) => button.addEventListener('click', closeDeleteModal));
    if (deleteModal) {
      deleteModal.addEventListener('click', (event) => {
        if (event.target === deleteModal) {
          closeDeleteModal();
        }
      });
    }

    commentToggle.addEventListener('click', () => setCommentMode(!commentMode));
    if (resolveButton) {
      resolveButton.addEventListener('click', toggleResolved);
    }
    if (pendingButton) {
      pendingButton.addEventListener('click', toggleConfirmationPending);
    }
    document.querySelectorAll('.download-button').forEach((button) => {
      button.addEventListener('click', () => {
        if (commentMode) {
          setCommentMode(false);
        }
      });
    });
    closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        closeModal();
      }
    });
    document.addEventListener('keydown', (event) => {
      if (deleteModal && !deleteModal.hidden && event.key === 'Escape') {
        closeDeleteModal();
        return;
      }
      if (!modal.hidden && event.key === 'Escape') {
        closeModal();
      }
    });

    iframe.addEventListener('load', () => {
      let previewFile = '';
      try {
        const previewUrl = new URL(iframe.contentWindow.location.href);
        previewFile = previewUrl.searchParams.get('file') || '';
        if (!availableFiles.has(previewFile)) {
          previewFile = previewUrlToFile(previewUrl.href);
        }
      } catch (error) {
        previewFile = '';
      }
      if (previewFile && availableFiles.has(previewFile) && previewFile !== activeFile) {
        window.location.href = projectPageUrl(previewFile);
        return;
      }
      const doc = iframe.contentDocument;
      if (doc && doc.defaultView) {
        doc.documentElement.classList.toggle('webpatch-comment-mode', commentMode);
        renderList();
        if (commentMode) {
          scheduleMarkerRender(doc);
        } else {
          clearMarkers(doc);
          hideHoverBox(doc);
        }
        if (pendingFocusThreadId) {
          const nextThread = threadById(pendingFocusThreadId);
          if (nextThread && isCurrentFileThread(nextThread) && scrollToThread(nextThread)) {
            pendingFocusThreadId = null;
            focusCommentListItem(nextThread);
          }
        }
        doc.addEventListener('click', (event) => {
          if (event.target.closest('[data-webpatch-comment-marker]') || event.target.closest('#webpatch-comment-marker-layer')) {
            return;
          }
          if (!commentMode) {
            return;
          }
          event.preventDefault();
          event.stopPropagation();
          const target = event.target.closest('body *');
          if (!target) {
            return;
          }
          openCreateModal(selectorForElement(commentTargetElement(target), doc));
        }, true);
        doc.addEventListener('mousemove', (event) => {
          if (!commentMode) {
            hideHoverBox(doc);
            return;
          }
          if (event.target.closest('[data-webpatch-comment-marker]') || event.target.closest('#webpatch-comment-marker-layer')) {
            hideHoverBox(doc);
            return;
          }
          const target = event.target.closest('body *');
          if (target) {
            updateHoverBox(commentTargetElement(target));
          }
        }, true);
        doc.addEventListener('mouseleave', () => hideHoverBox(doc), true);
        doc.defaultView.addEventListener('resize', () => {
          renderMarkers();
          if (hoverTarget) {
            updateHoverBox(hoverTarget);
          }
        });
        doc.defaultView.addEventListener('scroll', () => {
          renderMarkers();
          if (hoverTarget) {
            updateHoverBox(hoverTarget);
          }
        }, true);
        const ResizeObserverCtor = doc.defaultView.ResizeObserver || window.ResizeObserver;
        if (ResizeObserverCtor) {
          let markerResizeFrame = 0;
          const markerResizeObserver = new ResizeObserverCtor(() => {
            if (markerResizeFrame) {
              window.cancelAnimationFrame(markerResizeFrame);
            }
            markerResizeFrame = window.requestAnimationFrame(() => {
              scheduleMarkerRender(doc);
            });
          });
          markerResizeObserver.observe(doc.documentElement);
          markerResizeObserver.observe(doc.body);
        }
      }
    });
    if (aiCheckButton) {
      aiCheckButton.addEventListener('click', runAiCheck);
    }
    loadComments().catch((error) => showToast(error.message || 'コメントを読み込めませんでした。', 'error'));
  })();

  (() => {
    const toggle = document.querySelector('[data-project-settings-toggle]');
    const modal = document.querySelector('[data-project-settings-modal]');
    const form = document.querySelector('[data-project-settings-form]');
    const textarea = document.querySelector('[data-project-copy-prompt]');
    const gitRepositoryUrl = document.querySelector('[data-project-git-repository-url]');
    const gitBranchName = document.querySelector('[data-project-git-branch-name]');
    const gitTestButton = document.querySelector('[data-project-git-test]');
    const gitTestResult = document.querySelector('[data-project-git-test-result]');
    const closeButtons = Array.from(document.querySelectorAll('[data-project-settings-close]'));
    const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const projectId = <?= json_encode($projectPublicRef, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

    if (!toggle || !modal || !form || !textarea) {
      return;
    }

    const showToast = (message, type = 'success') => {
      if (window.webpatchShowToast) {
        window.webpatchShowToast(message, type);
      }
    };

    const openModal = () => {
      modal.hidden = false;
      requestAnimationFrame(() => textarea.focus());
    };

    const closeModal = () => {
      modal.hidden = true;
    };

    toggle.addEventListener('click', openModal);
    closeButtons.forEach((button) => button.addEventListener('click', closeModal));
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

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const submitButton = form.querySelector('button[type="submit"]');
      if (submitButton) {
        submitButton.disabled = true;
      }
      try {
        const response = await fetch('<?= h(base_url('save-project-settings.php')) ?>', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            csrf_token: csrfToken,
            project_id: projectId,
            copy_prompt: textarea.value,
            git_repository_url: gitRepositoryUrl ? gitRepositoryUrl.value : '',
            git_branch_name: gitBranchName ? gitBranchName.value : ''
          })
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
          throw new Error(result.message || '保存できませんでした。');
        }
        window.webpatchCopyPromptAddon = result.copy_prompt || '';
        closeModal();
        showToast('コピー設定を保存しました。', 'success');
      } catch (error) {
        showToast(error.message || '保存できませんでした。', 'error');
      } finally {
        if (submitButton) {
          submitButton.disabled = false;
        }
      }
    });

    if (gitTestButton && gitTestResult) {
      const setGitTestResult = (message, state = '') => {
        gitTestResult.textContent = message || '';
        gitTestResult.dataset.state = state;
      };
      gitTestButton.addEventListener('click', async () => {
        const previousText = gitTestButton.textContent;
        gitTestButton.disabled = true;
        gitTestButton.textContent = '確認中';
        setGitTestResult('GitHub接続を確認しています...', 'pending');
        try {
          const response = await fetch('<?= h(base_url('git-test-connection.php')) ?>', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json'
            },
            body: JSON.stringify({
              csrf_token: csrfToken,
              repository_url: gitRepositoryUrl ? gitRepositoryUrl.value : '',
              branch_name: gitBranchName ? gitBranchName.value : ''
            })
          });
          const result = await response.json();
          if (!response.ok || !result.ok) {
            throw new Error(result.message || 'GitHub接続確認に失敗しました。');
          }
          setGitTestResult(`${result.message} ${result.repository || ''} ${result.branch ? `(${result.branch})` : ''}`, 'success');
        } catch (error) {
          setGitTestResult(error.message || 'GitHub接続確認に失敗しました。', 'error');
        } finally {
          gitTestButton.disabled = false;
          gitTestButton.textContent = previousText || 'GitHub接続確認';
        }
      });
    }
  })();

  <?php if ($canManageProject): ?>
  (() => {
    const shareToggle = document.querySelector('[data-share-toggle]');
    const modal = document.querySelector('[data-share-modal]');
    const closeButton = document.querySelector('[data-share-modal-close]');
    const form = document.querySelector('[data-share-form]');
    const resultBox = document.querySelector('[data-share-result]');
    const publicLinkInput = document.querySelector('[data-public-link-url]');
    const publicLinkButtons = Array.from(document.querySelectorAll('[data-public-link-action]'));
    const publicLinkCopy = document.querySelector('[data-public-link-copy]');
    const roleSelects = Array.from(document.querySelectorAll('[data-share-role-user]'));

    if (!shareToggle || !modal || !form) {
      return;
    }

    const showToast = (message, type = 'success') => {
      if (window.webpatchShowToast) {
        window.webpatchShowToast(message, type);
      }
    };

    const setResult = (message, type = '', inviteUrl = '') => {
      if (!resultBox) {
        return;
      }
      resultBox.replaceChildren();
      resultBox.dataset.state = type;
      if (!message && !inviteUrl) {
        return;
      }
      if (message) {
        const text = document.createElement('p');
        text.textContent = message;
        resultBox.append(text);
      }
      if (inviteUrl) {
        const link = document.createElement('a');
        link.href = inviteUrl;
        link.textContent = inviteUrl;
        link.target = '_blank';
        link.rel = 'noreferrer';
        resultBox.append(link);
      }
    };

    const openModal = () => {
      modal.hidden = false;
      setResult('');
      const input = form.querySelector('input[name="email"]');
      if (input) {
        input.focus();
      }
    };

    const closeModal = () => {
      modal.hidden = true;
      form.reset();
      setResult('');
    };

    shareToggle.addEventListener('click', openModal);
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

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const submitButton = form.querySelector('button[type="submit"]');
      if (submitButton) {
        submitButton.disabled = true;
      }
      setResult('共有処理中...', '');

      try {
        const response = await fetch(form.action, {
          method: 'POST',
          headers: { 'Accept': 'application/json' },
          body: new FormData(form)
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
          throw new Error(result.message || '共有できませんでした。');
        }
        form.reset();
        setResult(result.message || '共有しました。', 'success', result.invite_url || '');
        showToast(result.message || '共有しました。', 'success');
      } catch (error) {
        setResult(error.message || '共有できませんでした。', 'error');
        showToast(error.message || '共有できませんでした。', 'error');
      } finally {
        if (submitButton) {
          submitButton.disabled = false;
        }
      }
    });

    roleSelects.forEach((select) => {
      select.addEventListener('change', async () => {
        const previous = select.dataset.previousValue || 'comment';
        const body = new FormData();
        body.set('csrf_token', <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
        body.set('project_id', <?= json_encode($projectPublicRef, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
        body.set('action', 'role');
        body.set('user_id', select.dataset.shareRoleUser || '');
        body.set('role', select.value);
        select.disabled = true;
        try {
          const response = await fetch('<?= h(base_url('share-project.php')) ?>', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body
          });
          const result = await response.json();
          if (!response.ok || !result.ok) {
            throw new Error(result.message || '権限を更新できませんでした。');
          }
          select.dataset.previousValue = select.value;
          showToast(result.message || '権限を更新しました。', 'success');
        } catch (error) {
          select.value = previous;
          showToast(error.message || '権限を更新できませんでした。', 'error');
        } finally {
          select.disabled = false;
        }
      });
      select.dataset.previousValue = select.value;
    });

    publicLinkButtons.forEach((button) => {
      button.addEventListener('click', async () => {
        const body = new FormData();
        body.set('csrf_token', <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
        body.set('project_id', <?= json_encode($projectPublicRef, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
        body.set('action', button.dataset.publicLinkAction || 'enable');
        button.disabled = true;
        try {
          const response = await fetch('<?= h(base_url('public-link.php')) ?>', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body
          });
          const result = await response.json();
          if (!response.ok || !result.ok) {
            throw new Error(result.message || '公開コメントリンクを更新できませんでした。');
          }
          if (publicLinkInput) {
            publicLinkInput.value = result.url || '';
          }
          setResult(result.message || '公開コメントリンクを更新しました。', 'success', result.url || '');
          showToast(result.message || '公開コメントリンクを更新しました。', 'success');
        } catch (error) {
          setResult(error.message || '公開コメントリンクを更新できませんでした。', 'error');
          showToast(error.message || '公開コメントリンクを更新できませんでした。', 'error');
        } finally {
          button.disabled = false;
        }
      });
    });

    if (publicLinkCopy) {
      publicLinkCopy.addEventListener('click', async () => {
        if (!publicLinkInput || publicLinkInput.value === '') {
          setResult('先に公開コメントリンクを有効化してください。', 'error');
          return;
        }
        try {
          await navigator.clipboard.writeText(publicLinkInput.value);
          showToast('公開コメントリンクをコピーしました。', 'success');
        } catch (error) {
          publicLinkInput.select();
          setResult('リンクを選択しました。コピーしてください。', 'success');
        }
      });
    }
  })();

  (() => {
    const iframe = document.querySelector('.site-preview');
    const editToggle = document.querySelector('[data-edit-toggle]');
    const saveButton = document.querySelector('[data-save-page]');
    const resetButton = document.querySelector('[data-reset-page]');
    const status = document.querySelector('[data-save-status]');
    const toast = document.querySelector('[data-action-toast]');
    const projectId = <?= json_encode($projectPublicRef, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const activeFile = <?= json_encode($activeFile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const previewUrl = <?= json_encode($previewUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    let editMode = false;
    let saving = false;

    if (!iframe || !editToggle || !saveButton) {
      return;
    }

    const setStatus = (message, type = '') => {
      if (status) {
        status.textContent = '';
        status.dataset.state = '';
      }
      if (message) {
        showToast(message, type || 'success');
      }
    };

    let toastTimer = null;
    const showToast = (message, type = 'success') => {
      if (!toast) {
        return;
      }
      window.clearTimeout(toastTimer);
      toast.textContent = message;
      toast.dataset.state = type;
      toast.classList.add('show');
      toastTimer = window.setTimeout(() => {
        toast.classList.remove('show');
      }, 3200);
    };

    const serializeDoctype = (doctype) => {
      if (!doctype) {
        return '<!doctype html>\n';
      }
      let output = `<!doctype ${doctype.name}`;
      if (doctype.publicId) {
        output += ` PUBLIC "${doctype.publicId}"`;
      }
      if (doctype.systemId) {
        output += doctype.publicId ? ` "${doctype.systemId}"` : ` SYSTEM "${doctype.systemId}"`;
      }
      return `${output}>\n`;
    };

    const htmlForSave = (doc) => {
      const clone = doc.documentElement.cloneNode(true);
      clone.classList.remove('webpatch-editing');
      clone.classList.remove('webpatch-comment-mode');

      const clonedBody = clone.querySelector('body');
      if (clonedBody) {
        clonedBody.removeAttribute('contenteditable');
        clonedBody.removeAttribute('spellcheck');
      }
      clone.querySelectorAll('[data-webpatch-comment-marker], #webpatch-comment-style').forEach((element) => element.remove());
      clone.querySelectorAll('[data-webpatch-comment-target]').forEach((element) => {
        if (element.hasAttribute('data-webpatch-original-position')) {
          const originalPosition = element.getAttribute('data-webpatch-original-position') || '';
          if (originalPosition === '__missing__') {
            element.style.removeProperty('position');
          } else {
            element.style.setProperty('position', originalPosition);
          }
          element.removeAttribute('data-webpatch-original-position');
        }
        element.removeAttribute('data-webpatch-comment-target');
        element.classList.remove('webpatch-comment-target');
      });

      const attrs = ['src', 'href', 'poster', 'srcset'];
      clone.querySelectorAll('[data-webpatch-original-src], [data-webpatch-original-href], [data-webpatch-original-poster], [data-webpatch-original-srcset]').forEach((element) => {
        attrs.forEach((attr) => {
          const originalAttr = `data-webpatch-original-${attr}`;
          if (element.hasAttribute(originalAttr)) {
            element.setAttribute(attr, element.getAttribute(originalAttr) || '');
            element.removeAttribute(originalAttr);
          }
        });
      });

      return serializeDoctype(doc.doctype) + clone.outerHTML;
    };

    const applyEditMode = () => {
      let doc;
      try {
        doc = iframe.contentDocument;
      } catch (error) {
        setStatus('このページは編集できません。', 'error');
        return;
      }
      if (!doc || !doc.body) {
        return;
      }

      doc.body.contentEditable = editMode ? 'true' : 'false';
      doc.body.spellcheck = false;
      doc.documentElement.classList.toggle('webpatch-editing', editMode);
      saveButton.disabled = !editMode;
      editToggle.classList.toggle('active', editMode);
      editToggle.setAttribute('aria-pressed', editMode ? 'true' : 'false');
      editToggle.dataset.mode = editMode ? 'edit' : 'view';
      const label = editToggle.querySelector('[data-mode-label]');
      if (label) {
        label.textContent = '文字編集';
      }
      setStatus(editMode ? '編集できます。リンクは無効です。' : '', '');
    };

    iframe.addEventListener('load', () => {
      let doc;
      try {
        doc = iframe.contentDocument;
      } catch (error) {
        setStatus('このページは編集できません。', 'error');
        return;
      }
      if (!doc) {
        return;
      }
      doc.addEventListener('click', (event) => {
        if (editMode && event.target.closest('a')) {
          event.preventDefault();
          event.stopPropagation();
        }
      }, true);
      doc.addEventListener('keydown', handleSaveShortcut);
      applyEditMode();
    });

    editToggle.addEventListener('click', () => {
      editMode = !editMode;
      applyEditMode();
    });

    const savePage = async () => {
      if (!editMode || saving) {
        return;
      }

      let doc;
      try {
        doc = iframe.contentDocument;
      } catch (error) {
        setStatus('保存できませんでした。', 'error');
        return;
      }
      if (!doc || !doc.body) {
        setStatus('保存できませんでした。', 'error');
        return;
      }

      saving = true;
      saveButton.disabled = true;
      setStatus('保存中...', '');

      try {
        const html = htmlForSave(doc);
        const response = await fetch('<?= h(base_url('save-text.php')) ?>', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            csrf_token: csrfToken,
            id: projectId,
            file: activeFile,
            html
          })
        });
        const result = await response.json();
        if (!response.ok || !result.ok) {
          throw new Error(result.message || '保存できませんでした。');
        }
        setStatus('保存しました。', 'success');
      } catch (error) {
        setStatus(error.message || '保存できませんでした。', 'error');
      } finally {
        saving = false;
        saveButton.disabled = !editMode;
      }
    };

    const handleSaveShortcut = (event) => {
      if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== 's') {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      savePage();
    };

    saveButton.addEventListener('click', savePage);
    document.addEventListener('keydown', handleSaveShortcut);

    if (resetButton) {
      resetButton.addEventListener('click', async () => {
        if (!window.confirm('このページを最初の状態に戻します。現在の編集内容は失われます。')) {
          return;
        }

        resetButton.disabled = true;
        saveButton.disabled = true;
        setStatus('リセット中...', '');

        try {
          const response = await fetch('<?= h(base_url('reset-page.php')) ?>', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json'
            },
            body: JSON.stringify({
              csrf_token: csrfToken,
              id: projectId,
              file: activeFile
            })
          });
          const result = await response.json();
          if (!response.ok || !result.ok) {
            throw new Error(result.message || 'リセットできませんでした。');
          }
          setStatus('最初の状態に戻しました。', 'success');
          iframe.src = `${previewUrl}&_=${Date.now()}`;
        } catch (error) {
          setStatus(error.message || 'リセットできませんでした。', 'error');
        } finally {
          resetButton.disabled = false;
          saveButton.disabled = !editMode;
        }
      });
    }
  })();
  <?php endif; ?>
</script>
<?php
$downloadUrl = base_url('download-project.php?id=' . rawurlencode($projectPublicRef));
$iconComment = '<svg class="header-button-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6.5 5.75h11A3.25 3.25 0 0 1 20.75 9v5.25a3.25 3.25 0 0 1-3.25 3.25h-4.28l-4.13 3.1a.85.85 0 0 1-1.36-.68V17.5H6.5a3.25 3.25 0 0 1-3.25-3.25V9A3.25 3.25 0 0 1 6.5 5.75Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
$iconShare = '<svg class="header-button-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 12.2h7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="6" cy="12.2" r="2.65" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="18" cy="6.2" r="2.65" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="18" cy="18.2" r="2.65" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m8.36 10.95 7.25-3.62M8.36 13.45l7.25 3.62" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
$iconEdit = '<svg class="header-button-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19h4.25L19.1 9.15a2.12 2.12 0 0 0 0-3L17.85 4.9a2.12 2.12 0 0 0-3 0L5 14.75V19Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="m13.75 6 4.25 4.25" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
$iconDownload = '<svg class="header-button-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.5v9.2m0 0 3.55-3.55M12 13.7l-3.55-3.55M5.5 17.8v.7a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-.7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$iconSave = '<svg class="header-button-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6.5 4.75h8.55L19.25 9v10.25H4.75V6.5A1.75 1.75 0 0 1 6.5 4.75Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8 4.75v5h7.25m-7.5 9v-5.1h8.5v5.1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
$iconSettings = '<svg class="header-button-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10.15 4.25h3.7l.62 2.05a6.7 6.7 0 0 1 1.25.72l2.12-.48 1.85 3.2-1.5 1.58c.04.23.06.46.06.68s-.02.45-.06.68l1.5 1.58-1.85 3.2-2.12-.48c-.39.29-.81.53-1.25.72l-.62 2.05h-3.7l-.62-2.05a6.7 6.7 0 0 1-1.25-.72l-2.12.48-1.85-3.2 1.5-1.58A4.3 4.3 0 0 1 5.75 12c0-.22.02-.45.06-.68l-1.5-1.58 1.85-3.2 2.12.48c.39-.29.81-.53 1.25-.72l.62-2.05Z" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.6" fill="none" stroke="currentColor" stroke-width="1.65"/></svg>';
$iconTrash = '<svg class="header-button-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 8.25v10M12 8.25v10M15.5 8.25v10M5.75 6.25h12.5M9.25 6.25l.55-2h4.4l.55 2M7 6.25l.75 14h8.5l.75-14" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$commentControl = '
  <button class="comment-mode-button" type="button" data-comment-toggle aria-pressed="false" title="コメント">
    ' . $iconComment . '
    <span>コメント</span>
  </button>
';
$shareControl = $canManageProject ? '
  <button class="member-share-button" type="button" data-share-toggle title="メンバー共有">
    ' . $iconShare . '
    <span>共有</span>
  </button>
' : '';
$settingsControl = '
  <button class="project-settings-button icon-only" type="button" data-project-settings-toggle aria-label="コピー設定" title="コピー設定">
    ' . $iconSettings . '
  </button>
';
$deletePageControl = $canDeletePage ? '
  <button class="page-delete-button icon-only" type="button" data-delete-page aria-label="このページを削除" title="このページを削除">
    ' . $iconTrash . '
  </button>
' : '';
$downloadControl = !$isUrlSource ? '<a class="download-button" href="' . h($downloadUrl) . '">' . $iconDownload . '<span>ダウンロード</span></a>' : '';
$editControls = $canEdit ? '
  ' . ($downloadControl !== '' ? '<span class="header-control-separator" aria-hidden="true"></span>' : '') . '
  <button class="mode-toggle" type="button" data-edit-toggle data-mode="view" aria-pressed="false">
    ' . $iconEdit . '
    <span class="mode-label" data-mode-label>文字編集</span>
  </button>
  <button class="save-button icon-only" type="button" data-save-page disabled aria-label="保存" title="保存">' . $iconSave . '</button>
' : '';
$headerControls = $commentControl . $shareControl . $settingsControl . $deletePageControl . $downloadControl . $editControls;
render_app_page($project['title'], ob_get_clean(), 'project-main', $headerControls);
