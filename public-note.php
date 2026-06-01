<?php

require __DIR__ . '/_app.php';

$token = (string) ($_GET['token'] ?? '');
$note = public_note_for_token($token);

header('X-Robots-Tag: noindex, nofollow, noarchive');

if ($note === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('公開リンクが無効です。');
}

$markdown = (string) $note['markdown'];
$markdownBody = preg_replace('/^\s*#\s+.+(?:\R|$)/u', '', $markdown, 1) ?? $markdown;
$bodyHtml = render_markdown_document($markdownBody);
?><!doctype html>
<html lang="ja">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?= h($note['title']) ?> | WebPatch 公開ノート</title>
    <link rel="icon" type="image/svg+xml" href="<?= h(base_url('favicon.svg')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= h(base_url('styles.css')) ?>">
  </head>
  <body>
    <div class="app-shell">
      <header class="app-header">
        <div class="header-left">
          <a class="brand" href="<?= h(base_url('login.php')) ?>" aria-label="WebPatch">
            <span class="brand-mark" aria-hidden="true">W</span>
            <span>WebPatch</span>
          </a>
        </div>
      </header>
      <main class="app-main note-main">
        <section class="note-reader-shell">
          <aside class="note-toc" data-note-toc hidden aria-label="目次"></aside>
          <div class="note-reader-content">
            <div class="note-reader-toolbar">
              <span>公開ノート</span>
              <span><?= h($note['original_filename']) ?></span>
            </div>
            <article class="note-paper" data-note-paper>
              <header class="note-article-header">
                <p class="eyebrow">Public Note</p>
                <h1 data-note-title><?= h($note['title']) ?></h1>
                <p><?= h(date('Y/m/d H:i', strtotime((string) $note['updated_at']))) ?></p>
              </header>
              <div class="note-article" data-note-article>
                <?= $bodyHtml ?>
              </div>
            </article>
          </div>
        </section>
      </main>
    </div>
    <script>
      (() => {
        const toc = document.querySelector('[data-note-toc]');
        const title = document.querySelector('[data-note-title]');
        const article = document.querySelector('[data-note-article]');
        if (!toc || !title || !article) return;

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
        const titleText = (title.innerText || title.textContent || '').trim() || 'ドキュメント';
        const titleId = uniqueId(title, titleText, 0);
        const headings = Array.from(article.querySelectorAll('h1, h2, h3')).filter((heading) => (heading.innerText || heading.textContent || '').trim() !== '');
        const tocTitle = document.createElement('p');
        tocTitle.className = 'note-toc-title';
        tocTitle.textContent = '目次';
        const documentLabel = document.createElement('span');
        documentLabel.className = 'note-toc-label';
        documentLabel.textContent = 'ドキュメント';
        toc.append(tocTitle, documentLabel, createLink(titleText, titleId, 'note-toc-link note-toc-document'));
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
      })();
    </script>
  </body>
</html>
