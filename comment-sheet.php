<?php

require __DIR__ . '/_app.php';

$project = null;
$publicToken = '';
$projectRef = (string) ($_GET['id'] ?? '');
$token = (string) ($_GET['token'] ?? '');
$user = null;

header('X-Robots-Tag: noindex, nofollow, noarchive');

if ($token !== '') {
    $project = public_project_for_token($token);
    $publicToken = $token;
} else {
    $user = current_user();
    if ($user !== null) {
        $project = find_project_for_user_ref($projectRef, (int) $user['id']);
    }
    if ($project === null) {
        $project = public_project_for_ref($projectRef);
        $publicToken = (string) ($project['public_token'] ?? '');
    }
    if ($project === null && $user === null) {
        $user = require_user();
    }
}

if ($project === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('プロジェクトが見つかりません。');
}

ensure_comment_ai_check_columns();
ensure_comment_client_share_column();

$canManageApi = $user !== null && user_owns_project($project, (int) $user['id']);
$apiTokenMeta = $canManageApi ? comment_sheet_api_token_meta((int) $project['id']) : null;
$copyPrompt = $user !== null ? project_copy_prompt_for_user((int) $project['id'], (int) $user['id']) : '';

function sheet_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_sheet_status(string $status): string
{
    return in_array($status, ['todo', 'doing', 'pending', 'done'], true) ? $status : 'todo';
}

function sheet_comment_attachment_map(array $commentIds, string $publicToken = ''): array
{
    $commentIds = array_values(array_unique(array_map('intval', $commentIds)));
    if ($commentIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
    $stmt = db()->prepare(
        'SELECT id, comment_id, original_filename, mime_type, byte_size
           FROM ' . table_name('comment_images') . '
          WHERE comment_id IN (' . $placeholders . ')
          ORDER BY id ASC'
    );
    $stmt->execute($commentIds);

    $attachments = [];
    foreach ($stmt->fetchAll() as $row) {
        $path = absolute_url('comment-image.php?id=' . rawurlencode((string) $row['id']));
        if ($publicToken !== '') {
            $path .= '&token=' . rawurlencode($publicToken);
        }
        $attachments[(int) $row['comment_id']][] = [
            'id' => (int) $row['id'],
            'filename' => $row['original_filename'],
            'mime_type' => $row['mime_type'],
            'byte_size' => (int) $row['byte_size'],
            'path' => $path,
            'url' => $path,
        ];
    }

    return $attachments;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new RuntimeException('更新内容を読み込めませんでした。');
        }
        if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($payload['csrf_token'] ?? ''))) {
            throw new RuntimeException('セッションの有効期限が切れました。もう一度お試しください。');
        }

        $commentId = (int) ($payload['comment_id'] ?? 0);
        if ($commentId <= 0) {
            throw new RuntimeException('コメントが見つかりません。');
        }

        $updates = [];
        $params = [];
        if (array_key_exists('sheet_status', $payload)) {
            $status = normalize_sheet_status((string) $payload['sheet_status']);
            $updates[] = 'sheet_status = ?';
            $params[] = $status;
            if ($status === 'done') {
                $updates[] = 'resolved_at = COALESCE(resolved_at, NOW())';
                $updates[] = 'confirmation_pending_at = NULL';
            } elseif ($status === 'pending') {
                $updates[] = 'resolved_at = NULL';
                $updates[] = 'confirmation_pending_at = COALESCE(confirmation_pending_at, NOW())';
            } else {
                $updates[] = 'resolved_at = NULL';
                $updates[] = 'confirmation_pending_at = NULL';
            }
        }
        if (array_key_exists('desired_due_at', $payload)) {
            $rawDue = trim((string) $payload['desired_due_at']);
            if ($rawDue === '') {
                $updates[] = 'desired_due_at = NULL';
            } else {
                $time = strtotime($rawDue);
                if ($time === false) {
                    throw new RuntimeException('希望完了日時の形式が不正です。');
                }
                $updates[] = 'desired_due_at = ?';
                $params[] = date('Y-m-d H:i:s', $time);
            }
        }
        if (array_key_exists('body', $payload)) {
            $body = trim((string) $payload['body']);
            if ($body === '') {
                throw new RuntimeException('コメント内容は空にできません。');
            }
            $updates[] = 'body = ?';
            $params[] = mb_substr($body, 0, 20000);
            $updates[] = 'ai_check_status = \'unchecked\'';
            $updates[] = 'ai_check_summary = NULL';
            $updates[] = 'ai_checked_at = NULL';
            $updates[] = 'ai_check_provider = NULL';
            $updates[] = 'ai_check_model = NULL';
        }
        if ($updates === []) {
            throw new RuntimeException('更新項目がありません。');
        }

        $params[] = $commentId;
        $params[] = (int) $project['id'];
        $stmt = db()->prepare(
            'UPDATE ' . table_name('comments') . '
                SET ' . implode(', ', $updates) . '
              WHERE id = ? AND project_id = ? AND parent_id IS NULL'
        );
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('コメントが見つかりません。');
        }
        sheet_response(['ok' => true]);
    } catch (Throwable $e) {
        sheet_response(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

$files = project_sidebar_html_files($project);
$fileCopyTargets = project_file_copy_targets($project, $files);

ensure_comment_confirmation_columns();
$commentWhere = 'WHERE c.project_id = ? AND c.parent_id IS NULL';
if ($publicToken !== '') {
    $commentWhere .= ' AND c.client_share_id IS NULL';
}
$stmt = db()->prepare(
    'SELECT c.id, c.file_path, c.selector, c.body, c.sheet_status, c.desired_due_at, c.ai_check_status, c.ai_check_summary, c.ai_checked_at, c.ai_check_provider, c.ai_check_model, c.resolved_at, c.confirmation_pending_at, c.created_at, u.name AS user_name
       FROM ' . table_name('comments') . ' c
       LEFT JOIN ' . table_name('users') . ' u ON u.id = c.user_id
      ' . $commentWhere . '
      ORDER BY c.file_path ASC, c.created_at ASC, c.id ASC'
);
$stmt->execute([(int) $project['id']]);

$comments = [];
$commentRows = $stmt->fetchAll();
$parentCommentIds = array_map('intval', array_column($commentRows, 'id'));
$replyRows = [];
if ($parentCommentIds !== []) {
    $placeholders = implode(',', array_fill(0, count($parentCommentIds), '?'));
    $replyWhere = 'WHERE c.project_id = ? AND c.parent_id IN (' . $placeholders . ')';
    if ($publicToken !== '') {
        $replyWhere .= ' AND c.client_share_id IS NULL';
    }
    $replyStmt = db()->prepare(
        'SELECT c.id, c.parent_id, c.body, c.created_at, c.guest_name, u.name AS user_name
           FROM ' . table_name('comments') . ' c
           LEFT JOIN ' . table_name('users') . ' u ON u.id = c.user_id
          ' . $replyWhere . '
          ORDER BY c.created_at ASC, c.id ASC'
    );
    $replyStmt->execute(array_merge([(int) $project['id']], $parentCommentIds));
    $replyRows = $replyStmt->fetchAll();
}

$replyCommentIds = array_map('intval', array_column($replyRows, 'id'));
$attachmentsByComment = sheet_comment_attachment_map(array_merge($parentCommentIds, $replyCommentIds), $publicToken);
$repliesByParent = [];
foreach ($replyRows as $replyRow) {
    $replyAttachments = $attachmentsByComment[(int) $replyRow['id']] ?? [];
    $repliesByParent[(int) $replyRow['parent_id']][] = [
        'id' => (int) $replyRow['id'],
        'body' => $replyRow['body'],
        'created_at' => $replyRow['created_at'],
        'user_name' => $replyRow['user_name'] ?: ($replyRow['guest_name'] ?: 'ゲスト'),
        'attachments' => $replyAttachments,
        'attachment_paths' => array_map(static fn (array $attachment): string => (string) $attachment['path'], $replyAttachments),
    ];
}

foreach ($commentRows as $row) {
    $attachments = $attachmentsByComment[(int) $row['id']] ?? [];
    $replies = $repliesByParent[(int) $row['id']] ?? [];
    $comments[] = [
        'id' => (int) $row['id'],
        'file_path' => $row['file_path'],
        'selector' => $row['selector'] ?? '',
        'body' => $row['body'],
        'sheet_status' => $row['resolved_at'] !== null ? 'done' : ($row['confirmation_pending_at'] !== null ? 'pending' : normalize_sheet_status((string) ($row['sheet_status'] ?? 'todo'))),
        'desired_due_at' => $row['desired_due_at'],
        'ai_check_status' => normalize_ai_check_status((string) ($row['ai_check_status'] ?? 'unchecked')),
        'ai_check_summary' => $row['ai_check_summary'] ?? '',
        'ai_checked_at' => $row['ai_checked_at'],
        'ai_check_provider' => $row['ai_check_provider'] ?? '',
        'ai_check_model' => $row['ai_check_model'] ?? '',
        'is_resolved' => $row['resolved_at'] !== null,
        'resolved_at' => $row['resolved_at'],
        'is_confirmation_pending' => $row['confirmation_pending_at'] !== null,
        'confirmation_pending_at' => $row['confirmation_pending_at'],
        'created_at' => $row['created_at'],
        'user_name' => $row['user_name'] ?: 'ゲスト',
        'attachments' => $attachments,
        'attachment_paths' => array_map(static fn (array $attachment): string => (string) $attachment['path'], $attachments),
        'replies' => $replies,
        'reply_count' => count($replies),
    ];
}

$sheetData = [
    'project' => [
        'id' => (int) $project['id'],
        'title' => $project['title'],
        'public_ref' => project_public_ref($project),
    ],
    'files' => $files,
    'file_copy_targets' => $fileCopyTargets,
    'comments' => $comments,
    'copy_prompt' => $copyPrompt,
    'csrf_token' => csrf_token(),
    'update_url' => base_url('comment-sheet.php' . ($publicToken !== '' ? '?token=' . rawurlencode($publicToken) : '?id=' . rawurlencode(project_public_ref($project)))),
    'api' => [
        'can_manage' => $canManageApi,
        'manage_url' => base_url('comment-sheet-token.php'),
        'endpoint_url' => absolute_url('sheet-api.php'),
        'project_ref' => project_public_ref($project),
        'enabled' => $apiTokenMeta !== null && (int) $apiTokenMeta['enabled'] === 1,
        'token_prefix' => $apiTokenMeta['token_prefix'] ?? '',
        'last_used_at' => $apiTokenMeta['last_used_at'] ?? null,
        'created_at' => $apiTokenMeta['created_at'] ?? null,
    ],
];
?><!doctype html>
<html lang="ja">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?= h($project['title']) ?> コメントシート | WebPatch</title>
    <link rel="icon" type="image/svg+xml" href="<?= h(base_url('favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= h(base_url('styles.css')) ?>">
    <style>
      body { margin: 0; overflow: hidden; background: #f5f7fb; color: #20242c; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
      .sheet-app { display: grid; grid-template-rows: 58px 1fr 46px; height: 100vh; }
      .sheet-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; border-bottom: 1px solid #dce2ec; background: #fff; padding: 0 18px; }
      .sheet-title { min-width: 0; }
      .sheet-title strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 15px; font-weight: 600; }
      .sheet-title span { color: #687386; font-size: 12px; font-weight: 400; }
      .sheet-actions { display: inline-flex; align-items: center; gap: 12px; margin-left: auto; }
      .sheet-help { color: #687386; font-size: 12px; font-weight: 400; }
      .sheet-download-button { display: inline-flex; align-items: center; justify-content: center; gap: 7px; height: 34px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #2563eb; cursor: pointer; font: 600 13px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; padding: 0 12px; }
      .sheet-download-button:hover, .sheet-download-button:focus-visible { border-color: #2563eb; background: #eef4ff; outline: none; }
      .sheet-download-button svg { width: 16px; height: 16px; }
      .sheet-api-modal-backdrop { position: fixed; inset: 0; z-index: 20; display: grid; place-items: center; background: rgba(15, 23, 42, .42); padding: 20px; }
      .sheet-api-modal-backdrop[hidden] { display: none; }
      .sheet-api-modal { width: min(720px, 100%); border: 1px solid #d5dce8; border-radius: 12px; background: #fff; box-shadow: 0 24px 70px rgba(15, 23, 42, .22); padding: 22px; }
      .sheet-api-modal-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
      .sheet-api-modal h2 { margin: 0 0 6px; color: #20242c; font-size: 20px; font-weight: 600; }
      .sheet-api-modal p { margin: 0; color: #687386; font-size: 13px; font-weight: 400; line-height: 1.7; }
      .sheet-api-close { display: inline-grid; place-items: center; width: 34px; height: 34px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #64748b; cursor: pointer; font-size: 20px; line-height: 1; }
      .sheet-api-grid { display: grid; gap: 12px; }
      .sheet-api-field label { display: block; margin-bottom: 6px; color: #20242c; font-size: 13px; font-weight: 600; }
      .sheet-api-field input, .sheet-api-field textarea { width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 8px; background: #f8fafc; color: #20242c; font: 400 13px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; padding: 10px 11px; }
      .sheet-api-field textarea { min-height: 94px; resize: vertical; }
      .sheet-api-example-tabs { display: inline-flex; gap: 6px; margin: 0 0 8px; border: 1px solid #dbe3ef; border-radius: 9px; background: #f8fafc; padding: 4px; }
      .sheet-api-example-tab { min-height: 30px; border: 0; border-radius: 7px; background: transparent; color: #64748b; cursor: pointer; font: 600 13px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; padding: 0 11px; }
      .sheet-api-example-tab.active { background: #fff; color: #2563eb; box-shadow: 0 1px 3px rgba(15, 23, 42, .08); }
      .sheet-api-status { border-radius: 8px; background: #f8fafc; color: #475569; font-size: 13px; font-weight: 400; line-height: 1.6; padding: 10px 12px; }
      .sheet-api-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; margin-top: 18px; }
      .sheet-api-secondary, .sheet-api-primary, .sheet-api-danger { height: 36px; border-radius: 8px; cursor: pointer; font: 600 13px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; padding: 0 13px; }
      .sheet-api-secondary { border: 1px solid #cbd5e1; background: #fff; color: #334155; }
      .sheet-api-primary { border: 1px solid #2563eb; background: #2563eb; color: #fff; }
      .sheet-api-danger { border: 1px solid #dc2626; background: #dc2626; color: #fff; }
      .sheet-api-token-note { color: #b45309; font-size: 12px; font-weight: 600; line-height: 1.6; }
      .sheet-canvas-wrap { position: relative; min-width: 0; min-height: 0; overflow: auto; overscroll-behavior: contain; }
      #sheet-canvas { display: block; max-width: none; width: 100%; height: 100%; background: #fff; }
      .sheet-tabs { display: flex; align-items: center; gap: 6px; overflow-x: auto; border-top: 1px solid #dce2ec; background: #fff; padding: 6px 10px; }
      .sheet-tab { flex: 0 0 auto; border: 1px solid #d7deea; border-radius: 7px; background: #fff; color: #586277; cursor: pointer; font-size: 13px; font-weight: 600; height: 32px; padding: 0 12px; }
      .sheet-tab.active { border-color: #2563eb; background: #eef4ff; color: #2563eb; }
      .sheet-editor { position: absolute; z-index: 5; display: none; border: 2px solid #2563eb; border-radius: 4px; background: #fff; font: 400 14px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; padding: 0 8px; }
      .sheet-select-editor { cursor: pointer; font-weight: 600; }
      .sheet-body-editor { min-height: 54px; padding: 8px; line-height: 1.55; resize: none; }
      .sheet-context-menu { position: fixed; z-index: 15; display: grid; min-width: 190px; border: 1px solid #d5dce8; border-radius: 10px; background: #fff; box-shadow: 0 18px 46px rgba(15, 23, 42, .18); padding: 6px; }
      .sheet-context-menu[hidden] { display: none; }
      .sheet-context-menu button { display: flex; align-items: center; width: 100%; min-height: 34px; border: 0; border-radius: 7px; background: transparent; color: #20242c; cursor: pointer; font: 600 13px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; padding: 0 10px; text-align: left; }
      .sheet-context-menu button:hover, .sheet-context-menu button:focus-visible { background: #eef4ff; color: #2563eb; outline: none; }
      .sheet-toast { position: fixed; right: 18px; bottom: 58px; z-index: 10; transform: translateY(12px); opacity: 0; border: 1px solid #cbd5e1; border-radius: 9px; background: #fff; box-shadow: 0 14px 36px rgba(15, 23, 42, .16); color: #20242c; font-size: 13px; font-weight: 600; padding: 10px 12px; transition: opacity .18s ease, transform .18s ease; }
      .sheet-toast.show { transform: translateY(0); opacity: 1; }
    </style>
  </head>
  <body>
    <div class="sheet-app">
      <header class="sheet-header">
        <div class="sheet-title">
          <strong><?= h($project['title']) ?> コメントシート</strong>
          <span>ページ別にコメントの対応状況を確認できます。</span>
        </div>
        <div class="sheet-actions">
          <div class="sheet-help">ステータスはクリックで選択、希望完了日時はクリックで編集</div>
          <button class="sheet-download-button" id="csv-download-button" type="button">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.5v9.2m0 0 3.55-3.55M12 13.7l-3.55-3.55M5.5 17.8v.7a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-.7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span>CSVダウンロード（返信含む）</span>
          </button>
          <?php if ($canManageApi): ?>
          <button class="sheet-download-button" id="api-reference-download-button" type="button">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 5.25A2.25 2.25 0 0 1 7.75 3h10.75v16.5H7.75A2.25 2.25 0 0 1 5.5 17.25v-12Zm2.25 14.25A2.25 2.25 0 0 0 5.5 21.75M8.5 7h6.75M8.5 10.25h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span>APIリファレンス</span>
          </button>
          <button class="sheet-download-button" id="api-button" type="button">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 8.75h-1A3.25 3.25 0 0 0 4.25 12v0a3.25 3.25 0 0 0 3.25 3.25h1m7-6.5h1A3.25 3.25 0 0 1 19.75 12v0a3.25 3.25 0 0 1-3.25 3.25h-1M8.75 12h6.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            <span>API</span>
          </button>
          <?php endif; ?>
        </div>
      </header>
      <main class="sheet-canvas-wrap">
        <canvas id="sheet-canvas"></canvas>
        <input class="sheet-editor" id="due-editor" type="datetime-local">
        <select class="sheet-editor sheet-select-editor" id="status-editor" aria-label="ステータス">
          <option value="todo">未着手</option>
          <option value="doing">対応中</option>
          <option value="pending">確認待ち</option>
          <option value="done">解決済み</option>
        </select>
        <textarea class="sheet-editor sheet-body-editor" id="body-editor" aria-label="コメント内容"></textarea>
      </main>
      <nav class="sheet-tabs" id="sheet-tabs" aria-label="ページ別シート"></nav>
    </div>
    <div class="sheet-context-menu" id="sheet-context-menu" hidden>
      <button type="button" id="copy-edit-prompt-button">編集プロンプトコピー</button>
    </div>
    <div class="sheet-toast" id="sheet-toast" role="status" aria-live="polite"></div>
    <?php if ($canManageApi): ?>
    <div class="sheet-api-modal-backdrop" id="api-modal" hidden>
      <section class="sheet-api-modal" role="dialog" aria-modal="true" aria-labelledby="api-modal-title">
        <div class="sheet-api-modal-header">
          <div>
            <h2 id="api-modal-title">コメントシートAPI</h2>
            <p>ローカルのAIエージェントから、このシートのコメント取得・ステータス更新・希望完了日時更新・コメント本文更新ができます。</p>
          </div>
          <button class="sheet-api-close" id="api-close-button" type="button" aria-label="閉じる">×</button>
        </div>
        <div class="sheet-api-grid">
          <div class="sheet-api-status" id="api-status"></div>
          <div class="sheet-api-field">
            <label for="api-endpoint">API URL</label>
            <input id="api-endpoint" type="text" readonly>
          </div>
          <div class="sheet-api-field">
            <label for="api-token">APIトークン</label>
            <input id="api-token" type="text" readonly placeholder="発行または再発行するとここに一度だけ表示されます">
            <div class="sheet-api-token-note">トークンはこの画面で一度だけ表示されます。閉じた後に必要な場合は再発行してください。</div>
          </div>
          <div class="sheet-api-field">
            <label for="api-example">利用例</label>
            <div class="sheet-api-example-tabs" role="tablist" aria-label="API利用例">
              <button class="sheet-api-example-tab active" type="button" data-api-example-tab="curl" aria-pressed="true">curl</button>
              <button class="sheet-api-example-tab" type="button" data-api-example-tab="python" aria-pressed="false">Python</button>
              <button class="sheet-api-example-tab" type="button" data-api-example-tab="js" aria-pressed="false">JS</button>
              <button class="sheet-api-example-tab" type="button" data-api-example-tab="php" aria-pressed="false">PHP</button>
            </div>
            <textarea id="api-example" readonly></textarea>
          </div>
        </div>
        <div class="sheet-api-actions">
          <button class="sheet-api-secondary" id="api-copy-button" type="button">コピー</button>
          <button class="sheet-api-danger" id="api-disable-button" type="button">無効化</button>
          <button class="sheet-api-primary" id="api-issue-button" type="button">発行 / 再発行</button>
        </div>
      </section>
    </div>
    <?php endif; ?>
    <script>
      (() => {
        const sheetData = <?= json_encode($sheetData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const canvas = document.getElementById('sheet-canvas');
        const ctx = canvas.getContext('2d');
        const wrap = canvas.parentElement;
        const tabs = document.getElementById('sheet-tabs');
        const dueEditor = document.getElementById('due-editor');
        const statusEditor = document.getElementById('status-editor');
        const bodyEditor = document.getElementById('body-editor');
        const contextMenu = document.getElementById('sheet-context-menu');
        const copyEditPromptButton = document.getElementById('copy-edit-prompt-button');
        const csvDownloadButton = document.getElementById('csv-download-button');
        const apiReferenceDownloadButton = document.getElementById('api-reference-download-button');
        const apiButton = document.getElementById('api-button');
        const apiModal = document.getElementById('api-modal');
        const apiCloseButton = document.getElementById('api-close-button');
        const apiIssueButton = document.getElementById('api-issue-button');
        const apiDisableButton = document.getElementById('api-disable-button');
        const apiCopyButton = document.getElementById('api-copy-button');
        const apiStatus = document.getElementById('api-status');
        const apiEndpoint = document.getElementById('api-endpoint');
        const apiToken = document.getElementById('api-token');
        const apiExample = document.getElementById('api-example');
        const apiExampleTabs = Array.from(document.querySelectorAll('[data-api-example-tab]'));
        const toast = document.getElementById('sheet-toast');
        const statuses = [
          { key: 'todo', label: '未着手', color: '#64748b', bg: '#f1f5f9' },
          { key: 'doing', label: '対応中', color: '#b45309', bg: '#fff7ed' },
          { key: 'pending', label: '確認待ち', color: '#047857', bg: '#ecfdf5' },
          { key: 'done', label: '解決済み', color: '#047857', bg: '#ecfdf5' }
        ];
        const aiStatuses = [
          { key: 'unchecked', label: '未確認', color: '#64748b', bg: '#f1f5f9' },
          { key: 'not_applicable', label: '対象外', color: '#475569', bg: '#f8fafc' },
          { key: 'reflected', label: '反映済み', color: '#047857', bg: '#ecfdf5' },
          { key: 'not_reflected', label: '未反映', color: '#b91c1c', bg: '#fef2f2' },
          { key: 'uncertain', label: '不明', color: '#b45309', bg: '#fff7ed' },
          { key: 'error', label: 'エラー', color: '#be123c', bg: '#fff1f2' }
        ];
        const columns = [
          { key: 'selector', label: 'コメントの位置', width: 330 },
          { key: 'body', label: 'コメント内容', width: 520 },
          { key: 'replies', label: '返信', width: 520 },
          { key: 'status', label: 'ステータス', width: 140 },
          { key: 'due', label: '希望完了日時', width: 190 },
          { key: 'ai_status', label: 'AI確認', width: 140 },
          { key: 'ai_summary', label: 'AI要約', width: 420 },
          { key: 'ai_checked_at', label: 'AI確認日時', width: 180 },
          { key: 'response_prompt', label: '対応プロンプト', width: 620 }
        ];
        const commentCountByFile = new Map();
        sheetData.comments.forEach((comment) => {
          const file = comment.file_path || '';
          if (file) {
            commentCountByFile.set(file, (commentCountByFile.get(file) || 0) + 1);
          }
        });
        const tabFiles = Array.from(commentCountByFile.keys()).filter(Boolean);
        const visibleTabFiles = tabFiles.length > 0 ? tabFiles : sheetData.files;
        let activeFile = visibleTabFiles[0] || '';
        let scrollY = 0;
        let hitCells = [];
        let toastTimer = null;
        let dueEditState = null;
        let statusEditState = null;
        let bodyEditState = null;
        let contextCell = null;

        const tableWidth = () => 46 + columns.reduce((sum, col) => sum + col.width, 0);
        const statusByKey = (key) => statuses.find((item) => item.key === key) || statuses[0];
        const aiStatusByKey = (key) => aiStatuses.find((item) => item.key === key) || aiStatuses[0];
        const commentsForFile = () => sheetData.comments.filter((comment) => comment.file_path === activeFile);
        const showToast = (message) => {
          window.clearTimeout(toastTimer);
          toast.textContent = message;
          toast.classList.add('show');
          toastTimer = window.setTimeout(() => toast.classList.remove('show'), 2200);
        };
        const hideContextMenu = () => {
          contextMenu.hidden = true;
          contextCell = null;
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
        const commentClipboardText = (comment) => {
          const file = comment.file_path || activeFile;
          const target = `${sheetData.file_copy_targets[file] || file} の ${comment.selector || ''}`;
          const attachmentLines = Array.isArray(comment.attachment_paths)
            ? comment.attachment_paths.map((path) => String(path || '').trim()).filter(Boolean).map((path) => `#添付 ${path}`)
            : [];
          const baseText = [`#対象 : ${target}`, `#コメント : ${comment.body || ''}`, ...attachmentLines].join('\n');
          const prompt = String(sheetData.copy_prompt || '').trim();
          return prompt ? `${baseText}\n\n${prompt}` : baseText;
        };
        const replyTextValue = (comment) => {
          const replies = Array.isArray(comment.replies) ? comment.replies : [];
          return replies.map((reply, index) => {
            const createdAt = reply.created_at ? String(reply.created_at).replace('T', ' ').slice(0, 16) : '';
            const meta = [reply.user_name || 'ゲスト', createdAt].filter(Boolean).join(' / ');
            const attachmentLines = Array.isArray(reply.attachment_paths)
              ? reply.attachment_paths.map((path) => String(path || '').trim()).filter(Boolean).map((path) => `#添付 ${path}`)
              : [];
            return [`${index + 1}. ${meta}`, reply.body || '', ...attachmentLines].filter(Boolean).join('\n');
          }).join('\n\n');
        };
        const csvEscape = (value) => {
          const text = String(value ?? '');
          return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
        };
        const downloadCsv = async () => {
          await commitOpenEditors();
          const header = ['ページ', 'コメントの位置', 'コメント内容', '返信コメント', 'ステータス', '希望完了日時', 'AI確認', 'AI要約', 'AI確認日時', 'AIプロバイダ', 'AIモデル', '投稿者', '作成日時', '対応プロンプト'];
          const rows = sheetData.comments.map((comment) => [
            comment.file_path || '',
            comment.selector || '',
            comment.body || '',
            replyTextValue(comment),
            statusByKey(comment.sheet_status).label,
            formatDue(comment.desired_due_at),
            aiStatusByKey(comment.ai_check_status).label,
            comment.ai_check_summary || '',
            formatDue(comment.ai_checked_at),
            comment.ai_check_provider || '',
            comment.ai_check_model || '',
            comment.user_name || '',
            formatDue(comment.created_at),
            commentClipboardText(comment)
          ]);
          const csv = [header, ...rows].map((row) => row.map(csvEscape).join(',')).join('\r\n');
          const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' });
          const url = URL.createObjectURL(blob);
          const link = document.createElement('a');
          const title = String(sheetData.project.title || 'webpatch').replace(/[\\/:*?"<>|]+/g, '_');
          link.href = url;
          link.download = `${title}_comments.csv`;
          document.body.append(link);
          link.click();
          link.remove();
          URL.revokeObjectURL(url);
          showToast('CSVをダウンロードしました。');
        };
        const apiReferenceMarkdown = () => {
          const endpoint = sheetData.api.endpoint_url;
          const projectTitle = sheetData.project.title || 'WebPatch Project';
          const generatedAt = new Date().toISOString();
          return [
            `# WebPatch コメントシートAPI リファレンス`,
            '',
            `対象プロジェクト: ${projectTitle}`,
            `生成日時: ${generatedAt}`,
            '',
            '## 概要',
            '',
            'WebPatchのコメントシートを外部ツールやローカルAIエージェントから取得・更新するためのAPIです。',
            'APIトークンはコメントシート画面の「API」ボタンから発行します。',
            '',
            '## エンドポイント',
            '',
            '```text',
            endpoint,
            '```',
            '',
            '## 認証',
            '',
            'HTTPヘッダーにAPIトークンを指定します。Basic認証と併用する場合は `X-WebPatch-API-Token` を推奨します。',
            '',
            '```http',
            'X-WebPatch-API-Token: <API_TOKEN>',
            '```',
            '',
            '`Authorization: Bearer` でも認証できます。',
            '',
            '```http',
            'Authorization: Bearer <API_TOKEN>',
            '```',
            '',
            'クエリパラメータ `api_token` でも認証できますが、URLログに残りやすいため推奨しません。',
            '',
            '### Basic認証と併用する場合',
            '',
            '```bash',
            `curl -sS -u BASIC_ID:BASIC_PASSWORD -H "X-WebPatch-API-Token: <API_TOKEN>" "${endpoint}?status=todo&fields=id,response_prompt"`,
            '```',
            '',
            '## GET コメント一覧取得',
            '',
            '```bash',
            `curl -sS -H "X-WebPatch-API-Token: <API_TOKEN>" "${endpoint}"`,
            '```',
            '',
            '### GET クエリパラメータ',
            '',
            '| パラメータ | 値 | 説明 |',
            '| --- | --- | --- |',
            '| `status` | `todo`, `doing`, `pending`, `done` | 指定したステータスだけ取得。カンマ区切りで複数指定可。 |',
            '| `fields` | `id`, `file_path`, `selector`, `body`, `replies`, `reply_count`, `reply_text`, `sheet_status`, `status_label`, `desired_due_at`, `attachments`, `attachment_paths`, `response_prompt` など | 返却フィールドを限定。カンマ区切りで複数指定可。 |',
            '',
            '### 未着手の対応プロンプトだけ取得',
            '',
            '```bash',
            `curl -sS -H "X-WebPatch-API-Token: <API_TOKEN>" "${endpoint}?status=todo&fields=id,response_prompt"`,
            '```',
            '',
            '## PATCH / POST コメント更新',
            '',
            '単一コメント更新:',
            '',
            '```bash',
            `curl -sS -X PATCH -H "X-WebPatch-API-Token: <API_TOKEN>" -H "Content-Type: application/json" \\`,
            `  -d '{"comment_id":123,"sheet_status":"doing","desired_due_at":"2026-05-31T18:00"}' \\`,
            `  "${endpoint}"`,
            '```',
            '',
            '複数コメント更新:',
            '',
            '```json',
            '{',
            '  "updates": [',
            '    { "comment_id": 123, "sheet_status": "doing" },',
            '    { "comment_id": 124, "sheet_status": "done" }',
            '  ]',
            '}',
            '```',
            '',
            '### 更新可能フィールド',
            '',
            '| フィールド | 値 | 説明 |',
            '| --- | --- | --- |',
            '| `comment_id` | 数値 | 更新対象コメントID。必須。 |',
            '| `sheet_status` | `todo`, `doing`, `pending`, `done` | ステータス。`done` は解決済みと連動します。 |',
            '| `desired_due_at` | `YYYY-MM-DDTHH:mm` または空文字 | 希望完了日時。空文字でクリア。 |',
            '| `body` | 文字列 | コメント本文。更新するとAI確認状態は未確認に戻ります。 |',
            '',
            '## ステータス',
            '',
            '| API値 | 表示名 |',
            '| --- | --- |',
            '| `todo` | 未着手 |',
            '| `doing` | 対応中 |',
            '| `pending` | 確認待ち |',
            '| `done` | 解決済み |',
            '',
            '## レスポンス主要フィールド',
            '',
            '| フィールド | 説明 |',
            '| --- | --- |',
            '| `id` | コメントID |',
            '| `file_path` | 対象ページパス |',
            '| `selector` | コメント対象DOMのselector |',
            '| `comment_position` | ページパスとselectorを結合した位置情報 |',
            '| `body` | コメント内容 |',
            '| `replies` | 返信の配列。投稿者、日時、本文、添付情報を含みます。 |',
            '| `reply_count` | 返信件数 |',
            '| `reply_text` | 返信を投稿順に整形したテキスト |',
            '| `sheet_status` / `status` | ステータスAPI値 |',
            '| `status_label` | 日本語ステータス |',
            '| `desired_due_at` | 希望完了日時 |',
            '| `attachments` | 添付ファイル情報。`path` / `url` に添付画像URLが入ります。 |',
            '| `attachment_paths` | 添付画像URLの配列。 |',
            '| `response_prompt` | AIエージェント向け対応プロンプト。添付がある場合は `#添付 [パス]` 行が入り、追加プロンプト設定があれば末尾に付与されます。 |',
            '| `ai_check_status` | AI確認ステータス |',
            '| `ai_check_summary` | AI確認要約 |',
            '',
            '添付URLをAPIトークンで取得する場合は、画像リクエストにも `X-WebPatch-API-Token` を付けてください。',
            '',
            '## Python例',
            '',
            '```python',
            'import requests',
            '',
            `endpoint = ${JSON.stringify(endpoint)}`,
            'token = "<API_TOKEN>"',
            'headers = {"X-WebPatch-API-Token": token}',
            '',
            'todo_prompts = requests.get(',
            '    endpoint,',
            '    headers=headers,',
            '    params={"status": "todo", "fields": "id,response_prompt"},',
            '    timeout=20,',
            ').json()',
            'print(todo_prompts["comments"])',
            '```',
            '',
            '## JavaScript例',
            '',
            '```js',
            `const endpoint = ${JSON.stringify(endpoint)};`,
            'const token = "<API_TOKEN>";',
            'const url = new URL(endpoint);',
            'url.searchParams.set("status", "todo");',
            'url.searchParams.set("fields", "id,response_prompt");',
            '',
            'const data = await fetch(url, {',
            '  headers: { "X-WebPatch-API-Token": token }',
            '}).then((res) => res.json());',
            '',
            'console.log(data.comments);',
            '```',
            '',
            '## PHP例',
            '',
            '```php',
            '<?php',
            `$endpoint = ${JSON.stringify(endpoint)};`,
            '$token = "<API_TOKEN>";',
            '$url = $endpoint . "?status=todo&fields=id,response_prompt";',
            '$context = stream_context_create([',
            '    "http" => [',
            '        "header" => "X-WebPatch-API-Token: {$token}\\r\\n",',
            '        "ignore_errors" => true,',
            '    ],',
            ']);',
            '$data = json_decode((string) file_get_contents($url, false, $context), true);',
            'print_r($data["comments"] ?? []);',
            '```',
            ''
          ].join('\n');
        };
        const downloadApiReference = () => {
          const blob = new Blob([apiReferenceMarkdown()], { type: 'text/markdown;charset=utf-8' });
          const url = URL.createObjectURL(blob);
          const link = document.createElement('a');
          const title = String(sheetData.project.title || 'webpatch').replace(/[\\/:*?"<>|]+/g, '_');
          link.href = url;
          link.download = `${title}_comment_sheet_api_reference.md`;
          document.body.append(link);
          link.click();
          link.remove();
          URL.revokeObjectURL(url);
          showToast('APIリファレンスをダウンロードしました。');
        };
        const apiCurlExample = (token = '<API_TOKEN>') => {
          const endpoint = sheetData.api.endpoint_url;
          const promptEndpoint = `${endpoint}?status=todo&fields=response_prompt`;
          return [
            `curl -sS -H "X-WebPatch-API-Token: ${token}" "${endpoint}"`,
            '',
            `curl -sS -H "X-WebPatch-API-Token: ${token}" "${promptEndpoint}"`,
            '',
            `curl -sS -X PATCH -H "X-WebPatch-API-Token: ${token}" -H "Content-Type: application/json" \\`,
            `  -d '{"comment_id":123,"sheet_status":"doing","desired_due_at":"2026-05-31T18:00"}' \\`,
            `  "${endpoint}"`
          ].join('\n');
        };
        const apiPythonExample = (token = '<API_TOKEN>') => {
          const endpoint = sheetData.api.endpoint_url;
          return [
            'import requests',
            '',
            `endpoint = ${JSON.stringify(endpoint)}`,
            `token = ${JSON.stringify(token)}`,
            '',
            'headers = {"X-WebPatch-API-Token": token}',
            'comments = requests.get(endpoint, headers=headers, timeout=20).json()',
            'print(comments)',
            '',
            'todo_prompts = requests.get(',
            '    endpoint,',
            '    headers=headers,',
            '    params={"status": "todo", "fields": "response_prompt"},',
            '    timeout=20,',
            ').json()',
            'print(todo_prompts["comments"])',
            '',
            'payload = {',
            '    "comment_id": 123,',
            '    "sheet_status": "doing",',
            '    "desired_due_at": "2026-05-31T18:00",',
            '}',
            'updated = requests.patch(endpoint, headers={**headers, "Content-Type": "application/json"}, json=payload, timeout=20).json()',
            'print(updated)'
          ].join('\n');
        };
        const apiJsExample = (token = '<API_TOKEN>') => {
          const endpoint = sheetData.api.endpoint_url;
          return [
            `const endpoint = ${JSON.stringify(endpoint)};`,
            `const token = ${JSON.stringify(token)};`,
            '',
            'const headers = { "X-WebPatch-API-Token": token };',
            'const comments = await fetch(endpoint, { headers }).then((res) => res.json());',
            'console.log(comments);',
            '',
            'const todoPromptsUrl = new URL(endpoint);',
            'todoPromptsUrl.searchParams.set("status", "todo");',
            'todoPromptsUrl.searchParams.set("fields", "response_prompt");',
            'const todoPrompts = await fetch(todoPromptsUrl, { headers }).then((res) => res.json());',
            'console.log(todoPrompts.comments);',
            '',
            'const updated = await fetch(endpoint, {',
            '  method: "PATCH",',
            '  headers: { ...headers, "Content-Type": "application/json" },',
            '  body: JSON.stringify({',
            '    comment_id: 123,',
            '    sheet_status: "doing",',
            '    desired_due_at: "2026-05-31T18:00"',
            '  })',
            '}).then((res) => res.json());',
            'console.log(updated);'
          ].join('\n');
        };
        const apiPhpExample = (token = '<API_TOKEN>') => {
          const endpoint = sheetData.api.endpoint_url;
          return [
            '<?php',
            `$endpoint = ${JSON.stringify(endpoint)};`,
            `$token = ${JSON.stringify(token)};`,
            '',
            'function webpatch_api(string $method, string $endpoint, string $token, ?array $payload = null): array {',
            '    $context = [',
            '        "http" => [',
            '            "method" => $method,',
            '            "header" => "X-WebPatch-API-Token: {$token}\\r\\nContent-Type: application/json\\r\\n",',
            '            "ignore_errors" => true,',
            '        ],',
            '    ];',
            '    if ($payload !== null) {',
            '        $context["http"]["content"] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);',
            '    }',
            '    $json = file_get_contents($endpoint, false, stream_context_create($context));',
            '    return json_decode((string) $json, true) ?: [];',
            '}',
            '',
            '$comments = webpatch_api("GET", $endpoint, $token);',
            'print_r($comments);',
            '',
            '$todoPrompts = webpatch_api("GET", $endpoint . "?status=todo&fields=response_prompt", $token);',
            'print_r($todoPrompts["comments"] ?? []);',
            '',
            '$updated = webpatch_api("PATCH", $endpoint, $token, [',
            '    "comment_id" => 123,',
            '    "sheet_status" => "doing",',
            '    "desired_due_at" => "2026-05-31T18:00",',
            ']);',
            'print_r($updated);'
          ].join('\n');
        };
        const apiExamples = {
          curl: apiCurlExample,
          python: apiPythonExample,
          js: apiJsExample,
          php: apiPhpExample
        };
        let activeApiExample = 'curl';
        const setApiExampleTab = (name) => {
          activeApiExample = apiExamples[name] ? name : 'curl';
          apiExampleTabs.forEach((tab) => {
            const isActive = tab.dataset.apiExampleTab === activeApiExample;
            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-pressed', isActive ? 'true' : 'false');
          });
          const token = apiToken && apiToken.value ? apiToken.value : '<API_TOKEN>';
          apiExample.value = apiExamples[activeApiExample](token);
        };
        const renderApiModal = (token = '') => {
          if (!sheetData.api || !sheetData.api.can_manage || !apiModal) {
            return;
          }
          apiEndpoint.value = sheetData.api.endpoint_url;
          apiToken.value = token;
          setApiExampleTab(activeApiExample);
          apiStatus.textContent = sheetData.api.enabled
            ? `APIは有効です。キー: ${sheetData.api.token_prefix || '発行済み'} / 最終利用: ${sheetData.api.last_used_at || '未使用'}`
            : 'APIは未発行または無効です。';
          apiDisableButton.disabled = !sheetData.api.enabled;
        };
        const openApiModal = () => {
          renderApiModal();
          apiModal.hidden = false;
        };
        const closeApiModal = () => {
          if (apiModal) {
            apiModal.hidden = true;
          }
        };
        const postApiTokenAction = async (action) => {
          const response = await fetch(sheetData.api.manage_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
              csrf_token: sheetData.csrf_token,
              project_ref: sheetData.api.project_ref,
              action
            })
          });
          const result = await response.json();
          if (!response.ok || !result.ok) {
            throw new Error(result.message || 'API設定を更新できませんでした。');
          }
          sheetData.api.enabled = Boolean(result.enabled);
          sheetData.api.token_prefix = result.token_prefix || '';
          sheetData.api.last_used_at = result.last_used_at || null;
          sheetData.api.created_at = result.created_at || null;
          renderApiModal(result.token || '');
          return result;
        };
        const copyApiInfo = async () => {
          const text = [
            `API URL: ${apiEndpoint.value}`,
            `API Token: ${apiToken.value || '<API_TOKEN>'}`,
            '',
            apiExample.value
          ].join('\n');
          await navigator.clipboard.writeText(text);
          showToast('API情報をコピーしました。');
        };
        const saveComment = async (comment, patch) => {
          const response = await fetch(sheetData.update_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ csrf_token: sheetData.csrf_token, comment_id: comment.id, ...patch })
          });
          const result = await response.json();
          if (!response.ok || !result.ok) throw new Error(result.message || '保存できませんでした。');
          showToast('保存しました。');
        };
        const wrapText = (text, maxWidth) => {
          const lines = [];
          String(text || '').split(/\r?\n/).forEach((paragraph) => {
            const chars = paragraph.split('');
            let line = '';
            for (const char of chars) {
              const next = line + char;
              if (ctx.measureText(next).width > maxWidth && line) {
                lines.push(line);
                line = char;
                continue;
              }
              line = next;
            }
            lines.push(line);
          });
          return lines;
        };
        const rowTextValue = (comment, key) => {
          if (key === 'selector') return comment.selector || '';
          if (key === 'body') return comment.body || '';
          if (key === 'replies') return replyTextValue(comment);
          if (key === 'due') return formatDue(comment.desired_due_at);
          if (key === 'ai_summary') return comment.ai_check_summary || '';
          if (key === 'ai_checked_at') return formatDue(comment.ai_checked_at);
          if (key === 'response_prompt') return commentClipboardText(comment);
          return '';
        };
        const rowMetrics = (rows) => {
          ctx.font = '400 14px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
          let offset = 0;
          return rows.map((comment) => {
            const maxLines = columns.reduce((max, col) => {
              if (col.key === 'status') return max;
              const lines = wrapText(rowTextValue(comment, col.key) || '未設定', col.width - 24).length;
              return Math.max(max, lines);
            }, 1);
            const height = Math.max(74, 34 + maxLines * 19);
            const metric = { y: offset, height, maxLines };
            offset += height;
            return metric;
          });
        };
        const rowsTotalHeight = (metrics) => metrics.reduce((sum, metric) => sum + metric.height, 0);
        const formatDue = (value) => {
          if (!value) return '';
          return String(value).replace('T', ' ').slice(0, 16);
        };
        const datetimeLocalValue = (value) => {
          if (!value) return '';
          return String(value).replace(' ', 'T').slice(0, 16);
        };
        const hideDueEditor = () => {
          dueEditor.style.display = 'none';
          dueEditor.onchange = null;
          dueEditState = null;
        };
        const hideStatusEditor = () => {
          statusEditor.style.display = 'none';
          statusEditor.onchange = null;
          statusEditState = null;
        };
        const hideBodyEditor = () => {
          bodyEditor.style.display = 'none';
          bodyEditor.onblur = null;
          bodyEditState = null;
        };
        const commitDueEditor = async () => {
          if (!dueEditState || dueEditor.style.display === 'none') {
            return;
          }
          const state = dueEditState;
          const previous = state.comment.desired_due_at;
          const nextValue = dueEditor.value;
          const nextStored = nextValue ? nextValue.replace('T', ' ') + ':00' : '';
          hideDueEditor();
          if ((previous || '') === nextStored) {
            draw();
            return;
          }
          state.comment.desired_due_at = nextStored;
          draw();
          try {
            await saveComment(state.comment, { desired_due_at: nextValue });
          } catch (error) {
            state.comment.desired_due_at = previous;
            draw();
            showToast(error.message || '保存できませんでした。');
          }
        };
        const commitStatusEditor = async () => {
          if (!statusEditState || statusEditor.style.display === 'none') {
            return;
          }
          const state = statusEditState;
          const previous = state.comment.sheet_status;
          const nextValue = statusEditor.value;
          hideStatusEditor();
          if ((previous || 'todo') === nextValue) {
            draw();
            return;
          }
          state.comment.sheet_status = nextValue;
          draw();
          try {
            await saveComment(state.comment, { sheet_status: nextValue });
          } catch (error) {
            state.comment.sheet_status = previous;
            draw();
            showToast(error.message || '保存できませんでした。');
          }
        };
        const commitBodyEditor = async () => {
          if (!bodyEditState || bodyEditor.style.display === 'none') {
            return;
          }
          const state = bodyEditState;
          const previous = state.comment.body || '';
          const nextValue = bodyEditor.value.trim();
          hideBodyEditor();
          if (nextValue === '') {
            showToast('コメント内容は空にできません。');
            draw();
            return;
          }
          if (previous === nextValue) {
            draw();
            return;
          }
          state.comment.body = nextValue;
          draw();
          try {
            await saveComment(state.comment, { body: nextValue });
          } catch (error) {
            state.comment.body = previous;
            draw();
            showToast(error.message || '保存できませんでした。');
          }
        };
        const commitOpenEditors = async () => {
          await commitBodyEditor();
          await commitDueEditor();
          await commitStatusEditor();
        };
        const resize = () => {
          const ratio = window.devicePixelRatio || 1;
          const rect = wrap.getBoundingClientRect();
          const cssWidth = Math.max(rect.width, tableWidth());
          canvas.width = Math.floor(cssWidth * ratio);
          canvas.height = Math.floor(rect.height * ratio);
          canvas.style.width = `${cssWidth}px`;
          canvas.style.height = `${rect.height}px`;
          ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
          draw();
        };
        const draw = () => {
          const rect = wrap.getBoundingClientRect();
          const width = Math.max(rect.width, tableWidth());
          const height = rect.height;
          const headerHeight = 42;
          const rowNumberWidth = 46;
          const totalWidth = tableWidth();
          const rows = commentsForFile();
          const metrics = rowMetrics(rows);
          const maxScroll = Math.max(0, headerHeight + rowsTotalHeight(metrics) - height);
          scrollY = Math.max(0, Math.min(maxScroll, scrollY));
          hitCells = [];

          ctx.clearRect(0, 0, width, height);
          ctx.fillStyle = '#fff';
          ctx.fillRect(0, 0, width, height);
          ctx.font = '600 13px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
          ctx.textBaseline = 'middle';

          ctx.fillStyle = '#f8fafc';
          ctx.fillRect(0, 0, Math.max(width, totalWidth), headerHeight);
          ctx.strokeStyle = '#dce2ec';
          ctx.lineWidth = 1;
          ctx.beginPath();
          ctx.moveTo(0, headerHeight + .5);
          ctx.lineTo(Math.max(width, totalWidth), headerHeight + .5);
          ctx.moveTo(rowNumberWidth + .5, 0);
          ctx.lineTo(rowNumberWidth + .5, height);
          ctx.stroke();

          let x = rowNumberWidth;
          ctx.fillStyle = '#64748b';
          columns.forEach((col) => {
            ctx.fillText(col.label, x + 12, headerHeight / 2);
            ctx.strokeStyle = '#dce2ec';
            ctx.beginPath();
            ctx.moveTo(x + .5, 0);
            ctx.lineTo(x + .5, height);
            ctx.stroke();
            x += col.width;
          });
          ctx.beginPath();
          ctx.moveTo(x + .5, 0);
          ctx.lineTo(x + .5, height);
          ctx.stroke();

          if (rows.length === 0) {
            ctx.fillStyle = '#64748b';
            ctx.font = '400 14px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText('このページにはコメントがありません。', 24, 78);
            return;
          }

          ctx.save();
          ctx.beginPath();
          ctx.rect(0, headerHeight, Math.max(width, totalWidth), Math.max(0, height - headerHeight));
          ctx.clip();
          rows.forEach((comment, index) => {
            const rowHeight = metrics[index].height;
            const y = headerHeight + metrics[index].y - scrollY;
            if (y + rowHeight < headerHeight || y > height) return;
            const isResolvedRow = comment.sheet_status === 'done' || Boolean(comment.resolved_at);
            ctx.fillStyle = isResolvedRow ? '#f1f3f6' : (index % 2 === 0 ? '#fff' : '#fbfdff');
            ctx.fillRect(0, y, Math.max(width, totalWidth), rowHeight);
            ctx.strokeStyle = '#d8e0eb';
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(0, y + .5);
            ctx.lineTo(Math.max(width, totalWidth), y + .5);
            ctx.moveTo(0, y + rowHeight + .5);
            ctx.lineTo(Math.max(width, totalWidth), y + rowHeight + .5);
            ctx.stroke();
            ctx.strokeStyle = 'rgba(37, 99, 235, .08)';
            ctx.beginPath();
            ctx.moveTo(0, y + rowHeight + .5);
            ctx.lineTo(Math.max(width, totalWidth), y + rowHeight + .5);
            ctx.stroke();

            ctx.fillStyle = isResolvedRow ? '#7b8491' : '#94a3b8';
            ctx.font = '600 13px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(String(index + 1), 18, y + rowHeight / 2);

            let cellX = rowNumberWidth;
            columns.forEach((col) => {
              hitCells.push({ x: cellX, y, width: col.width, height: rowHeight, col: col.key, comment });
              ctx.save();
              ctx.beginPath();
              ctx.rect(cellX + 1, y + 1, col.width - 2, rowHeight - 2);
              ctx.clip();
              ctx.font = '400 14px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
              if (col.key === 'status') {
                const status = statusByKey(comment.sheet_status);
                ctx.fillStyle = isResolvedRow ? '#e5e7eb' : status.bg;
                ctx.fillRect(cellX + 12, y + Math.max(14, (rowHeight - 30) / 2), 92, 30);
                ctx.fillStyle = isResolvedRow ? '#4b5563' : status.color;
                ctx.font = '600 13px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
                ctx.fillText(status.label, cellX + 30, y + rowHeight / 2);
              } else if (col.key === 'ai_status') {
                const status = aiStatusByKey(comment.ai_check_status);
                ctx.fillStyle = isResolvedRow ? '#eef0f3' : status.bg;
                ctx.fillRect(cellX + 12, y + Math.max(14, (rowHeight - 30) / 2), 92, 30);
                ctx.fillStyle = isResolvedRow ? '#5f6977' : status.color;
                ctx.font = '600 13px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
                ctx.fillText(status.label, cellX + 28, y + rowHeight / 2);
              } else {
                const value = rowTextValue(comment, col.key);
                ctx.fillStyle = value ? (isResolvedRow ? '#5f6977' : '#20242c') : '#94a3b8';
                const lines = wrapText(value || '未設定', col.width - 24);
                lines.forEach((line, lineIndex) => ctx.fillText(line, cellX + 12, y + 20 + lineIndex * 19));
              }
              ctx.restore();
              ctx.strokeStyle = '#e1e7f0';
              ctx.lineWidth = 1;
              ctx.beginPath();
              ctx.moveTo(cellX + col.width + .5, y);
              ctx.lineTo(cellX + col.width + .5, y + rowHeight);
              ctx.stroke();
              cellX += col.width;
            });
          });
          ctx.restore();
        };
        const renderTabs = () => {
          tabs.replaceChildren();
          visibleTabFiles.forEach((file) => {
            const count = commentCountByFile.get(file) || 0;
            const button = document.createElement('button');
            button.className = 'sheet-tab';
            button.type = 'button';
            button.textContent = count > 0 ? `${file.split('/').pop() || file} (${count})` : (file.split('/').pop() || file);
            button.title = count > 0 ? `${file} / コメント ${count}件` : file;
            button.classList.toggle('active', file === activeFile);
            button.addEventListener('click', async () => {
              await commitOpenEditors();
              activeFile = file;
              scrollY = 0;
              wrap.scrollLeft = 0;
              hideContextMenu();
              renderTabs();
              draw();
            });
            tabs.append(button);
          });
        };
        const cellAt = (x, y) => y < 42 ? undefined : hitCells.find((cell) => x >= cell.x && x <= cell.x + cell.width && y >= cell.y && y <= cell.y + cell.height);
        canvas.addEventListener('contextmenu', (event) => {
          const rect = canvas.getBoundingClientRect();
          const cell = cellAt(event.clientX - rect.left, event.clientY - rect.top);
          if (!cell || !['body', 'response_prompt'].includes(cell.col)) {
            hideContextMenu();
            return;
          }
          event.preventDefault();
          contextCell = cell;
          contextMenu.hidden = false;
          const menuRect = contextMenu.getBoundingClientRect();
          const left = Math.min(event.clientX, window.innerWidth - menuRect.width - 8);
          const top = Math.min(event.clientY, window.innerHeight - menuRect.height - 8);
          contextMenu.style.left = `${Math.max(8, left)}px`;
          contextMenu.style.top = `${Math.max(8, top)}px`;
          copyEditPromptButton.focus();
        });
        canvas.addEventListener('dblclick', async (event) => {
          const rect = canvas.getBoundingClientRect();
          const cell = cellAt(event.clientX - rect.left, event.clientY - rect.top);
          if (!cell || cell.col !== 'body') {
            return;
          }
          await commitDueEditor();
          await commitStatusEditor();
          if (bodyEditState && bodyEditState.comment.id !== cell.comment.id) {
            await commitBodyEditor();
          }
          bodyEditor.value = cell.comment.body || '';
          bodyEditor.style.left = `${cell.x + 4}px`;
          bodyEditor.style.top = `${cell.y + 4}px`;
          bodyEditor.style.width = `${cell.width - 8}px`;
          bodyEditor.style.height = `${Math.max(58, cell.height - 8)}px`;
          bodyEditor.style.display = 'block';
          bodyEditState = cell;
          bodyEditor.focus();
          bodyEditor.select();
          bodyEditor.onblur = () => {
            commitBodyEditor();
          };
        });
        canvas.addEventListener('click', async (event) => {
          hideContextMenu();
          const rect = canvas.getBoundingClientRect();
          const cell = cellAt(event.clientX - rect.left, event.clientY - rect.top);
          if (!cell) {
            await commitOpenEditors();
            return;
          }
          if (cell.col === 'status') {
            await commitBodyEditor();
            await commitDueEditor();
            if (statusEditState && statusEditState.comment.id !== cell.comment.id) {
              await commitStatusEditor();
            }
            statusEditor.value = statusByKey(cell.comment.sheet_status).key;
            statusEditor.style.left = `${cell.x + 12}px`;
            statusEditor.style.top = `${cell.y + 21}px`;
            statusEditor.style.width = '104px';
            statusEditor.style.height = '32px';
            statusEditor.style.display = 'block';
            statusEditState = cell;
            statusEditor.focus();
            statusEditor.onchange = commitStatusEditor;
            return;
          }
          if (cell.col === 'due') {
            await commitBodyEditor();
            await commitStatusEditor();
            if (dueEditState && dueEditState.comment.id !== cell.comment.id) {
              await commitDueEditor();
            }
            dueEditor.value = datetimeLocalValue(cell.comment.desired_due_at);
            dueEditor.style.left = `${cell.x + 4}px`;
            dueEditor.style.top = `${cell.y + 18}px`;
            dueEditor.style.width = `${cell.width - 8}px`;
            dueEditor.style.height = '34px';
            dueEditor.style.display = 'block';
            dueEditState = cell;
            dueEditor.focus();
            dueEditor.onchange = commitDueEditor;
            return;
          }
          await commitOpenEditors();
        });
        canvas.addEventListener('wheel', async (event) => {
          event.preventDefault();
          hideContextMenu();
          const horizontalDelta = event.shiftKey && Math.abs(event.deltaX) < 1 ? event.deltaY : event.deltaX;
          if (horizontalDelta) {
            wrap.scrollLeft = Math.max(0, wrap.scrollLeft + horizontalDelta);
          }
          const verticalDelta = event.shiftKey ? 0 : event.deltaY;
          const rows = commentsForFile();
          const maxScroll = Math.max(0, 42 + rowsTotalHeight(rowMetrics(rows)) - wrap.getBoundingClientRect().height);
          scrollY = Math.max(0, Math.min(maxScroll, scrollY + verticalDelta));
          await commitOpenEditors();
          draw();
        }, { passive: false });
        document.addEventListener('pointerdown', (event) => {
          if (event.target instanceof Node && contextMenu.contains(event.target)) {
            return;
          }
          hideContextMenu();
          const statusHidden = statusEditor.style.display === 'none';
          const dueHidden = dueEditor.style.display === 'none';
          const bodyHidden = bodyEditor.style.display === 'none';
          if ((statusHidden && dueHidden && bodyHidden) || event.target === dueEditor || event.target === statusEditor || event.target === bodyEditor) {
            return;
          }
          if (event.target instanceof Node && tabs.contains(event.target)) {
            return;
          }
          const rect = canvas.getBoundingClientRect();
          const insideCanvas = event.clientX >= rect.left && event.clientX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom;
          if (!insideCanvas) {
            commitOpenEditors();
          }
        });
        bodyEditor.addEventListener('keydown', (event) => {
          if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
            event.preventDefault();
            bodyEditor.blur();
          }
          if (event.key === 'Escape') {
            event.preventDefault();
            hideBodyEditor();
            draw();
          }
        });
        copyEditPromptButton.addEventListener('click', async () => {
          if (!contextCell) {
            return;
          }
          try {
            await copyTextToClipboard(commentClipboardText(contextCell.comment));
            hideContextMenu();
            showToast('編集プロンプトをコピーしました。');
          } catch (error) {
            showToast('クリップボードへコピーできませんでした。');
          }
        });
        window.addEventListener('resize', hideContextMenu);
        csvDownloadButton.addEventListener('click', downloadCsv);
        if (apiReferenceDownloadButton) {
          apiReferenceDownloadButton.addEventListener('click', downloadApiReference);
        }
        if (apiButton) {
          apiButton.addEventListener('click', openApiModal);
        }
        if (apiCloseButton) {
          apiCloseButton.addEventListener('click', closeApiModal);
        }
        if (apiModal) {
          let apiBackdropPointerStarted = false;
          apiModal.addEventListener('pointerdown', (event) => {
            apiBackdropPointerStarted = event.target === apiModal;
          });
          apiModal.addEventListener('click', (event) => {
            if (apiBackdropPointerStarted && event.target === apiModal) {
              closeApiModal();
            }
            apiBackdropPointerStarted = false;
          });
        }
        apiExampleTabs.forEach((tab) => {
          tab.addEventListener('click', () => setApiExampleTab(tab.dataset.apiExampleTab || 'curl'));
        });
        if (apiIssueButton) {
          apiIssueButton.addEventListener('click', async () => {
            try {
              apiIssueButton.disabled = true;
              await postApiTokenAction('issue');
              showToast('APIトークンを発行しました。');
            } catch (error) {
              showToast(error.message || 'APIトークンを発行できませんでした。');
            } finally {
              apiIssueButton.disabled = false;
            }
          });
        }
        if (apiDisableButton) {
          apiDisableButton.addEventListener('click', async () => {
            try {
              apiDisableButton.disabled = true;
              await postApiTokenAction('disable');
              apiToken.value = '';
              showToast('APIトークンを無効化しました。');
            } catch (error) {
              showToast(error.message || 'APIトークンを無効化できませんでした。');
            } finally {
              apiDisableButton.disabled = !sheetData.api.enabled;
            }
          });
        }
        if (apiCopyButton) {
          apiCopyButton.addEventListener('click', () => {
            copyApiInfo().catch(() => showToast('コピーできませんでした。'));
          });
        }
        window.addEventListener('resize', resize);
        renderTabs();
        resize();
      })();
    </script>
  </body>
</html>
