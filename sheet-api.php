<?php

require __DIR__ . '/_app.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');

function sheet_api_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sheet_api_status(string $status): string
{
    return in_array($status, ['todo', 'doing', 'pending', 'done'], true) ? $status : 'todo';
}

function sheet_api_status_label(string $status): string
{
    return match (sheet_api_status($status)) {
        'doing' => '対応中',
        'pending' => '確認待ち',
        'done' => '解決済み',
        default => '未着手',
    };
}

function sheet_api_ai_check_status_label(string $status): string
{
    return match (normalize_ai_check_status($status)) {
        'not_applicable' => '対象外',
        'reflected' => '反映済み',
        'not_reflected' => '未反映',
        'uncertain' => '不明',
        'error' => 'エラー',
        default => '未確認',
    };
}

function sheet_api_status_filter_from_request(): ?array
{
    $raw = trim((string) ($_GET['status'] ?? ''));
    if ($raw === '') {
        return null;
    }

    $statuses = [];
    foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $status) {
        $status = sheet_api_status((string) $status);
        $statuses[$status] = true;
    }

    return array_keys($statuses);
}

function sheet_api_fields_from_request(): ?array
{
    $raw = trim((string) ($_GET['fields'] ?? ''));
    if ($raw === '') {
        return null;
    }

    $fields = [];
    foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $field) {
        $field = trim((string) $field);
        if ($field !== '') {
            $fields[$field] = true;
        }
    }

    return $fields === [] ? null : array_keys($fields);
}

function sheet_api_token_from_request(): string
{
    $webpatchToken = trim((string) ($_SERVER['HTTP_X_WEBPATCH_API_TOKEN'] ?? ''));
    if ($webpatchToken !== '') {
        return $webpatchToken;
    }

    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', (string) $header, $matches)) {
        return trim($matches[1]);
    }
    return trim((string) ($_GET['api_token'] ?? ''));
}

function sheet_api_copy_prompt_for_token(array $project): string
{
    $tokenId = (int) ($project['api_token_id'] ?? 0);
    if ($tokenId <= 0) {
        return '';
    }

    $stmt = db()->prepare('SELECT created_by FROM ' . table_name('comment_sheet_api_tokens') . ' WHERE id = ? LIMIT 1');
    $stmt->execute([$tokenId]);
    $createdBy = (int) ($stmt->fetchColumn() ?: 0);
    if ($createdBy <= 0) {
        return '';
    }

    return project_copy_prompt_for_user((int) $project['id'], $createdBy);
}

function sheet_api_comment_attachment_map(array $commentIds): array
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
        $commentId = (int) $row['comment_id'];
        $path = absolute_url('comment-image.php?id=' . rawurlencode((string) $row['id']));
        $attachments[$commentId][] = [
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

function sheet_api_response_prompt(array $row, array $fileCopyTargets, string $copyPrompt): string
{
    $file = (string) ($row['file_path'] ?? '');
    $selector = (string) ($row['selector'] ?? '');
    $target = ($fileCopyTargets[$file] ?? $file) . ' の ' . $selector;
    $lines = [
        '#対象 : ' . $target,
        '#コメント : ' . (string) ($row['body'] ?? ''),
    ];
    foreach (($row['attachment_paths'] ?? []) as $path) {
        $path = trim((string) $path);
        if ($path !== '') {
            $lines[] = '#添付 ' . $path;
        }
    }
    $copyPrompt = trim($copyPrompt);
    $baseText = implode("\n", $lines);

    return $copyPrompt === '' ? $baseText : $baseText . "\n\n" . $copyPrompt;
}

function sheet_api_apply_fields(array $row, ?array $fields): array
{
    if ($fields === null) {
        return $row;
    }

    $filtered = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $row)) {
            $filtered[$field] = $row[$field];
        }
    }

    return $filtered;
}

function sheet_api_comment_rows(array $project, ?array $statusFilter = null, ?array $fields = null): array
{
    ensure_comment_confirmation_columns();
    ensure_comment_ai_check_columns();
    $projectId = (int) $project['id'];
    $files = sheet_api_files($project);
    $fileCopyTargets = project_file_copy_targets($project, $files);
    $copyPrompt = sheet_api_copy_prompt_for_token($project);
    $stmt = db()->prepare(
        'SELECT c.id, c.file_path, c.selector, c.body, c.sheet_status, c.desired_due_at, c.ai_check_status, c.ai_check_summary, c.ai_checked_at, c.ai_check_provider, c.ai_check_model, c.resolved_at, c.confirmation_pending_at, c.created_at, u.name AS user_name
           FROM ' . table_name('comments') . ' c
           LEFT JOIN ' . table_name('users') . ' u ON u.id = c.user_id
          WHERE c.project_id = ? AND c.parent_id IS NULL
          ORDER BY c.file_path ASC, c.created_at ASC, c.id ASC'
    );
    $comments = [];
    $stmt->execute([$projectId]);
    $rows = $stmt->fetchAll();
    $attachmentsByComment = sheet_api_comment_attachment_map(array_column($rows, 'id'));
    foreach ($rows as $row) {
        $status = $row['resolved_at'] !== null ? 'done' : ($row['confirmation_pending_at'] !== null ? 'pending' : sheet_api_status((string) ($row['sheet_status'] ?? 'todo')));
        if ($statusFilter !== null && !in_array($status, $statusFilter, true)) {
            continue;
        }
        $filePath = (string) $row['file_path'];
        $selector = (string) ($row['selector'] ?? '');
        $comment = [
            'id' => (int) $row['id'],
            'file_path' => $filePath,
            'selector' => $selector,
            'comment_position' => trim($filePath . ' / ' . $selector),
            'body' => $row['body'],
            'sheet_status' => $status,
            'status' => $status,
            'status_label' => sheet_api_status_label($status),
            'desired_due_at' => $row['desired_due_at'],
            'ai_check_status' => normalize_ai_check_status((string) ($row['ai_check_status'] ?? 'unchecked')),
            'ai_check_status_label' => sheet_api_ai_check_status_label((string) ($row['ai_check_status'] ?? 'unchecked')),
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
        ];
        $attachments = $attachmentsByComment[(int) $row['id']] ?? [];
        $comment['attachments'] = $attachments;
        $comment['attachment_paths'] = array_map(static fn (array $attachment): string => (string) $attachment['path'], $attachments);
        $comment['response_prompt'] = sheet_api_response_prompt($comment, $fileCopyTargets, $copyPrompt);
        $comments[] = sheet_api_apply_fields($comment, $fields);
    }
    return $comments;
}

function sheet_api_files(array $project): array
{
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
    return $files;
}

function sheet_api_update_comment(int $projectId, array $update): void
{
    ensure_comment_confirmation_columns();
    ensure_comment_ai_check_columns();
    $commentId = (int) ($update['comment_id'] ?? $update['id'] ?? 0);
    if ($commentId <= 0) {
        throw new RuntimeException('comment_id が必要です。');
    }

    $sets = [];
    $params = [];
    if (array_key_exists('sheet_status', $update)) {
        $status = sheet_api_status((string) $update['sheet_status']);
        $sets[] = 'sheet_status = ?';
        $params[] = $status;
        if ($status === 'done') {
            $sets[] = 'resolved_at = COALESCE(resolved_at, NOW())';
            $sets[] = 'confirmation_pending_at = NULL';
        } elseif ($status === 'pending') {
            $sets[] = 'resolved_at = NULL';
            $sets[] = 'confirmation_pending_at = COALESCE(confirmation_pending_at, NOW())';
        } else {
            $sets[] = 'resolved_at = NULL';
            $sets[] = 'confirmation_pending_at = NULL';
        }
    }
    if (array_key_exists('desired_due_at', $update)) {
        $rawDue = trim((string) $update['desired_due_at']);
        if ($rawDue === '') {
            $sets[] = 'desired_due_at = NULL';
        } else {
            $time = strtotime($rawDue);
            if ($time === false) {
                throw new RuntimeException('希望完了日時の形式が不正です。');
            }
            $sets[] = 'desired_due_at = ?';
            $params[] = date('Y-m-d H:i:s', $time);
        }
    }
    if (array_key_exists('body', $update)) {
        $body = trim((string) $update['body']);
        if ($body === '') {
            throw new RuntimeException('コメント内容は空にできません。');
        }
        $sets[] = 'body = ?';
        $params[] = mb_substr($body, 0, 20000);
        $sets[] = 'ai_check_status = \'unchecked\'';
        $sets[] = 'ai_check_summary = NULL';
        $sets[] = 'ai_checked_at = NULL';
        $sets[] = 'ai_check_provider = NULL';
        $sets[] = 'ai_check_model = NULL';
    }
    if ($sets === []) {
        throw new RuntimeException('更新項目がありません。');
    }

    $params[] = $commentId;
    $params[] = $projectId;
    $stmt = db()->prepare(
        'UPDATE ' . table_name('comments') . '
            SET ' . implode(', ', $sets) . '
          WHERE id = ? AND project_id = ? AND parent_id IS NULL'
    );
    $stmt->execute($params);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('コメントが見つかりません。');
    }
}

try {
    $token = sheet_api_token_from_request();
    $project = project_for_comment_sheet_api_token($token);
    if ($project === null) {
        sheet_api_response(['ok' => false, 'message' => 'APIトークンが無効です。'], 401);
    }

    $stmt = db()->prepare('UPDATE ' . table_name('comment_sheet_api_tokens') . ' SET last_used_at = NOW() WHERE id = ?');
    $stmt->execute([(int) $project['api_token_id']]);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $comments = sheet_api_comment_rows($project, sheet_api_status_filter_from_request(), sheet_api_fields_from_request());
        sheet_api_response([
            'ok' => true,
            'project' => [
                'id' => project_public_ref($project),
                'title' => $project['title'],
            ],
            'files' => sheet_api_files($project),
            'comments' => $comments,
        ]);
    }

    if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PATCH'], true)) {
        sheet_api_response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('JSONを読み込めませんでした。');
    }

    $updates = [];
    if (isset($payload['updates']) && is_array($payload['updates'])) {
        $updates = $payload['updates'];
    } else {
        $updates = [$payload];
    }
    if ($updates === []) {
        throw new RuntimeException('更新対象がありません。');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($updates as $update) {
            if (!is_array($update)) {
                throw new RuntimeException('更新内容が不正です。');
            }
            sheet_api_update_comment((int) $project['id'], $update);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    sheet_api_response([
        'ok' => true,
        'updated' => count($updates),
        'comments' => sheet_api_comment_rows($project),
    ]);
} catch (Throwable $e) {
    sheet_api_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
