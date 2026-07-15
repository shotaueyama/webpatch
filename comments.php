<?php

require __DIR__ . '/_app.php';

$user = require_user();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

function respond_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function comment_image_payload(array $commentIds): array
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

    $images = [];
    foreach ($stmt->fetchAll() as $row) {
        $commentId = (int) $row['comment_id'];
        $images[$commentId][] = [
            'id' => (int) $row['id'],
            'filename' => $row['original_filename'],
            'mime_type' => $row['mime_type'],
            'byte_size' => (int) $row['byte_size'],
        ];
    }
    return $images;
}

function uploaded_comment_images(): array
{
    $files = $_FILES['images'] ?? null;
    if (!is_array($files) || !isset($files['name'])) {
        return [];
    }
    $normalized = [];
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
    $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];

    foreach ($names as $index => $name) {
        $error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $normalized[] = [
            'name' => (string) $name,
            'tmp_name' => (string) ($tmpNames[$index] ?? ''),
            'error' => $error,
            'size' => (int) ($sizes[$index] ?? 0),
        ];
    }
    return $normalized;
}

function save_comment_images(int $projectId, int $commentId): void
{
    $images = uploaded_comment_images();
    if ($images === []) {
        return;
    }
    if (count($images) > WEBPATCH_MAX_COMMENT_IMAGES) {
        throw new RuntimeException('画像は一度に' . WEBPATCH_MAX_COMMENT_IMAGES . '枚までアップロードできます。');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $baseDir = storage_root() . '/comment_images/' . $projectId . '/' . $commentId;
    if (!is_dir($baseDir) && !mkdir($baseDir, 0750, true) && !is_dir($baseDir)) {
        throw new RuntimeException('画像保存ディレクトリを作成できませんでした。');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($images as $image) {
        if ($image['error'] !== UPLOAD_ERR_OK || $image['tmp_name'] === '') {
            throw new RuntimeException('画像をアップロードできませんでした。');
        }
        if ($image['size'] <= 0 || $image['size'] > WEBPATCH_MAX_COMMENT_IMAGE_BYTES) {
            throw new RuntimeException('画像は1枚5MB以内にしてください。');
        }
        $mime = (string) $finfo->file($image['tmp_name']);
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('アップロードできる画像はJPEG、PNG、GIF、WebPのみです。');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        $relativePath = 'comment_images/' . $projectId . '/' . $commentId . '/' . $filename;
        $targetPath = storage_root() . '/' . $relativePath;
        if (!move_uploaded_file($image['tmp_name'], $targetPath)) {
            throw new RuntimeException('画像を保存できませんでした。');
        }
        chmod($targetPath, 0640);

        $stmt = db()->prepare(
            'INSERT INTO ' . table_name('comment_images') . ' (project_id, comment_id, storage_path, original_filename, mime_type, byte_size)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $projectId,
            $commentId,
            $relativePath,
            mb_substr(basename((string) $image['name']), 0, 255),
            $mime,
            $image['size'],
        ]);
    }
}

function comments_payload(int $projectId, ?string $file, int $userId, int $ownerId): array
{
    ensure_comment_confirmation_columns();
    ensure_comment_viewport_column();
    ensure_comment_client_share_column();
    ensure_project_client_links_table();
    ensure_comment_thread_reads_table();
    $where = 'WHERE c.project_id = ?';
    $params = [$projectId];
    if ($file !== null) {
        $where .= ' AND c.file_path = ?';
        $params[] = $file;
    }

    $stmt = db()->prepare(
        'SELECT c.id, c.file_path, c.selector, c.viewport_mode, c.body, c.parent_id, c.user_id, c.guest_name, c.client_share_id, cl.label AS client_share_label, c.resolved_at, c.confirmation_pending_at, c.created_at, u.name AS user_name
           FROM ' . table_name('comments') . ' c
           LEFT JOIN ' . table_name('users') . ' u ON u.id = c.user_id
           LEFT JOIN ' . table_name('project_client_links') . ' cl ON cl.id = c.client_share_id
          ' . $where . '
          ORDER BY COALESCE(c.parent_id, c.id) DESC, c.parent_id IS NOT NULL ASC, c.created_at ASC, c.id ASC'
    );
    $stmt->execute($params);

    $rows = $stmt->fetchAll();
    $images = comment_image_payload(array_column($rows, 'id'));
    $threads = [];
    foreach ($rows as $row) {
        $item = [
            'id' => (int) $row['id'],
            'file_path' => $row['file_path'],
            'selector' => $row['selector'],
            'viewport_mode' => normalize_comment_viewport_mode((string) ($row['viewport_mode'] ?? '')),
            'body' => $row['body'],
            'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
            'is_own' => (int) $row['user_id'] === $userId,
            'can_delete' => (int) $row['user_id'] === $userId || $ownerId === $userId,
            'can_edit' => (int) $row['user_id'] === $userId,
            'can_resolve' => $row['parent_id'] === null,
            'is_resolved' => $row['resolved_at'] !== null,
            'resolved_at' => $row['resolved_at'],
            'is_confirmation_pending' => $row['confirmation_pending_at'] !== null,
            'confirmation_pending_at' => $row['confirmation_pending_at'],
            'user_name' => $row['user_name'] ?: ($row['guest_name'] ?: 'ゲスト'),
            'client_share_id' => $row['client_share_id'] === null ? null : (int) $row['client_share_id'],
            'client_share_label' => $row['client_share_label'] ?? '',
            'created_at' => $row['created_at'],
            'images' => $images[(int) $row['id']] ?? [],
        ];

        if ($item['parent_id'] === null) {
            $item['replies'] = [];
            $threads[$item['id']] = $item;
            continue;
        }

        if (isset($threads[$item['parent_id']])) {
            $threads[$item['parent_id']]['replies'][] = $item;
        }
    }

    if ($threads !== []) {
        $threadIds = array_keys($threads);
        $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
        $readStmt = db()->prepare(
            'SELECT thread_id, last_read_at
               FROM ' . table_name('comment_thread_reads') . '
              WHERE project_id = ? AND user_id = ? AND thread_id IN (' . $placeholders . ')'
        );
        $readStmt->execute(array_merge([$projectId, $userId], $threadIds));
        $lastReadByThread = [];
        foreach ($readStmt->fetchAll() as $readRow) {
            $lastReadByThread[(int) $readRow['thread_id']] = strtotime((string) $readRow['last_read_at']) ?: 0;
        }

        foreach ($threads as $threadId => &$thread) {
            $latestOtherActivity = 0;
            $comments = array_merge([$thread], $thread['replies']);
            foreach ($comments as $comment) {
                if (!empty($comment['is_own'])) {
                    continue;
                }
                $createdAt = strtotime((string) ($comment['created_at'] ?? '')) ?: 0;
                $latestOtherActivity = max($latestOtherActivity, $createdAt);
            }
            $lastReadAt = $lastReadByThread[(int) $threadId] ?? 0;
            $thread['has_unread_activity'] = $latestOtherActivity > 0 && ($lastReadAt === 0 || $latestOtherActivity > $lastReadAt);
            $thread['latest_other_activity_at'] = $latestOtherActivity > 0 ? date('Y-m-d H:i:s', $latestOtherActivity) : null;
            $thread['last_read_at'] = $lastReadAt > 0 ? date('Y-m-d H:i:s', $lastReadAt) : null;
        }
        unset($thread);
    }

    return array_values($threads);
}

function mark_comment_thread_read(int $projectId, int $userId, int $threadId): void
{
    ensure_comment_thread_reads_table();
    $stmt = db()->prepare(
        'SELECT id
           FROM ' . table_name('comments') . '
          WHERE id = ? AND project_id = ? AND parent_id IS NULL
          LIMIT 1'
    );
    $stmt->execute([$threadId, $projectId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('既読にするコメントが見つかりません。');
    }

    $stmt = db()->prepare(
        'INSERT INTO ' . table_name('comment_thread_reads') . ' (project_id, thread_id, user_id, last_read_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE last_read_at = VALUES(last_read_at)'
    );
    $stmt->execute([$projectId, $threadId, $userId]);
}

try {
    ensure_comment_confirmation_columns();
    ensure_comment_viewport_column();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $projectRef = (string) ($_GET['id'] ?? '');
        $fileParam = trim((string) ($_GET['file'] ?? ''));
        $project = find_project_for_user_ref($projectRef, (int) $user['id']);
        if ($project === null) {
            respond_json(['ok' => false, 'message' => 'プロジェクトが見つかりません。'], 404);
        }
        $file = null;
        if ($fileParam !== '') {
            $file = normalize_zip_path($fileParam);
            safe_project_file($project, $file);
            if (!is_html_file($file)) {
                throw new RuntimeException('HTMLファイルではありません。');
            }
        }

        respond_json(['ok' => true, 'threads' => comments_payload((int) $project['id'], $file, (int) $user['id'], (int) $project['user_id'])]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond_json(['ok' => false, 'message' => 'Method not allowed'], 405);
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'multipart/form-data')) {
        $payload = $_POST;
    } else {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new RuntimeException('コメントを読み込めませんでした。');
        }
    }

    $token = (string) ($payload['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('セッションの有効期限が切れました。もう一度お試しください。');
    }

    $projectRef = (string) ($payload['id'] ?? '');
    $file = normalize_zip_path((string) ($payload['file'] ?? ''));
    $action = (string) ($payload['action'] ?? 'create');
    $body = trim((string) ($payload['body'] ?? ''));
    $parentId = (int) ($payload['parent_id'] ?? 0);
    $selector = trim((string) ($payload['selector'] ?? ''));
    $viewportMode = normalize_comment_viewport_mode((string) ($payload['viewport_mode'] ?? ''));

    $project = find_project_for_user_ref($projectRef, (int) $user['id']);
    if ($project === null) {
        throw new RuntimeException('プロジェクトが見つかりません。');
    }
    safe_project_file($project, $file);
    if (!is_html_file($file)) {
        throw new RuntimeException('HTMLファイルではありません。');
    }

    if ($action === 'delete') {
        $commentId = (int) ($payload['comment_id'] ?? 0);
        if ($commentId <= 0) {
            throw new RuntimeException('削除するコメントが見つかりません。');
        }

        $stmt = db()->prepare(
            'SELECT id, user_id, parent_id, selector, viewport_mode, resolved_at, sheet_status, desired_due_at
               FROM ' . table_name('comments') . '
              WHERE id = ? AND project_id = ? AND file_path = ?
              LIMIT 1'
        );
        $stmt->execute([$commentId, (int) $project['id'], $file]);
        $comment = $stmt->fetch();
        if (!$comment) {
            throw new RuntimeException('削除するコメントが見つかりません。');
        }
        if ((int) $comment['user_id'] !== (int) $user['id'] && (int) $project['user_id'] !== (int) $user['id']) {
            throw new RuntimeException('このコメントは削除できません。');
        }

        $stmt = db()->prepare('SELECT COUNT(*) FROM ' . table_name('comments') . ' WHERE parent_id = ? AND project_id = ? AND file_path = ?');
        $stmt->execute([$commentId, (int) $project['id'], $file]);
        $replyCount = (int) $stmt->fetchColumn();
        $focusId = $comment['parent_id'] === null ? 0 : (int) $comment['parent_id'];

        if ($replyCount > 0 && $comment['parent_id'] === null) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'SELECT id
                       FROM ' . table_name('comments') . '
                      WHERE parent_id = ? AND project_id = ? AND file_path = ?
                      ORDER BY created_at ASC, id ASC
                      LIMIT 1'
                );
                $stmt->execute([$commentId, (int) $project['id'], $file]);
                $newParentId = (int) $stmt->fetchColumn();
                if ($newParentId <= 0) {
                    throw new RuntimeException('返信コメントが見つかりません。');
                }
                $focusId = $newParentId;

                $stmt = $pdo->prepare(
                    'UPDATE ' . table_name('comments') . '
                        SET parent_id = NULL,
                            selector = ?,
                            viewport_mode = ?,
                            resolved_at = ?,
                            sheet_status = ?,
                            desired_due_at = ?
                      WHERE id = ? AND project_id = ? AND file_path = ?'
                );
                $stmt->execute([
                    $comment['selector'],
                    $comment['viewport_mode'],
                    $comment['resolved_at'],
                    $comment['sheet_status'],
                    $comment['desired_due_at'],
                    $newParentId,
                    (int) $project['id'],
                    $file,
                ]);

                $stmt = $pdo->prepare(
                    'UPDATE ' . table_name('comments') . '
                        SET parent_id = ?
                      WHERE parent_id = ? AND id <> ? AND project_id = ? AND file_path = ?'
                );
                $stmt->execute([$newParentId, $commentId, $newParentId, (int) $project['id'], $file]);

                $stmt = $pdo->prepare('DELETE FROM ' . table_name('comments') . ' WHERE id = ? AND project_id = ? AND file_path = ?');
                $stmt->execute([$commentId, (int) $project['id'], $file]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } else {
            $stmt = db()->prepare('DELETE FROM ' . table_name('comments') . ' WHERE id = ? AND project_id = ? AND file_path = ?');
            $stmt->execute([$commentId, (int) $project['id'], $file]);
        }

        respond_json(['ok' => true, 'focus_id' => $focusId, 'threads' => comments_payload((int) $project['id'], $file, (int) $user['id'], (int) $project['user_id'])]);
    }

    if ($action === 'mark_read') {
        $commentId = (int) ($payload['comment_id'] ?? 0);
        if ($commentId <= 0) {
            throw new RuntimeException('既読にするコメントが見つかりません。');
        }
        mark_comment_thread_read((int) $project['id'], (int) $user['id'], $commentId);
        respond_json(['ok' => true, 'thread_id' => $commentId]);
    }

    if ($action === 'update') {
        $commentId = (int) ($payload['comment_id'] ?? 0);
        if ($commentId <= 0) {
            throw new RuntimeException('編集するコメントが見つかりません。');
        }
        if ($body === '' || mb_strlen($body) > 2000) {
            throw new RuntimeException('コメントは1文字以上2000文字以内で入力してください。');
        }

        $stmt = db()->prepare(
            'SELECT id, user_id, parent_id
               FROM ' . table_name('comments') . '
              WHERE id = ? AND project_id = ? AND file_path = ?
              LIMIT 1'
        );
        $stmt->execute([$commentId, (int) $project['id'], $file]);
        $comment = $stmt->fetch();
        if (!$comment) {
            throw new RuntimeException('編集するコメントが見つかりません。');
        }
        if ((int) $comment['user_id'] !== (int) $user['id']) {
            throw new RuntimeException('このコメントは編集できません。');
        }

        $stmt = db()->prepare('UPDATE ' . table_name('comments') . ' SET body = ? WHERE id = ? AND project_id = ? AND file_path = ?');
        $stmt->execute([$body, $commentId, (int) $project['id'], $file]);
        reset_comment_ai_check_for_comment((int) $project['id'], $commentId);
        save_comment_images((int) $project['id'], $commentId);

        respond_json([
            'ok' => true,
            'updated_id' => $commentId,
            'focus_id' => $comment['parent_id'] === null ? $commentId : (int) $comment['parent_id'],
            'threads' => comments_payload((int) $project['id'], $file, (int) $user['id'], (int) $project['user_id']),
        ]);
    }

    if ($action === 'resolve') {
        $commentId = (int) ($payload['comment_id'] ?? 0);
        if ($commentId <= 0) {
            throw new RuntimeException('対象コメントが見つかりません。');
        }

        $stmt = db()->prepare(
            'SELECT id, resolved_at
               FROM ' . table_name('comments') . '
              WHERE id = ? AND project_id = ? AND file_path = ? AND parent_id IS NULL
              LIMIT 1'
        );
        $stmt->execute([$commentId, (int) $project['id'], $file]);
        $comment = $stmt->fetch();
        if (!$comment) {
            throw new RuntimeException('対象コメントが見つかりません。');
        }

        $resolved = $comment['resolved_at'] === null;
        $stmt = db()->prepare('UPDATE ' . table_name('comments') . ' SET resolved_at = ?, sheet_status = ? WHERE id = ? AND project_id = ? AND file_path = ? AND parent_id IS NULL');
        $stmt->execute([$resolved ? date('Y-m-d H:i:s') : null, $resolved ? 'done' : 'todo', $commentId, (int) $project['id'], $file]);

        respond_json(['ok' => true, 'resolved' => $resolved, 'threads' => comments_payload((int) $project['id'], $file, (int) $user['id'], (int) $project['user_id'])]);
    }

    if ($action === 'confirmation_pending') {
        $commentId = (int) ($payload['comment_id'] ?? 0);
        if ($commentId <= 0) {
            throw new RuntimeException('対象コメントが見つかりません。');
        }

        $stmt = db()->prepare(
            'SELECT id, confirmation_pending_at
               FROM ' . table_name('comments') . '
              WHERE id = ? AND project_id = ? AND file_path = ? AND parent_id IS NULL
              LIMIT 1'
        );
        $stmt->execute([$commentId, (int) $project['id'], $file]);
        $comment = $stmt->fetch();
        if (!$comment) {
            throw new RuntimeException('対象コメントが見つかりません。');
        }

        $pending = $comment['confirmation_pending_at'] === null;
        $stmt = db()->prepare('UPDATE ' . table_name('comments') . ' SET confirmation_pending_at = ? WHERE id = ? AND project_id = ? AND file_path = ? AND parent_id IS NULL');
        $stmt->execute([$pending ? date('Y-m-d H:i:s') : null, $commentId, (int) $project['id'], $file]);

        respond_json(['ok' => true, 'confirmation_pending' => $pending, 'threads' => comments_payload((int) $project['id'], $file, (int) $user['id'], (int) $project['user_id'])]);
    }

    if ($body === '' || mb_strlen($body) > 2000) {
        throw new RuntimeException('コメントは1文字以上2000文字以内で入力してください。');
    }

    $createdId = 0;
    if ($parentId > 0) {
        $stmt = db()->prepare('SELECT id FROM ' . table_name('comments') . ' WHERE id = ? AND project_id = ? AND file_path = ? AND parent_id IS NULL');
        $stmt->execute([$parentId, (int) $project['id'], $file]);
        if (!$stmt->fetch()) {
            throw new RuntimeException('返信先のコメントが見つかりません。');
        }

        $stmt = db()->prepare('INSERT INTO ' . table_name('comments') . ' (project_id, file_path, selector, viewport_mode, user_id, parent_id, body) VALUES (?, ?, NULL, NULL, ?, ?, ?)');
        $stmt->execute([(int) $project['id'], $file, (int) $user['id'], $parentId, $body]);
        $createdId = (int) db()->lastInsertId();
        reset_comment_ai_checks((int) $project['id'], null, $parentId);
        save_comment_images((int) $project['id'], $createdId);
    } else {
        if ($selector === '' || mb_strlen($selector) > 255) {
            throw new RuntimeException('コメント対象のセレクタを入力してください。');
        }
        $file = resolve_comment_file_for_selector($project, $file, $selector);

        $stmt = db()->prepare('INSERT INTO ' . table_name('comments') . ' (project_id, file_path, selector, viewport_mode, user_id, parent_id, body) VALUES (?, ?, ?, ?, ?, NULL, ?)');
        $stmt->execute([(int) $project['id'], $file, $selector, $viewportMode !== '' ? $viewportMode : null, (int) $user['id'], $body]);
        $createdId = (int) db()->lastInsertId();
        save_comment_images((int) $project['id'], $createdId);
    }

    respond_json(['ok' => true, 'created_id' => $parentId > 0 ? $parentId : $createdId, 'threads' => comments_payload((int) $project['id'], $file, (int) $user['id'], (int) $project['user_id'])]);
} catch (Throwable $e) {
    respond_json(['ok' => false, 'message' => $e->getMessage()], 400);
}
