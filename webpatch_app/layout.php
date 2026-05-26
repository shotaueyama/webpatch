<?php

declare(strict_types=1);

function render_auth_page(string $title, string $eyebrow, string $heading, string $lead, string $form): void
{
    $features = [
        'HTMLファイルをプロジェクト単位で整理',
        '公開前のページにそのままコメント',
        'チームで確認しやすいシンプルな画面設計',
    ];
    $flash = take_flash();
    ?><!doctype html>
<html lang="ja">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> | WebPatch</title>
    <link rel="icon" type="image/svg+xml" href="<?= h(base_url('favicon.svg')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= h(base_url('styles.css')) ?>">
  </head>
  <body>
    <main class="auth-shell">
      <section class="product-panel" aria-labelledby="product-title">
        <a class="brand" href="<?= h(base_url('login.php')) ?>" aria-label="WebPatch ホーム">
          <span class="brand-mark" aria-hidden="true">W</span>
          <span>WebPatch</span>
        </a>
        <div class="product-copy">
          <p class="eyebrow">HTML review workspace</p>
          <h1 id="product-title">アップロードしたHTMLに、ブラウザ上でそのままコメント。</h1>
          <p class="lead">ウェブサイトのHTMLデータを登録し、実際の表示を見ながら修正指示や確認コメントを残せる制作ワークスペースです。</p>
        </div>
        <div class="feature-list" aria-label="主な特徴">
          <?php foreach ($features as $feature): ?>
            <div class="feature-item">
              <span class="feature-dot" aria-hidden="true"></span>
              <span><?= h($feature) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
      <section class="form-panel" aria-labelledby="form-title">
        <div class="form-card">
          <div class="form-header">
            <p class="eyebrow"><?= h($eyebrow) ?></p>
            <h2 id="form-title"><?= h($heading) ?></h2>
            <p><?= h($lead) ?></p>
          </div>
          <?php if ($flash !== null): ?>
            <div class="notice <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
          <?php endif; ?>
          <?= $form ?>
        </div>
      </section>
    </main>
  </body>
</html>
<?php
}

function render_app_page(string $title, string $content, string $mainClass = '', string $headerControls = ''): void
{
    $user = current_user();
    $language = current_app_language();
    $flash = take_flash();
    $flashAsToast = $flash !== null && (($flash['type'] ?? '') === 'success' || str_contains(' ' . $mainClass . ' ', ' project-main '));
    ?><!doctype html>
<html lang="<?= h($language) ?>">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> | WebPatch</title>
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
          <a class="brand" href="<?= h(base_url('dashboard.php')) ?>" aria-label="<?= h(app_text('dashboard_aria', $language)) ?>">
            <span class="brand-mark" aria-hidden="true">W</span>
            <span>WebPatch</span>
          </a>
          <?php if ($user !== null): ?>
            <nav class="header-nav header-nav-desktop" aria-label="<?= h(app_text('main_navigation', $language)) ?>">
              <a href="<?= h(base_url('dashboard.php')) ?>"><?= h(app_text('dashboard', $language)) ?></a>
              <a href="<?= h(base_url('notes.php')) ?>"><?= h(app_text('notes', $language)) ?></a>
              <a href="<?= h(base_url('account.php')) ?>"><?= h(app_text('account_settings', $language)) ?></a>
            </nav>
          <?php endif; ?>
        </div>
        <?php if ($user !== null): ?>
          <button class="header-menu-button" type="button" data-header-menu-toggle aria-controls="header-menu" aria-expanded="false">
            <span aria-hidden="true"></span>
            <span class="sr-only"><?= h(app_text('menu', $language)) ?></span>
          </button>
          <div class="header-menu" id="header-menu" data-header-menu>
            <nav class="header-nav header-nav-mobile" aria-label="<?= h(app_text('main_navigation', $language)) ?>">
              <a href="<?= h(base_url('dashboard.php')) ?>"><?= h(app_text('dashboard', $language)) ?></a>
              <a href="<?= h(base_url('notes.php')) ?>"><?= h(app_text('notes', $language)) ?></a>
              <a href="<?= h(base_url('account.php')) ?>"><?= h(app_text('account_settings', $language)) ?></a>
            </nav>
            <?php if ($headerControls !== ''): ?>
              <div class="header-controls">
                <?= $headerControls ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </header>
      <main class="app-main<?= $mainClass !== '' ? ' ' . h($mainClass) : '' ?>">
        <?php if ($flash !== null && !$flashAsToast): ?>
          <div class="notice <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>
        <?= $content ?>
      </main>
    </div>
    <script>
      (() => {
        const button = document.querySelector('[data-header-menu-toggle]');
        const menu = document.querySelector('[data-header-menu]');
        if (!button || !menu) {
          return;
        }
        button.addEventListener('click', () => {
          const isOpen = menu.classList.toggle('open');
          button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
      })();
      <?php if ($flashAsToast): ?>
        (() => {
          const flash = <?= json_encode($flash, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
          const show = () => {
            if (window.webpatchShowToast) {
              window.webpatchShowToast(flash.message || '', flash.type || 'success');
              return;
            }
            if (!flash.message) return;
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.setAttribute('aria-atomic', 'true');
            toast.dataset.state = flash.type || 'success';
            toast.textContent = flash.message;
            document.body.appendChild(toast);
            window.requestAnimationFrame(() => toast.classList.add('show'));
            window.setTimeout(() => {
              toast.classList.remove('show');
              window.setTimeout(() => toast.remove(), 220);
            }, 2800);
          };
          if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', show, { once: true });
          } else {
            show();
          }
        })();
      <?php endif; ?>
    </script>
  </body>
</html>
<?php
}
