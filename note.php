<?php

require __DIR__ . '/_app.php';
require $GLOBALS['webpatch_app_root'] . '/layout.php';

$user = require_user();
$noteRef = (string) ($_GET['id'] ?? '');
$note = find_note_for_user_ref($noteRef, (int) $user['id']);

if (!$note) {
    http_response_code(404);
    render_app_page('ノートが見つかりません', '<div class="notice error">ノートが見つかりません。</div>');
    exit;
}

$canManageNote = user_owns_note($note, (int) $user['id']);
$canEditNote = $canManageNote || ($note['access_role'] ?? '') === 'view';
$sharedUsers = $canManageNote ? shared_users_for_note((int) $note['id']) : [];
$publicLink = $canManageNote ? public_link_for_note((int) $note['id']) : null;
$publicLinkUrl = ($publicLink !== null && (int) $publicLink['enabled'] === 1)
    ? public_note_url((string) $publicLink['token'])
    : '';
$markdown = (string) $note['markdown'];
$markdownBody = preg_replace('/^\s*#\s+.+(?:\R|$)/u', '', $markdown, 1) ?? $markdown;
$bodyHtml = render_markdown_document($markdownBody);

ob_start();
?>
<section class="note-reader-shell">
  <aside class="note-toc" data-note-toc hidden aria-label="目次"></aside>
  <article class="note-paper" data-note-paper>
    <header class="note-article-header">
      <p class="eyebrow">Note</p>
      <h1 data-note-title><?= h($note['title']) ?></h1>
      <p><?= h(date('Y/m/d H:i', strtotime((string) $note['updated_at']))) ?></p>
    </header>
    <div class="note-article" data-note-article>
      <?= $bodyHtml ?>
    </div>
    <?php if ($canEditNote): ?>
      <form class="note-append-form" action="<?= h(base_url('append-note.php')) ?>" method="post" enctype="multipart/form-data" data-note-append-form>
        <?= csrf_field() ?>
        <input type="hidden" name="note_id" value="<?= h(note_public_ref($note)) ?>">
        <input id="note_append_md" class="sr-only" name="note_md" type="file" accept=".md,text/markdown,text/plain" required data-note-append-input>
        <button class="secondary-button note-append-button" type="button" data-note-append-trigger>md追加</button>
        <p class="help-text">Markdownファイルを選択すると、このノートの末尾に内容を追加します。</p>
      </form>
    <?php endif; ?>
  </article>
</section>
<script>
  (() => {
    const toc = document.querySelector('[data-note-toc]');
    const paper = document.querySelector('[data-note-paper]');
    const title = document.querySelector('[data-note-title]');
    const article = document.querySelector('[data-note-article]');
    if (!toc || !paper || !title || !article) return;

    let tocTimer = 0;
    const normalizeId = (value, index) => {
      const base = String(value || '').trim().toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-+|-+$/g, '');
      return base !== '' ? `note-${base}` : `note-heading-${index + 1}`;
    };
    const uniqueId = (element, text, index) => {
      if (element.id) return element.id;
      let id = normalizeId(text, index);
      let count = 2;
      while (document.getElementById(id)) {
        id = `${normalizeId(text, index)}-${count}`;
        count += 1;
      }
      element.id = id;
      return id;
    };
    const createLink = (label, target, className = '') => {
      const link = document.createElement('a');
      link.href = `#${target}`;
      link.textContent = label;
      if (className) link.className = className;
      return link;
    };
    const buildToc = () => {
      const titleText = (title.innerText || title.textContent || '').trim() || 'ドキュメント';
      const titleId = uniqueId(title, titleText, 0);
      const headings = Array.from(article.querySelectorAll('h1, h2, h3')).filter((heading) => (heading.innerText || heading.textContent || '').trim() !== '');

      toc.replaceChildren();
      const heading = document.createElement('p');
      heading.className = 'note-toc-title';
      heading.textContent = '目次';
      const documentLabel = document.createElement('span');
      documentLabel.className = 'note-toc-label';
      documentLabel.textContent = 'ドキュメント';
      toc.append(heading, documentLabel, createLink(titleText, titleId, 'note-toc-link note-toc-document'));

      if (headings.length > 0) {
        const sectionLabel = document.createElement('span');
        sectionLabel.className = 'note-toc-label';
        sectionLabel.textContent = '見出し';
        toc.append(sectionLabel);
        headings.forEach((headingElement, index) => {
          const text = (headingElement.innerText || headingElement.textContent || '').trim();
          const id = uniqueId(headingElement, text, index + 1);
          const level = headingElement.tagName.toLowerCase();
          toc.append(createLink(text, id, `note-toc-link note-toc-${level}`));
        });
      }

      toc.hidden = false;
    };
    const scheduleToc = () => {
      window.clearTimeout(tocTimer);
      tocTimer = window.setTimeout(buildToc, 180);
    };

    window.webpatchBuildNoteToc = buildToc;
    buildToc();
    title.addEventListener('input', scheduleToc);
    article.addEventListener('input', scheduleToc);
  })();
</script>
<?php if ($canEditNote): ?>
<div class="note-delete-modal-backdrop" data-note-delete-modal hidden>
  <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="note-delete-title" aria-describedby="note-delete-message">
    <div class="confirm-modal-header">
      <h3 id="note-delete-title">ドキュメントを削除しますか？</h3>
      <button class="icon-button" type="button" data-note-delete-cancel aria-label="閉じる">×</button>
    </div>
    <p id="note-delete-message">このドキュメントをノートから削除します。削除後は保存され、元に戻すには再アップロードが必要です。</p>
    <div class="modal-actions">
      <button class="modal-button secondary" type="button" data-note-delete-cancel>キャンセル</button>
      <button class="modal-button danger" type="button" data-note-delete-confirm>削除する</button>
    </div>
  </div>
</div>
<?php if ($canManageNote): ?>
<div class="share-modal-backdrop" data-note-share-modal hidden>
  <div class="share-modal" role="dialog" aria-modal="true" aria-labelledby="note-share-modal-title">
    <div class="share-modal-header">
      <div>
        <p class="eyebrow">Members</p>
        <h2 id="note-share-modal-title">ノート共有</h2>
        <p>登録済みアカウントには即時共有し、未登録メールには招待リンクを送ります。</p>
      </div>
      <button class="icon-button" type="button" data-note-share-modal-close aria-label="閉じる">×</button>
    </div>
    <form class="share-form" data-note-share-form action="<?= h(base_url('share-note.php')) ?>" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="note_id" value="<?= h(note_public_ref($note)) ?>">
      <div class="field">
        <label for="note_share_email">メールアドレス</label>
        <input id="note_share_email" name="email" type="email" placeholder="member@example.com" autocomplete="email" required>
      </div>
      <button class="primary-button" type="submit">共有する</button>
    </form>
    <div class="share-result" data-note-share-result aria-live="polite"></div>
    <div class="public-share-panel">
      <h3>公開リンク</h3>
      <p>URLを知っているゲストはログインなしで閲覧できます。検索エンジンには表示されない設定です。</p>
      <div class="public-link-row">
        <input type="text" value="<?= h($publicLinkUrl) ?>" data-note-public-link-url readonly placeholder="まだ公開リンクは有効ではありません">
        <button class="secondary-button" type="button" data-note-public-link-copy>コピー</button>
      </div>
      <div class="public-link-actions">
        <button class="secondary-button" type="button" data-note-public-link-action="enable">有効化</button>
        <button class="secondary-button" type="button" data-note-public-link-action="regenerate">再発行</button>
        <button class="secondary-button danger" type="button" data-note-public-link-action="disable">無効化</button>
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
              <small>閲覧可</small>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<div class="toast" data-action-toast role="status" aria-live="polite" aria-atomic="true"></div>
<script>
  (() => {
    const toast = document.querySelector('[data-action-toast]');
    let toastTimer = 0;
    window.webpatchShowToast = (message, type = 'success') => {
      if (!toast || !message) return;
      toast.textContent = message;
      toast.classList.remove('success', 'error', 'visible');
      toast.classList.add(type === 'error' ? 'error' : 'success', 'visible');
      window.clearTimeout(toastTimer);
      toastTimer = window.setTimeout(() => toast.classList.remove('visible'), 2600);
    };

    const shareToggle = document.querySelector('[data-note-share-toggle]');
    const editToggle = document.querySelector('[data-note-edit-toggle]');
    const saveButton = document.querySelector('[data-save-note]');
    const paper = document.querySelector('[data-note-paper]');
    const title = document.querySelector('[data-note-title]');
    const article = document.querySelector('[data-note-article]');
    const appendForm = document.querySelector('[data-note-append-form]');
    const appendInput = document.querySelector('[data-note-append-input]');
    const appendTrigger = document.querySelector('[data-note-append-trigger]');
    const deleteModal = document.querySelector('[data-note-delete-modal]');
    const deleteConfirmButton = document.querySelector('[data-note-delete-confirm]');
    const deleteTitle = document.getElementById('note-delete-title');
    const deleteMessage = document.getElementById('note-delete-message');
    const deleteCancelButtons = Array.from(document.querySelectorAll('[data-note-delete-cancel]'));
    const modal = document.querySelector('[data-note-share-modal]');
    const closeButton = document.querySelector('[data-note-share-modal-close]');
    const form = document.querySelector('[data-note-share-form]');
    const resultBox = document.querySelector('[data-note-share-result]');
    const publicLinkInput = document.querySelector('[data-note-public-link-url]');
    const publicLinkCopy = document.querySelector('[data-note-public-link-copy]');
    const publicLinkButtons = Array.from(document.querySelectorAll('[data-note-public-link-action]'));
    let editMode = false;
    let isSaving = false;
    let isReplacingDocument = false;
    let pendingDeleteDocument = null;
    let pendingDeleteDeletesNote = false;

    const refreshToc = () => {
      if (typeof window.webpatchBuildNoteToc === 'function') {
        window.webpatchBuildNoteToc();
      }
    };
    const renumberDocumentBlocks = () => {
      if (!article) return;
      Array.from(article.querySelectorAll(':scope > .note-document')).forEach((documentBlock, index) => {
        documentBlock.dataset.noteDocumentIndex = String(index + 1);
        const deleteButton = documentBlock.querySelector('[data-note-document-action="delete"]');
        if (deleteButton) deleteButton.hidden = index === 0;
      });
    };

    const setEditMode = (enabled) => {
      editMode = enabled;
      if (paper) paper.classList.toggle('editing', editMode);
      if (editToggle) {
        editToggle.classList.toggle('active', editMode);
        editToggle.setAttribute('aria-pressed', editMode ? 'true' : 'false');
      }
      if (title) {
        title.contentEditable = editMode ? 'true' : 'false';
      }
      if (article) {
        article.contentEditable = editMode ? 'true' : 'false';
      }
      if (saveButton) {
        saveButton.disabled = !editMode || isSaving;
      }
      if (editMode && title) {
        title.focus();
      }
    };

    const text = (node) => (node ? (node.innerText || node.textContent || '').trim() : '');
    const markdownFromNodes = (nodes) => Array.from(nodes).map(markdownForNode).filter((block) => block !== '').join('\n\n');
    const markdownForNode = (node) => {
      if (!node || node.nodeType !== Node.ELEMENT_NODE) return '';
      if (node.classList && node.classList.contains('note-document-toolbar')) return '';
      if (node.classList && node.classList.contains('note-document')) {
        const body = node.querySelector(':scope > .note-document-body');
        return body ? markdownFromNodes(body.children) : markdownFromNodes(node.children);
      }
      if (node.classList && node.classList.contains('note-document-body')) {
        return markdownFromNodes(node.children);
      }
      if (node.classList && node.classList.contains('note-table-wrap')) {
        const table = node.querySelector(':scope > table');
        return table ? markdownForNode(table) : markdownFromNodes(node.children);
      }
      const tag = node.tagName ? node.tagName.toLowerCase() : '';
      if (tag === 'h1') return '# ' + text(node);
      if (tag === 'h2') return '## ' + text(node);
      if (tag === 'h3') return '### ' + text(node);
      if (tag === 'blockquote') return text(node).split('\n').map((line) => '> ' + line).join('\n');
      if (tag === 'pre') return '```\n' + text(node) + '\n```';
      if (tag === 'hr') return '---';
      if (tag === 'ul') {
        return Array.from(node.children).filter((child) => child.tagName && child.tagName.toLowerCase() === 'li').map((child) => '- ' + text(child)).join('\n');
      }
      if (tag === 'table') {
        const rows = Array.from(node.querySelectorAll('tr')).map((row) => Array.from(row.children).filter((cell) => {
          const cellTag = cell.tagName ? cell.tagName.toLowerCase() : '';
          return cellTag === 'th' || cellTag === 'td';
        }).map((cell) => text(cell).replace(/\|/g, '\\|')));
        if (rows.length === 0 || rows[0].length === 0) return '';
        const columnCount = rows[0].length;
        const normalizeRow = (row) => {
          const next = row.slice(0, columnCount);
          while (next.length < columnCount) next.push('');
          return next;
        };
        const separator = Array.from({ length: columnCount }, () => '---');
        return [
          '| ' + normalizeRow(rows[0]).join(' | ') + ' |',
          '| ' + separator.join(' | ') + ' |',
          ...rows.slice(1).map((row) => '| ' + normalizeRow(row).join(' | ') + ' |')
        ].join('\n');
      }
      if (tag === 'section' || tag === 'div') return markdownFromNodes(node.children);
      return text(node);
    };
    const markdownFromArticle = () => {
      const blocks = markdownFromNodes(article ? article.children : []);
      return '# ' + text(title) + (blocks !== '' ? '\n\n' + blocks : '');
    };
    const saveNote = async (options = {}) => {
      const force = options.force === true;
      if ((!editMode && !force) || isSaving || !title || !article || !saveButton) return false;
      isSaving = true;
      saveButton.disabled = true;
      try {
        const response = await fetch('<?= h(base_url('save-note.php')) ?>', {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            csrf_token: <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            id: <?= json_encode(note_public_ref($note), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            title: text(title),
            markdown: markdownFromArticle()
          })
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || '保存できませんでした。');
        window.webpatchShowToast(result.message || '保存しました。', 'success');
        return true;
      } catch (error) {
        window.webpatchShowToast(error.message || '保存できませんでした。', 'error');
        return false;
      } finally {
        isSaving = false;
        saveButton.disabled = !editMode;
      }
    };

    const documentToolIcon = (action) => {
      const icons = {
        delete: '<svg class="note-document-tool-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9.25 4.75h5.5M5.75 7.25h12.5M8 7.25l.45 11.2a1.9 1.9 0 0 0 1.9 1.8h3.3a1.9 1.9 0 0 0 1.9-1.8L16 7.25M10.5 10.5v6M13.5 10.5v6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        upload: '<svg class="note-document-tool-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16.25V5.75m0 0-3.6 3.6M12 5.75l3.6 3.6M5.75 17.75v.75a2 2 0 0 0 2 2h8.5a2 2 0 0 0 2-2v-.75" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        download: '<svg class="note-document-tool-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.75v10.5m0 0 3.6-3.6M12 15.25l-3.6-3.6M5.75 18.25v.5a2 2 0 0 0 2 2h8.5a2 2 0 0 0 2-2v-.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        edit: '<svg class="note-document-tool-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.25 18.75h4.05L18.6 9.45a2.05 2.05 0 0 0 0-2.9L17.45 5.4a2.05 2.05 0 0 0-2.9 0l-9.3 9.3v4.05Z" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/><path d="m13.65 6.3 4.05 4.05" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>'
      };
      return icons[action] || icons.edit;
    };

    const createDocumentToolButton = (label, iconClass, action) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `note-document-tool-button ${action === 'delete' ? 'danger' : ''}`;
      button.dataset.noteDocumentAction = action;
      button.setAttribute('aria-label', label);
      button.title = label;
      button.contentEditable = 'false';
      button.innerHTML = documentToolIcon(action);
      return button;
    };
    const replaceDocumentFromFile = async (documentBlock, file) => {
      if (!documentBlock || !file || isReplacingDocument) return;
      const body = documentBlock.querySelector(':scope > .note-document-body');
      if (!body) return;
      isReplacingDocument = true;
      const formData = new FormData();
      formData.set('csrf_token', <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
      formData.set('note_id', <?= json_encode(note_public_ref($note), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
      formData.set('document_index', documentBlock.dataset.noteDocumentIndex || '0');
      formData.set('note_md', file);
      try {
        const response = await fetch('<?= h(base_url('replace-note-document.php')) ?>', {
          method: 'POST',
          headers: { 'Accept': 'application/json' },
          body: formData
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || '差し替えできませんでした。');
        body.innerHTML = result.html || '';
        renumberDocumentBlocks();
        refreshToc();
        window.webpatchShowToast(result.message || 'ドキュメントを差し替えました。', 'success');
      } catch (error) {
        window.webpatchShowToast(error.message || '差し替えできませんでした。', 'error');
      } finally {
        isReplacingDocument = false;
      }
    };
    const openReplaceFilePicker = (documentBlock) => {
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = '.md,text/markdown,text/plain';
      input.className = 'sr-only';
      input.addEventListener('change', () => {
        const file = input.files && input.files.length > 0 ? input.files[0] : null;
        if (file) replaceDocumentFromFile(documentBlock, file);
        input.remove();
      }, { once: true });
      document.body.appendChild(input);
      input.click();
    };
    const openDeleteModal = (documentBlock) => {
      if (!deleteModal || !documentBlock) return;
      const documents = Array.from(article.querySelectorAll(':scope > .note-document'));
      const isLastDocument = documents.length <= 1;
      if (isLastDocument && !<?= $canManageNote ? 'true' : 'false' ?>) {
        window.webpatchShowToast('最後のドキュメント削除は所有者のみ可能です。', 'error');
        return;
      }
      pendingDeleteDocument = documentBlock;
      pendingDeleteDeletesNote = isLastDocument;
      if (deleteTitle) {
        deleteTitle.textContent = isLastDocument ? 'ノートを削除しますか？' : 'ドキュメントを削除しますか？';
      }
      if (deleteMessage) {
        deleteMessage.textContent = isLastDocument
          ? '最後のドキュメントを削除すると、このノート全体を削除してノート一覧へ戻ります。'
          : 'このドキュメントをノートから削除します。削除後は保存され、元に戻すには再アップロードが必要です。';
      }
      deleteModal.hidden = false;
      document.body.classList.add('modal-open');
      if (deleteConfirmButton) deleteConfirmButton.focus();
    };
    const closeDeleteModal = () => {
      if (!deleteModal) return;
      deleteModal.hidden = true;
      document.body.classList.remove('modal-open');
      pendingDeleteDocument = null;
      pendingDeleteDeletesNote = false;
    };
    const deleteCurrentNote = async () => {
      try {
        const response = await fetch('<?= h(base_url('delete-note.php')) ?>', {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            csrf_token: <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            id: <?= json_encode(note_public_ref($note), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
          })
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'ノートを削除できませんでした。');
        window.location.href = result.redirect || <?= json_encode(base_url('notes.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
      } catch (error) {
        window.webpatchShowToast(error.message || 'ノートを削除できませんでした。', 'error');
      }
    };
    const focusDocument = (documentBlock) => {
      if (!documentBlock) return;
      setEditMode(true);
      const target = documentBlock.querySelector('.note-document-body h1, .note-document-body h2, .note-document-body h3, .note-document-body p, .note-document-body li, .note-document-body blockquote, .note-document-body pre');
      window.setTimeout(() => {
        if (target) target.focus();
      }, 0);
    };
    const safeDownloadName = (value, fallback) => {
      const name = String(value || '').trim().replace(/[\\/:*?"<>|]+/g, '-').replace(/\s+/g, '-').replace(/^-+|-+$/g, '');
      return (name || fallback || 'document').slice(0, 80);
    };
    const downloadNoteMarkdown = () => {
      const markdown = markdownFromArticle().trim();
      if (markdown === '') {
        window.webpatchShowToast('ダウンロードできるMarkdownがありません。', 'error');
        return;
      }
      const fileName = `${safeDownloadName(text(title), 'note')}.md`;
      const blob = new Blob([markdown + '\n'], { type: 'text/markdown;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = fileName;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1200);
      window.webpatchShowToast('Markdownをダウンロードしました。', 'success');
    };
    const createDocumentToolbar = (documentBlock) => {
      const toolbar = document.createElement('div');
      toolbar.className = 'note-document-toolbar';
      toolbar.contentEditable = 'false';
      toolbar.setAttribute('aria-label', 'ドキュメント操作');
      const editButton = createDocumentToolButton('このドキュメントを編集', 'edit', 'edit');
      const uploadButton = createDocumentToolButton('このドキュメントをmdで差し替え', 'upload', 'upload');
      const downloadButton = createDocumentToolButton('ノート全体をmdでダウンロード', 'download', 'download');
      const deleteButton = createDocumentToolButton('このドキュメントを削除', 'trash', 'delete');
      editButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        focusDocument(documentBlock);
      });
      uploadButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        openReplaceFilePicker(documentBlock);
      });
      downloadButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        downloadNoteMarkdown();
      });
      deleteButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        openDeleteModal(documentBlock);
      });
      toolbar.append(deleteButton, downloadButton, uploadButton, editButton);
      return toolbar;
    };

    let toolbarSyncFrame = 0;
    const syncDocumentToolbars = () => {
      toolbarSyncFrame = 0;
      const header = document.querySelector('.app-header');
      const headerHeight = header ? header.getBoundingClientRect().height : 64;
      const targetTop = headerHeight + 18;
      const documents = Array.from(article ? article.querySelectorAll(':scope > .note-document') : []);
      const isCompact = window.matchMedia('(max-width: 860px)').matches;
      document.documentElement.style.setProperty('--note-fixed-toolbar-top', `${Math.round(headerHeight + 12)}px`);
      if (isCompact) {
        const anchorY = headerHeight + 88;
        let activeDocument = documents[0] || null;
        let bestDistance = Number.POSITIVE_INFINITY;
        documents.forEach((documentBlock) => {
          const rect = documentBlock.getBoundingClientRect();
          const containsAnchor = rect.top <= anchorY && rect.bottom >= anchorY;
          const distance = containsAnchor ? 0 : Math.abs(rect.top - anchorY);
          if (distance < bestDistance) {
            bestDistance = distance;
            activeDocument = documentBlock;
          }
        });
        documents.forEach((documentBlock) => {
          documentBlock.classList.toggle('toolbar-active', documentBlock === activeDocument);
          const toolbar = documentBlock.querySelector(':scope > .note-document-toolbar');
          if (toolbar) toolbar.style.removeProperty('--note-toolbar-top');
        });
        return;
      }
      documents.forEach((documentBlock) => {
        documentBlock.classList.remove('toolbar-active');
        const toolbar = documentBlock.querySelector(':scope > .note-document-toolbar');
        if (!toolbar) return;
        const rect = documentBlock.getBoundingClientRect();
        const toolbarHeight = toolbar.offsetHeight || 176;
        const padding = 12;
        const maxViewportTop = rect.bottom - toolbarHeight - padding;
        const viewportTop = Math.min(Math.max(targetTop, rect.top + padding), maxViewportTop);
        const top = Math.max(padding, viewportTop - rect.top);
        toolbar.style.setProperty('--note-toolbar-top', `${Math.round(top)}px`);
      });
    };
    const scheduleDocumentToolbarSync = () => {
      if (toolbarSyncFrame) return;
      toolbarSyncFrame = window.requestAnimationFrame(syncDocumentToolbars);
    };

    const buildDocumentBlocks = () => {
      if (!article) return;
      Array.from(article.querySelectorAll(':scope > .note-document')).forEach((documentBlock) => {
        const body = documentBlock.querySelector(':scope > .note-document-body');
        if (body) {
          while (body.firstChild) {
            article.insertBefore(body.firstChild, documentBlock);
          }
        }
        documentBlock.remove();
      });
      const children = Array.from(article.children);
      if (children.length === 0) return;
      const groups = [];
      let current = [];
      children.forEach((child) => {
        const tag = child.tagName ? child.tagName.toLowerCase() : '';
        if (tag === 'h1' && current.length > 0) {
          groups.push(current);
          current = [];
        }
        current.push(child);
      });
      if (current.length > 0) groups.push(current);

      const fragment = document.createDocumentFragment();
      groups.forEach((group, index) => {
        const documentBlock = document.createElement('section');
        documentBlock.className = 'note-document';
        documentBlock.dataset.noteDocumentIndex = String(index + 1);
        const body = document.createElement('div');
        body.className = 'note-document-body';
        group.forEach((child) => body.appendChild(child));
        documentBlock.append(body, createDocumentToolbar(documentBlock));
        fragment.appendChild(documentBlock);
      });
      article.replaceChildren(fragment);
      renumberDocumentBlocks();
      refreshToc();
      scheduleDocumentToolbarSync();
    };

    if (editToggle) {
      editToggle.addEventListener('click', () => setEditMode(!editMode));
    }
    if (saveButton) {
      saveButton.addEventListener('click', saveNote);
    }
    document.addEventListener('keydown', (event) => {
      if (!(event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== 's') return;
      event.preventDefault();
      if (editMode) saveNote();
    });
    if (appendTrigger && appendInput) {
      appendTrigger.addEventListener('click', () => appendInput.click());
      appendInput.addEventListener('change', () => {
        if (appendInput.files && appendInput.files.length > 0 && appendForm) {
          appendForm.submit();
        }
      });
    }
    buildDocumentBlocks();
    window.addEventListener('scroll', scheduleDocumentToolbarSync, { passive: true });
    window.addEventListener('resize', scheduleDocumentToolbarSync);
    deleteCancelButtons.forEach((button) => button.addEventListener('click', closeDeleteModal));
    if (deleteModal) {
      deleteModal.addEventListener('click', (event) => {
        if (event.target === deleteModal) closeDeleteModal();
      });
    }
    if (deleteConfirmButton) {
      deleteConfirmButton.addEventListener('click', async () => {
        if (!pendingDeleteDocument || !article) return;
        if (pendingDeleteDeletesNote) {
          closeDeleteModal();
          await deleteCurrentNote();
          return;
        }
        const documentBlock = pendingDeleteDocument;
        const nextSibling = documentBlock.nextSibling;
        documentBlock.remove();
        closeDeleteModal();
        renumberDocumentBlocks();
        refreshToc();
        scheduleDocumentToolbarSync();
        const saved = await saveNote({ force: true });
        if (!saved) {
          if (nextSibling && nextSibling.parentNode === article) {
            article.insertBefore(documentBlock, nextSibling);
          } else {
            article.appendChild(documentBlock);
          }
          renumberDocumentBlocks();
          refreshToc();
          scheduleDocumentToolbarSync();
        }
      });
    }
    document.addEventListener('keydown', (event) => {
      if (deleteModal && !deleteModal.hidden && event.key === 'Escape') closeDeleteModal();
    });

    if (!shareToggle || !modal || !form) return;

    const setResult = (message, type = '', inviteUrl = '') => {
      resultBox.className = 'share-result';
      resultBox.replaceChildren();
      if (type) resultBox.classList.add(type);
      if (!message && !inviteUrl) return;
      const p = document.createElement('p');
      p.textContent = message;
      resultBox.append(p);
      if (inviteUrl) {
        const link = document.createElement('a');
        link.href = inviteUrl;
        link.textContent = inviteUrl;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        resultBox.append(link);
      }
    };
    const openModal = () => {
      modal.hidden = false;
      document.body.classList.add('modal-open');
      const input = document.getElementById('note_share_email');
      window.setTimeout(() => input && input.focus(), 0);
    };
    const closeModal = () => {
      modal.hidden = true;
      document.body.classList.remove('modal-open');
      setResult('');
    };
    shareToggle.addEventListener('click', openModal);
    if (closeButton) closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
      if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', (event) => {
      if (!modal.hidden && event.key === 'Escape') closeModal();
    });

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const submitButton = form.querySelector('button[type="submit"]');
      if (submitButton) submitButton.disabled = true;
      setResult('共有処理中...', '');
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          headers: { 'Accept': 'application/json' },
          body: new FormData(form)
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || '共有できませんでした。');
        form.reset();
        setResult(result.message || '共有しました。', 'success', result.invite_url || '');
        window.webpatchShowToast(result.message || '共有しました。', 'success');
      } catch (error) {
        setResult(error.message || '共有できませんでした。', 'error');
        window.webpatchShowToast(error.message || '共有できませんでした。', 'error');
      } finally {
        if (submitButton) submitButton.disabled = false;
      }
    });

    publicLinkButtons.forEach((button) => {
      button.addEventListener('click', async () => {
        const body = new FormData();
        body.set('csrf_token', <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
        body.set('note_id', <?= json_encode(note_public_ref($note), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
        body.set('action', button.dataset.notePublicLinkAction || 'enable');
        button.disabled = true;
        try {
          const response = await fetch('<?= h(base_url('public-note-link.php')) ?>', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body
          });
          const result = await response.json();
          if (!response.ok || !result.ok) throw new Error(result.message || '公開リンクを更新できませんでした。');
          if (publicLinkInput) publicLinkInput.value = result.url || '';
          setResult(result.message || '公開リンクを更新しました。', 'success', result.url || '');
          window.webpatchShowToast(result.message || '公開リンクを更新しました。', 'success');
        } catch (error) {
          setResult(error.message || '公開リンクを更新できませんでした。', 'error');
          window.webpatchShowToast(error.message || '公開リンクを更新できませんでした。', 'error');
        } finally {
          button.disabled = false;
        }
      });
    });

    if (publicLinkCopy) {
      publicLinkCopy.addEventListener('click', async () => {
        if (!publicLinkInput || publicLinkInput.value === '') {
          setResult('先に公開リンクを有効化してください。', 'error');
          return;
        }
        try {
          await navigator.clipboard.writeText(publicLinkInput.value);
          window.webpatchShowToast('公開リンクをコピーしました。', 'success');
        } catch (error) {
          publicLinkInput.select();
          setResult('リンクを選択しました。コピーしてください。', 'success');
        }
      });
    }
  })();
</script>
<?php endif; ?>
<?php
$shareControl = $canManageNote ? '
  <button class="member-share-button" type="button" data-note-share-toggle title="ノート共有">
    <span class="member-share-icon" aria-hidden="true"></span>
    <span>共有</span>
  </button>
' : '';
$editControls = $canEditNote ? '
  <button class="mode-toggle" type="button" data-note-edit-toggle aria-pressed="false">
    <span class="mode-label">文字編集</span>
  </button>
  <button class="save-button" type="button" data-save-note disabled>保存</button>
' : '';
render_app_page((string) $note['title'], ob_get_clean(), 'note-main', $shareControl . $editControls);
