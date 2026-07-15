<?php

require __DIR__ . '/_app.php';

$token = (string) ($_GET['token'] ?? '');
$project = client_project_for_token($token);

header('X-Robots-Tag: noindex, nofollow, noarchive');

if ($project === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('クライアント共有リンクが無効です。');
}

$files = project_sidebar_html_files($project);
$fileTitles = project_file_display_titles($project, $files);
$fileCopyTargets = project_file_copy_targets($project, $files);
$pageCommentMarkerStates = page_comment_marker_states_for_project((int) $project['id'], (int) $project['client_share_id']);

$activeFile = (string) ($_GET['file'] ?? $project['entry_file']);
if (!in_array($activeFile, $files, true)) {
    $activeFile = $files[0] ?? (string) $project['entry_file'];
}
$activeFileTitle = $fileTitles[$activeFile] ?? (pathinfo($activeFile, PATHINFO_FILENAME) ?: $activeFile);
$previewUrl = base_url('public-preview?client_token=' . rawurlencode($token) . '&file=' . rawurlencode($activeFile));
?><!doctype html>
<html lang="ja">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?= h($project['title']) ?> | WebPatch クライアント共有</title>
    <link rel="icon" type="image/svg+xml" href="<?= h(base_url('favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= h(base_url('styles.css')) ?>">
  </head>
  <body>
    <div class="app-shell public-share-shell">
      <header class="app-header">
        <a class="brand" href="<?= h(base_url('login.php')) ?>" aria-label="WebPatch">
          <span class="brand-mark" aria-hidden="true">W</span>
          <span>WebPatch</span>
        </a>
        <div class="header-controls">
          <button class="comment-mode-button active" type="button" data-comment-toggle aria-pressed="true" title="コメント">
            <svg class="header-button-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6.5 5.75h11A3.25 3.25 0 0 1 20.75 9v5.25a3.25 3.25 0 0 1-3.25 3.25h-4.28l-4.13 3.1a.85.85 0 0 1-1.36-.68V17.5H6.5a3.25 3.25 0 0 1-3.25-3.25V9A3.25 3.25 0 0 1 6.5 5.75Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
            <span>コメント</span>
          </button>
          <span class="project-badge">クライアント共有</span>
        </div>
      </header>
      <main class="app-main project-main">
        <section class="project-layout">
          <section class="preview-panel">
            <div class="preview-stage" data-preview-stage data-viewport="desktop">
              <iframe class="site-preview" title="<?= h($project['title']) ?> プレビュー" sandbox="allow-same-origin allow-scripts allow-forms" src="<?= h($previewUrl) ?>"></iframe>
            </div>
          </section>
          <aside class="file-panel" aria-label="右サイドバー">
            <div class="file-panel-header">
              <div class="viewport-controls" aria-label="プレビュー幅">
                <button class="viewport-button active" type="button" data-preview-mode="desktop" aria-pressed="true" title="デスクトップ表示"><span class="device-icon desktop-icon" aria-hidden="true"></span><span class="sr-only">デスクトップ</span></button>
                <button class="viewport-button" type="button" data-preview-mode="tablet" aria-pressed="false" title="タブレット表示"><span class="device-icon tablet-icon" aria-hidden="true"></span><span class="sr-only">タブレット</span></button>
                <button class="viewport-button" type="button" data-preview-mode="mobile" aria-pressed="false" title="スマホ表示"><span class="device-icon mobile-icon" aria-hidden="true"></span><span class="sr-only">スマホ</span></button>
                <a class="viewport-button fullscreen-preview-button" href="<?= h($previewUrl) ?>" target="_blank" rel="noopener noreferrer" title="全画面表示" aria-label="サイトを新しいタブで全画面表示"><span class="fullscreen-icon" aria-hidden="true"></span><span class="sr-only">全画面表示</span></a>
              </div>
              <div class="sidebar-tabs" role="tablist" aria-label="右サイドバー表示">
                <button class="sidebar-tab active" type="button" role="tab" aria-selected="true" aria-controls="pages-tab" id="pages-tab-button" data-sidebar-tab="pages">ページ</button>
                <button class="sidebar-tab" type="button" role="tab" aria-selected="false" aria-controls="comments-tab" id="comments-tab-button" data-sidebar-tab="comments">コメント</button>
              </div>
            </div>
            <section class="sidebar-tab-panel active" id="pages-tab" role="tabpanel" aria-labelledby="pages-tab-button" data-sidebar-panel="pages">
              <div class="project-summary">
                <p class="eyebrow">Public Comment</p>
                <h1><?= h($project['title']) ?></h1>
                <div class="active-page-meta">
                  <strong><?= h($activeFileTitle) ?></strong>
                  <span><?= h($activeFile) ?></span>
                </div>
              </div>
              <div class="file-list">
                <?php foreach ($files as $file): ?>
                  <?php $pageMarker = $pageCommentMarkerStates[$file] ?? null; ?>
                  <a class="<?= $file === $activeFile ? 'active' : '' ?>" href="<?= h(base_url('client-project?token=' . rawurlencode($token) . '&file=' . rawurlencode($file))) ?>">
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
            </section>
            <section class="sidebar-tab-panel" id="comments-tab" role="tabpanel" aria-labelledby="comments-tab-button" data-sidebar-panel="comments" hidden>
              <div class="comment-panel">
                <div class="comment-panel-heading">
                  <h2>コメント</h2>
                </div>
                <div class="comment-list" data-comment-list aria-label="コメント一覧"></div>
              </div>
            </section>
          </aside>
        </section>
        <div class="comment-modal-backdrop" data-comment-modal hidden>
          <div class="comment-modal" role="dialog" aria-modal="true" aria-labelledby="comment-modal-title">
            <div class="comment-modal-header">
              <div>
                <p class="eyebrow">Comment</p>
                <h2 id="comment-modal-title">コメント</h2>
                <p data-comment-modal-selector></p>
              </div>
              <div class="comment-modal-actions">
                <button class="comment-resolve-button" type="button" data-comment-resolve-toggle hidden>解決済みにする</button>
                <button class="icon-button" type="button" data-comment-modal-close aria-label="閉じる">×</button>
              </div>
            </div>
            <div class="comment-thread" data-comment-thread></div>
            <form class="comment-reply-form" data-comment-reply-form>
              <div class="field">
                <label for="guest_name">表示名</label>
                <input id="guest_name" name="guest_name" type="text" value="ゲスト" maxlength="120" required>
              </div>
              <div class="field">
                <label id="comment_reply_label" for="comment_reply">コメント</label>
                <textarea id="comment_reply" name="body" rows="3" required></textarea>
              </div>
              <div class="field">
                <label for="comment_images">画像</label>
                <input id="comment_images" name="images[]" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple data-comment-images>
                <p class="field-hint">JPEG、PNG、GIF、WebPを最大8枚まで添付できます。</p>
              </div>
              <button class="primary-button" type="submit" data-comment-submit>コメントする</button>
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
        <div class="toast" data-action-toast role="status" aria-live="polite" aria-atomic="true"></div>
      </main>
    </div>
    <script>
      (() => {
        const filePanel = document.querySelector('.file-panel');
        if (!filePanel) {
          return;
        }

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

        scheduleActivePageScroll();
        window.addEventListener('webpatch:pages-tab-active', scheduleActivePageScroll);
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
        const storageKey = 'webpatch-client-preview-mode';

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
          buttons.forEach((item) => {
            const active = item.dataset.previewMode === nextMode;
            item.classList.toggle('active', active);
            item.setAttribute('aria-pressed', active ? 'true' : 'false');
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
        let timer = null;
        window.webpatchShowToast = (message, type = 'success') => {
          if (!toast || !message) return;
          window.clearTimeout(timer);
          toast.textContent = message;
          toast.dataset.state = type;
          toast.classList.add('show');
          timer = window.setTimeout(() => toast.classList.remove('show'), 3200);
        };
      })();

      (() => {
        const tabs = Array.from(document.querySelectorAll('[data-sidebar-tab]'));
        const panels = Array.from(document.querySelectorAll('[data-sidebar-panel]'));
        const setTab = (name) => {
          tabs.forEach((tab) => {
            const active = tab.dataset.sidebarTab === name;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
          });
          panels.forEach((panel) => {
            const active = panel.dataset.sidebarPanel === name;
            panel.classList.toggle('active', active);
            panel.hidden = !active;
          });
          if (name === 'pages') {
            window.dispatchEvent(new CustomEvent('webpatch:pages-tab-active'));
          }
          if (name === 'comments' && window.webpatchSetCommentMode) window.webpatchSetCommentMode(true);
        };
        window.webpatchSetSidebarTab = setTab;
        tabs.forEach((tab) => tab.addEventListener('click', () => setTab(tab.dataset.sidebarTab)));
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
        const deleteModal = document.querySelector('[data-comment-delete-modal]');
        const deleteConfirmButton = document.querySelector('[data-comment-delete-confirm]');
        const deleteCancelButtons = Array.from(document.querySelectorAll('[data-comment-delete-cancel]'));
        const token = <?= json_encode($token, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const activeFile = <?= json_encode($activeFile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const fileCopyTargets = <?= json_encode($fileCopyTargets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const availableFiles = new Set(<?= json_encode(array_values($files), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
        const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const commentImageBaseUrl = <?= json_encode(base_url('comment-image'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const requestedCommentId = Number(new URLSearchParams(window.location.search).get('comment') || 0);
        let threads = [];
        let activeThreadId = null;
        let selectedThreadId = requestedCommentId > 0 ? requestedCommentId : null;
        let pendingFocusThreadId = requestedCommentId > 0 ? requestedCommentId : null;
        let draftSelector = null;
        let editCommentId = null;
        let editCommentFile = activeFile;
        let pendingDeleteComment = null;
        let commentMode = true;
        let hoverTarget = null;
        const showToast = (message, type = 'success') => window.webpatchShowToast && window.webpatchShowToast(message, type);
        const guestKeyStorage = `webpatch-client-guest-key-${token}`;
        const randomGuestKey = () => {
          const bytes = new Uint8Array(24);
          if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
          }
          return `${Date.now().toString(36)}${Math.random().toString(36).slice(2)}${Math.random().toString(36).slice(2)}`;
        };
        const guestKey = (() => {
          try {
            const stored = localStorage.getItem(guestKeyStorage);
            if (stored) {
              return stored;
            }
            const next = randomGuestKey();
            localStorage.setItem(guestKeyStorage, next);
            return next;
          } catch (error) {
            return randomGuestKey();
          }
        })();
        const pathWithoutRoot = (file) => {
          const parts = String(file || '').split('/').filter(Boolean);
          return parts.length > 1 ? parts.slice(1).join('/') : parts.join('/');
        };
        const resolveThreadFile = (thread) => {
          const file = thread.file_path || activeFile;
          if (availableFiles.has(file)) return file;
          const comparable = pathWithoutRoot(file);
          if (!comparable) return '';
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
        const publicPageUrl = (file, commentId = '') => {
          const params = new URLSearchParams({ token, file });
          if (commentId) params.set('comment', String(commentId));
          return `<?= h(base_url('client-project')) ?>?${params.toString()}`;
        };
        const navigateToThreadPage = (thread) => {
          const file = resolveThreadFile(thread);
          if (!file) {
            showToast(`コメント ${threadNumberLabel(thread)} のページが現在のサイト内に見つかりません。`, 'error');
            return true;
          }
          if (file !== activeFile) {
            window.location.href = publicPageUrl(file, thread.id);
            return true;
          }
          return false;
        };
        const baseName = (file) => {
          const parts = String(file || '').split('/');
          return parts[parts.length - 1] || file;
        };
        const displayFileLabel = (file) => pathWithoutRoot(file) || baseName(file);
        const escapeCss = (value) => window.CSS && CSS.escape ? CSS.escape(value) : value.replace(/[^A-Za-z0-9_-]/g, '\\$&');
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
          if (element.id) return `#${escapeCss(element.id)}`;
          const parts = [];
          let node = element;
          while (node && node.nodeType === 1 && node !== doc.body && node !== doc.documentElement) {
            const tag = node.tagName.toLowerCase();
            const siblings = Array.from(node.parentElement ? node.parentElement.children : []);
            const sameTag = siblings.filter((sibling) => sibling.tagName.toLowerCase() === tag);
            let part = tag;
            const classes = Array.from(node.classList).filter((name) => !name.startsWith('webpatch-'));
            if (classes[0]) part += `.${escapeCss(classes[0])}`;
            if (sameTag.length > 1) part += `:nth-of-type(${sameTag.indexOf(node) + 1})`;
            parts.unshift(part);
            try { if (doc.querySelectorAll(parts.join(' > ')).length === 1) return parts.join(' > '); } catch (error) {}
            node = node.parentElement;
          }
          return parts.join(' > ') || 'body';
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
          const body = document.createElement('p');
          body.textContent = comment.body;
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
          item.append(meta, body);
          if (Array.isArray(comment.images) && comment.images.length > 0) {
            const gallery = document.createElement('div');
            gallery.className = 'comment-image-gallery';
            comment.images.forEach((image) => {
              const link = document.createElement('a');
              link.href = `${commentImageBaseUrl}?id=${encodeURIComponent(image.id)}&client_token=${encodeURIComponent(token)}`;
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
          draftSelector = null;
          editCommentId = null;
          editCommentFile = thread.file_path || activeFile;
          modalTitle.textContent = `コメント ${threadNumberLabel(thread)}`;
          modalSelector.textContent = `${thread.file_path || activeFile} / ${thread.selector || ''}`;
          threadBody.replaceChildren(buildMessage(thread, thread), ...thread.replies.map((reply) => buildMessage(reply, thread)));
          threadBody.classList.toggle('resolved', Boolean(thread.is_resolved));
          replyForm.reset();
          replyForm.elements.guest_name.value = localStorage.getItem('webpatch-client-guest-name') || 'ゲスト';
          replyLabel.textContent = '返信';
          submitButton.textContent = '返信する';
          if (resolveButton) {
            resolveButton.hidden = !thread.can_resolve;
            resolveButton.classList.toggle('resolved', Boolean(thread.is_resolved));
            resolveButton.textContent = thread.is_resolved ? '解決済みを解除' : '解決済みにする';
          }
          modal.hidden = false;
          requestAnimationFrame(() => {
            if (preserveScroll && previewWindow) previewWindow.scrollTo(previewScrollX, previewScrollY);
            if (preserveScroll) window.scrollTo(pageScrollX, pageScrollY);
            replyForm.elements.body.focus({ preventScroll: true });
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
          modalTitle.textContent = 'コメントを追加';
          modalSelector.textContent = selector;
          threadBody.replaceChildren();
          threadBody.classList.remove('resolved');
          replyForm.reset();
          replyForm.elements.guest_name.value = localStorage.getItem('webpatch-client-guest-name') || 'ゲスト';
          replyLabel.textContent = 'コメント';
          submitButton.textContent = 'コメントする';
          if (resolveButton) {
            resolveButton.hidden = true;
            resolveButton.classList.remove('resolved');
          }
          modal.hidden = false;
          requestAnimationFrame(() => {
            if (previewWindow) previewWindow.scrollTo(previewScrollX, previewScrollY);
            window.scrollTo(pageScrollX, pageScrollY);
            replyForm.elements.body.focus({ preventScroll: true });
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
          modalTitle.textContent = 'コメントを編集';
          modalSelector.textContent = `${editCommentFile} / ${(thread && thread.selector) || comment.selector || ''}`;
          threadBody.replaceChildren();
          threadBody.classList.remove('resolved');
          replyForm.reset();
          replyForm.elements.guest_name.value = localStorage.getItem('webpatch-client-guest-name') || 'ゲスト';
          replyForm.elements.body.value = comment.body || '';
          replyLabel.textContent = 'コメント';
          submitButton.textContent = '保存する';
          if (resolveButton) {
            resolveButton.hidden = true;
            resolveButton.classList.remove('resolved');
          }
          modal.hidden = false;
          requestAnimationFrame(() => {
            if (previewWindow) previewWindow.scrollTo(previewScrollX, previewScrollY);
            window.scrollTo(pageScrollX, pageScrollY);
            replyForm.elements.body.focus({ preventScroll: true });
          });
        };
        const closeModal = () => {
          modal.hidden = true;
          activeThreadId = null;
          draftSelector = null;
          editCommentId = null;
          editCommentFile = activeFile;
          if (resolveButton) {
            resolveButton.hidden = true;
            resolveButton.classList.remove('resolved');
            resolveButton.disabled = false;
          }
        };
        const clearMarkers = (doc) => {
          doc.querySelectorAll('[data-webpatch-comment-marker]').forEach((marker) => marker.remove());
          const layer = doc.getElementById('webpatch-comment-marker-layer');
          if (layer) {
            layer.remove();
          }
          doc.querySelectorAll('.webpatch-comment-hover').forEach((element) => element.classList.remove('webpatch-comment-hover'));
          doc.querySelectorAll('.webpatch-comment-focus').forEach((element) => element.classList.remove('webpatch-comment-focus'));
          doc.querySelectorAll('.webpatch-comment-target-active').forEach((element) => element.classList.remove('webpatch-comment-target-active'));
          doc.querySelectorAll('[data-webpatch-comment-target], [data-webpatch-marker-host]').forEach((element) => {
            if (element.hasAttribute('data-webpatch-original-position')) {
              const pos = element.getAttribute('data-webpatch-original-position') || '';
              pos === '__missing__' ? element.style.removeProperty('position') : element.style.setProperty('position', pos);
              element.removeAttribute('data-webpatch-original-position');
            }
            element.removeAttribute('data-webpatch-comment-target');
            element.removeAttribute('data-webpatch-marker-host');
            element.classList.remove('webpatch-comment-target');
          });
        };
        const ensureMarkerStyle = (doc) => {
          if (doc.getElementById('webpatch-comment-style')) return;
          const style = doc.createElement('style');
          style.id = 'webpatch-comment-style';
          style.textContent = '.webpatch-comment-target{outline:2px solid rgba(37,99,235,.55)!important;outline-offset:2px!important}.webpatch-comment-target-active{outline:3px solid rgba(249,115,22,.95)!important;outline-offset:3px!important;box-shadow:0 0 0 6px rgba(249,115,22,.16)!important}.webpatch-comment-hover{outline:2px solid rgba(37,99,235,.9)!important;outline-offset:2px!important;box-shadow:inset 0 0 0 9999px rgba(37,99,235,.08)!important}.webpatch-comment-mode,.webpatch-comment-mode *{cursor:crosshair!important}#webpatch-comment-marker-layer{display:none!important}';
          (doc.head || doc.documentElement).append(style);
        };
        const setImportant = (element, styles) => Object.entries(styles).forEach(([key, value]) => element.style.setProperty(key, value, 'important'));
        const ensureHoverBox = (doc) => {
          let box = doc.getElementById('webpatch-hover-box');
          if (box) return box;
          box = doc.createElement('webpatch-hover-box');
          box.id = 'webpatch-hover-box';
          box.setAttribute('aria-hidden', 'true');
          setImportant(box, {'background':'rgba(37,99,235,.08)','border':'2px solid rgba(37,99,235,.9)','box-sizing':'border-box','display':'none','left':'0','margin':'0','min-height':'0','min-width':'0','pointer-events':'none','position':'fixed','top':'0','transform':'translate3d(0,0,0)','z-index':'2147483646'});
          (doc.body || doc.documentElement).append(box);
          return box;
        };
        const hideHoverBox = (doc) => {
          if (hoverTarget) hoverTarget.classList.remove('webpatch-comment-hover');
          hoverTarget = null;
          const box = doc && doc.getElementById('webpatch-hover-box');
          if (box) box.style.setProperty('display', 'none', 'important');
        };
        const updateHoverBox = (target) => {
          if (!commentMode || !target || !target.ownerDocument || target.closest('[data-webpatch-comment-marker]')) return;
          target = commentTargetElement(target);
          const doc = target.ownerDocument;
          const rect = target.getBoundingClientRect();
          if (rect.width <= 0 || rect.height <= 0) {
            hideHoverBox(doc);
            return;
          }
          if (hoverTarget && hoverTarget !== target) hoverTarget.classList.remove('webpatch-comment-hover');
          hoverTarget = target;
          target.classList.add('webpatch-comment-hover');
          const box = doc.getElementById('webpatch-hover-box');
          if (box) box.style.setProperty('display', 'none', 'important');
        };
        const markerLayer = (doc) => {
          let layer = doc.getElementById('webpatch-comment-marker-layer');
          if (layer) {
            return layer;
          }
          layer = doc.createElement('webpatch-comment-marker-layer');
          layer.id = 'webpatch-comment-marker-layer';
          layer.setAttribute('aria-hidden', 'true');
          setImportant(layer, {
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
          setImportant(box, {
            'position': 'absolute',
            'left': `${view.scrollX + rect.left - 3}px`,
            'top': `${view.scrollY + rect.top - 3}px`,
            'width': `${rect.width + 6}px`,
            'height': `${rect.height + 6}px`,
            'border': '2px solid rgba(249,115,22,.95)',
            'border-radius': '6px',
            'box-shadow': '0 0 0 4px rgba(249,115,22,.18)',
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
        const pinStyles = {'align-items':'center','background':'#2563eb','border':'2px solid #fff','border-radius':'999px','box-shadow':'0 8px 24px rgba(18,25,38,.18)','box-sizing':'border-box','color':'#fff','cursor':'pointer','display':'inline-flex','font':'600 12px -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif','font-family':'-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif','font-size':'12px','font-style':'normal','font-variant':'normal','font-weight':'600','height':'24px','justify-content':'center','left':'50%','line-height':'1','margin':'0','mix-blend-mode':'normal','min-height':'0','min-width':'0','opacity':'1','overflow':'visible','padding':'0','pointer-events':'auto','position':'absolute','text-align':'center','text-decoration':'none','text-indent':'0','text-shadow':'none','text-transform':'none','top':'0','transform':'translate(-50%, 0)','visibility':'visible','-webkit-background-clip':'border-box','background-clip':'border-box','-webkit-text-fill-color':'#fff','width':'24px','z-index':'2147483647'};
        const labelStyles = {'align-items':'center','background':'transparent','color':'#fff','display':'inline-flex','font':'600 12px -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif','font-family':'-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif','font-size':'12px','font-style':'normal','font-variant':'normal','font-weight':'600','height':'100%','justify-content':'center','letter-spacing':'0','line-height':'1','margin':'0','mix-blend-mode':'normal','opacity':'1','overflow':'visible','padding':'0','position':'static','text-align':'center','text-decoration':'none','text-indent':'0','text-shadow':'none','text-transform':'none','transform':'none','visibility':'visible','-webkit-background-clip':'border-box','background-clip':'border-box','-webkit-text-fill-color':'#fff','width':'100%'};
        const renderMarkers = () => {
          let doc;
          try { doc = iframe.contentDocument; } catch (error) { return; }
          if (!doc || !doc.body) return;
          clearMarkers(doc);
          if (!commentMode) return;
          ensureMarkerStyle(doc);
          const markerSelectorTotals = new Map();
          const markerSelectorOffsets = new Map();
          threads.forEach((thread) => {
            if (thread.is_resolved || !isCurrentFileThread(thread) || !threadMatchesCurrentViewport(thread) || !thread.selector) return;
            markerSelectorTotals.set(thread.selector, (markerSelectorTotals.get(thread.selector) || 0) + 1);
          });
          threads.forEach((thread) => {
            if (thread.is_resolved) return;
            if (!isCurrentFileThread(thread)) return;
            if (!threadMatchesCurrentViewport(thread)) return;
            let target = null;
            try { target = doc.querySelector(thread.selector); } catch (error) {}
            if (!target) return;
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
            setImportant(marker, pinStyles);
            if (Number(thread.id) === Number(selectedThreadId)) {
              marker.style.setProperty('background', '#f97316', 'important');
              marker.style.setProperty('border-color', '#fff', 'important');
              marker.style.setProperty('box-shadow', '0 0 0 4px rgba(249,115,22,.22), 0 8px 24px rgba(18,25,38,.18)', 'important');
            } else if (thread.is_confirmation_pending) {
              marker.style.setProperty('background', '#16a34a', 'important');
              marker.style.setProperty('border-color', '#fff', 'important');
              marker.style.setProperty('box-shadow', '0 0 0 4px rgba(22,163,74,.22), 0 8px 24px rgba(18,25,38,.18)', 'important');
            }
            const markerLabel = doc.createElement('span');
            markerLabel.textContent = String(markerNumber);
            setImportant(markerLabel, labelStyles);
            marker.append(markerLabel);
            marker.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); const next = threadById(thread.id); if (next) { focusCommentListItem(next); renderModal(next); } });
            marker.addEventListener('keydown', (event) => {
              if (event.key !== 'Enter' && event.key !== ' ') {
                return;
              }
              event.preventDefault();
              const next = threadById(thread.id);
              if (next) {
                focusCommentListItem(next);
                renderModal(next);
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
        const renderList = () => {
          list.replaceChildren();
          if (threads.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'share-empty';
            empty.textContent = 'まだコメントはありません。';
            list.append(empty);
            return;
          }
          threads.forEach((thread) => {
            const button = document.createElement('article');
            button.className = 'comment-list-item';
            const currentMissingTarget = !thread.is_resolved && hasThreadViewport(thread) && isCurrentFileThread(thread) && !threadTargetExists(thread);
            button.classList.toggle('resolved', Boolean(thread.is_resolved));
            button.classList.toggle('confirmation-pending', !thread.is_resolved && Boolean(thread.is_confirmation_pending));
            button.classList.toggle('missing-target', currentMissingTarget);
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
            } else if (!thread.is_resolved && thread.is_confirmation_pending) {
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
              if (event.key !== 'Enter' && event.key !== ' ') return;
              event.preventDefault();
              activateCommentFromList(thread);
            });
            list.append(button);
          });
        };
        const scrollToThread = (thread) => {
          if (!isCurrentFileThread(thread)) {
            if (!threadFileExists(thread)) {
              showToast(`コメント ${threadNumberLabel(thread)} のページが現在のサイト内に見つかりません。`, 'error');
              return false;
            }
            window.location.href = publicPageUrl(resolveThreadFile(thread), thread.id);
            return true;
          }
          if (switchToThreadViewport(thread)) {
            [120, 320, 700].forEach((delay) => {
              window.setTimeout(() => scrollToThread(thread), delay);
            });
            return true;
          }
          let doc, target;
          try { doc = iframe.contentDocument; target = doc.querySelector(thread.selector); } catch (error) {}
          if (!target) {
            showToast(`コメント ${threadNumberLabel(thread)} の対象要素が現在のHTML内に見つかりません。`, 'error');
            renderList();
            return false;
          }
          target = commentTargetElement(target);
          const previewWindow = iframe.contentWindow;
          if (!previewWindow) return false;
          const rect = target.getBoundingClientRect();
          const nextTop = Math.max(0, previewWindow.scrollY + rect.top + rect.height / 2 - previewWindow.innerHeight / 2);
          previewWindow.scrollTo({ left: previewWindow.scrollX, top: nextTop, behavior: 'smooth' });
          [0, 120, 320, 700].forEach((delay) => {
            window.setTimeout(() => {
              if (commentMode) renderMarkers();
            }, delay);
          });
          return true;
        };
        const threadTargetExists = (thread) => {
          if (!isCurrentFileThread(thread) || !thread.selector) return false;
          if (!threadMatchesCurrentViewport(thread)) return true;
          let doc;
          try { doc = iframe.contentDocument; } catch (error) {}
          if (!doc) return true;
          try { return Boolean(doc.querySelector(thread.selector)); } catch (error) { return false; }
        };
        const focusCommentListItem = (thread) => {
          if (!thread || !list) return;
          selectedThreadId = Number(thread.id);
          if (commentMode) renderMarkers();
          if (window.webpatchSetSidebarTab) window.webpatchSetSidebarTab('comments');
          const item = list.querySelector(`[data-thread-id="${thread.id}"]`);
          if (!item) return;
          list.querySelectorAll('.comment-list-item.active').forEach((activeItem) => activeItem.classList.remove('active'));
          item.classList.add('active');
          item.scrollIntoView({ behavior: 'smooth', block: 'center' });
        };
        const commentClipboardText = (comment, thread) => {
          const file = thread.file_path || activeFile;
          const target = `${fileCopyTargets[file] || file} の ${thread.selector || ''}`;
          const attachmentLines = Array.isArray(comment.images)
            ? comment.images
                .map((image) => image && image.id ? `#添付 ${new URL(`${commentImageBaseUrl}?id=${encodeURIComponent(image.id)}&client_token=${encodeURIComponent(token)}`, window.location.origin).toString()}` : '')
                .filter(Boolean)
            : [];
          return [`#対象 : ${target}`, `#コメント : ${comment.body || ''}`, ...attachmentLines].join('\n');
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
        const activateCommentFromList = async (thread) => {
          if (navigateToThreadPage(thread)) {
            return;
          }
          focusCommentListItem(thread);
          if (!scrollToThread(thread)) {
            renderList();
          }
        };
        const loadComments = async () => {
          const params = new URLSearchParams({ token, file: activeFile, guest_key: guestKey });
          const response = await fetch(`<?= h(base_url('client-comments')) ?>?${params.toString()}`, { headers: { 'Accept': 'application/json' } });
          const result = await response.json();
          if (!response.ok || !result.ok) throw new Error(result.message || 'コメントを読み込めませんでした。');
          threads = (result.threads || []).filter(isCurrentFileThread);
          renderList();
          scheduleMarkerRender();
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
        const postComment = async (payload) => {
          const targetFile = currentPreviewFile();
          if (!targetFile) {
            throw new Error('現在の表示ページを特定できないため、コメントを保存できません。ページを再読み込みしてください。');
          }
          const guestName = replyForm.elements.guest_name.value.trim() || 'ゲスト';
          localStorage.setItem('webpatch-client-guest-name', guestName);
          const imageInput = replyForm.querySelector('[data-comment-images]');
          const imageFiles = imageInput ? Array.from(imageInput.files || []) : [];
          const hasImages = imageFiles.length > 0;
          const requestOptions = {
            method: 'POST',
            headers: { 'Accept': 'application/json' }
          };
          const basePayload = { csrf_token: csrfToken, token, file: targetFile, guest_name: guestName, guest_key: guestKey, viewport_mode: currentPreviewMode(), ...payload };
          if (hasImages) {
            const formData = new FormData();
            Object.entries(basePayload).forEach(([key, value]) => formData.append(key, value == null ? '' : String(value)));
            imageFiles.forEach((file) => formData.append('images[]', file));
            requestOptions.body = formData;
          } else {
            requestOptions.headers['Content-Type'] = 'application/json';
            requestOptions.body = JSON.stringify(basePayload);
          }
          const response = await fetch('<?= h(base_url('client-comments')) ?>', requestOptions);
          const result = await response.json();
          if (!response.ok || !result.ok) throw new Error(result.message || 'コメントを保存できませんでした。');
          threads = (result.threads || []).filter(isCurrentFileThread);
          renderList();
          renderMarkers();
          if (imageInput) {
            imageInput.value = '';
          }
          return result;
        };
        const openDeleteModal = (comment) => {
          if (!comment || !comment.id) return;
          pendingDeleteComment = comment;
          if (!deleteModal) {
            performDeleteComment(comment);
            return;
          }
          deleteModal.hidden = false;
          if (deleteConfirmButton) deleteConfirmButton.focus();
        };
        const closeDeleteModal = () => {
          if (deleteModal) deleteModal.hidden = true;
          pendingDeleteComment = null;
        };
        const performDeleteComment = async (comment) => {
          try {
            const response = await fetch('<?= h(base_url('client-comments')) ?>', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
              body: JSON.stringify({
                csrf_token: csrfToken,
                token,
                file: activeFile,
                guest_key: guestKey,
                action: 'delete',
                comment_id: comment.id
              })
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'コメントを削除できませんでした。');
            const currentThreadId = result.focus_id || activeThreadId;
            if (result.focus_id) {
              activeThreadId = result.focus_id;
              selectedThreadId = result.focus_id;
            }
            threads = (result.threads || []).filter(isCurrentFileThread);
            renderList();
            renderMarkers();
            if (selectedThreadId && !threadById(selectedThreadId)) selectedThreadId = null;
            if (currentThreadId) {
              const next = threadById(currentThreadId);
              if (next) renderModal(next);
              else closeModal();
            }
            showToast('コメントを削除しました。', 'success');
          } catch (error) {
            showToast(error.message || 'コメントを削除できませんでした。', 'error');
          }
        };
        const deleteComment = (comment) => openDeleteModal(comment);
        const toggleResolved = async () => {
          if (!activeThreadId || !resolveButton) {
            return;
          }
          const thread = threadById(activeThreadId);
          const targetFile = resolveThreadFile(thread) || activeFile;
          resolveButton.disabled = true;
          try {
            const response = await fetch('<?= h(base_url('client-comments')) ?>', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
              },
              body: JSON.stringify({
                csrf_token: csrfToken,
                token,
                file: targetFile,
                guest_key: guestKey,
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
            renderMarkers();
            const nextThread = threadById(activeThreadId);
            if (nextThread) {
              focusCommentListItem(nextThread);
              renderModal(nextThread);
            } else {
              closeModal();
            }
            showToast(result.resolved ? 'コメントを解決済みにしました。' : '解決済みを解除しました。', 'success');
          } catch (error) {
            showToast(error.message || 'コメント状態を更新できませんでした。', 'error');
          } finally {
            resolveButton.disabled = false;
          }
        };
        window.webpatchSetCommentMode = (enabled) => {
          commentMode = enabled;
          commentToggle.classList.toggle('active', commentMode);
          commentToggle.setAttribute('aria-pressed', commentMode ? 'true' : 'false');
          let doc;
          try { doc = iframe.contentDocument; } catch (error) {}
          if (doc && doc.documentElement) {
            doc.documentElement.classList.toggle('webpatch-comment-mode', commentMode);
            if (commentMode) {
              renderMarkers();
            } else {
              clearMarkers(doc);
              hideHoverBox(doc);
            }
          }
        };
        commentToggle.addEventListener('click', () => window.webpatchSetCommentMode(!commentMode));
        closeButton.addEventListener('click', closeModal);
        if (resolveButton) {
          resolveButton.addEventListener('click', toggleResolved);
        }
        modal.addEventListener('click', (event) => { if (event.target === modal) closeModal(); });
        replyForm.addEventListener('submit', async (event) => {
          event.preventDefault();
          const body = replyForm.elements.body.value.trim();
          if (!activeThreadId && !draftSelector && !editCommentId) return;
          try {
            if (editCommentId) {
              const result = await postComment({ action: 'update', comment_id: editCommentId, file: editCommentFile, body });
              const next = threadById(result.updated_id || editCommentId);
              if (next) {
                focusCommentListItem(next);
              }
              closeModal();
              showToast('コメントを更新しました。', 'success');
              return;
            }
            const result = draftSelector ? await postComment({ selector: draftSelector, body }) : await postComment({ parent_id: activeThreadId, body });
            const next = threadById(result.created_id || activeThreadId);
            if (next) {
              focusCommentListItem(next);
              renderModal(next);
            }
            showToast('コメントを追加しました。', 'success');
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
            if (event.target === deleteModal) closeDeleteModal();
          });
        }
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
            window.location.href = publicPageUrl(previewFile);
            return;
          }
          const doc = iframe.contentDocument;
          if (!doc || !doc.defaultView) return;
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
            if (event.target.closest('[data-webpatch-comment-marker]') || event.target.closest('#webpatch-comment-marker-layer') || !commentMode) return;
            event.preventDefault();
            event.stopPropagation();
            const target = event.target.closest('body *');
            if (target) openCreateModal(selectorForElement(commentTargetElement(target), doc));
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
            if (target) updateHoverBox(commentTargetElement(target));
          }, true);
          doc.addEventListener('mouseleave', () => hideHoverBox(doc), true);
          doc.defaultView.addEventListener('resize', () => {
            renderMarkers();
            if (hoverTarget) updateHoverBox(hoverTarget);
          });
          doc.defaultView.addEventListener('scroll', () => {
            renderMarkers();
            if (hoverTarget) updateHoverBox(hoverTarget);
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
        });
        loadComments().catch((error) => showToast(error.message || 'コメントを読み込めませんでした。', 'error'));
      })();
    </script>
  </body>
</html>
