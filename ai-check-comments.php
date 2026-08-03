<?php

require __DIR__ . '/_app.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');

function ai_check_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ai_check_http_json(string $url, array $headers, array $payload, int $timeout = 45): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('AI接続を初期化できませんでした。');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $body === '') {
        throw new RuntimeException($error !== '' ? $error : 'AIレスポンスが空です。');
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new RuntimeException('AIレスポンスを解析できませんでした。');
    }
    if ($status < 200 || $status >= 300) {
        $message = $data['error']['message'] ?? $data['message'] ?? 'AI APIがエラーを返しました。';
        throw new RuntimeException((string) $message);
    }
    return $data;
}

function ai_check_text_from_response(string $provider, array $response): string
{
    if ($provider === 'openai') {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }
        foreach (($response['output'] ?? []) as $output) {
            foreach (($output['content'] ?? []) as $content) {
                if (isset($content['text']) && is_string($content['text'])) {
                    return $content['text'];
                }
            }
        }
        return (string) ($response['choices'][0]['message']['content'] ?? '');
    }
    if ($provider === 'gemini') {
        return (string) ($response['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }
    return (string) ($response['choices'][0]['message']['content'] ?? '');
}

function ai_check_extract_json(string $text): array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
    $text = preg_replace('/\s*```$/', '', $text) ?? $text;
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    if (preg_match('/\{.*\}/s', $text, $matches)) {
        $decoded = json_decode($matches[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    throw new RuntimeException('AIレスポンスのJSONを解析できませんでした。');
}

function ai_check_call(array $setting, string $prompt): array
{
    $provider = (string) $setting['provider'];
    $model = (string) $setting['model'];
    $apiKey = (string) $setting['api_key'];

    if ($provider === 'openai') {
        $response = ai_check_http_json(
            'https://api.openai.com/v1/responses',
            ['Authorization: Bearer ' . $apiKey],
            [
                'model' => $model,
                'instructions' => 'You are a strict website QA reviewer. Return only valid JSON.',
                'input' => $prompt,
                'max_output_tokens' => 700,
            ]
        );
    } elseif ($provider === 'gemini') {
        $response = ai_check_http_json(
            'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
            ['x-goog-api-key: ' . $apiKey],
            [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'maxOutputTokens' => 700,
                ],
            ]
        );
    } else {
        $response = ai_check_http_json(
            'https://api.x.ai/v1/chat/completions',
            ['Authorization: Bearer ' . $apiKey],
            [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a strict website QA reviewer. Return only valid JSON.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => 700,
            ]
        );
    }

    $data = ai_check_extract_json(ai_check_text_from_response($provider, $response));
    $status = normalize_ai_check_status((string) ($data['status'] ?? 'uncertain'));
    if ($status === 'unchecked') {
        $status = 'uncertain';
    }
    $summary = trim((string) ($data['summary'] ?? ''));
    if ($summary === '') {
        $summary = 'AI確認結果の要約がありません。';
    }

    return [
        'status' => $status,
        'summary' => mb_substr($summary, 0, 5000),
        'provider' => $provider,
        'model' => $model,
    ];
}

function ai_check_page_context(array $project, string $file): array
{
    $path = safe_project_file($project, $file);
    $html = (string) file_get_contents($path);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return [
        'html_excerpt' => mb_substr($html, 0, 12000),
        'text_excerpt' => mb_substr(trim($text), 0, 16000),
    ];
}

function ai_check_prompt(array $project, array $comment, array $replies, array $context): string
{
    $replyLines = [];
    foreach ($replies as $reply) {
        $replyLines[] = '- ' . (string) $reply['body'];
    }
    $replyText = $replyLines === [] ? 'なし' : implode("\n", $replyLines);

    return <<<PROMPT
以下はWebサイト確認ツールのコメントです。
コメントの意図を判断し、修正・改善要望であれば、現在のHTMLに反映済みかを確認してください。

返答は必ず次のJSONだけにしてください。
{"status":"not_applicable|reflected|not_reflected|uncertain","summary":"日本語で80〜220文字の判定理由"}

判定基準:
- 質問、感想、単なる連絡、確認依頼で、修正・改善要望ではない場合は not_applicable
- 修正・改善要望が現在のHTMLに反映されていると判断できる場合は reflected
- 反映されていないと判断できる場合は not_reflected
- HTML断片やコメントだけでは判断できない場合は uncertain

プロジェクト: {$project['title']}
対象ファイル: {$comment['file_path']}
対象selector: {$comment['selector']}
親コメント: {$comment['body']}
返信:
{$replyText}

ページテキスト抜粋:
{$context['text_excerpt']}

HTML抜粋:
{$context['html_excerpt']}
PROMPT;
}

function ai_check_default_counts(): array
{
    return ['not_applicable' => 0, 'reflected' => 0, 'not_reflected' => 0, 'uncertain' => 0, 'error' => 0];
}

function ai_check_decode_counts(?string $json): array
{
    $counts = ai_check_default_counts();
    $decoded = json_decode((string) $json, true);
    if (!is_array($decoded)) {
        return $counts;
    }
    foreach ($counts as $key => $value) {
        $counts[$key] = max(0, (int) ($decoded[$key] ?? 0));
    }
    return $counts;
}

function ai_check_count_targets(int $projectId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*)
           FROM ' . table_name('comments') . '
          WHERE project_id = ?
            AND parent_id IS NULL
            AND COALESCE(ai_check_status, \'unchecked\') = \'unchecked\''
    );
    $stmt->execute([$projectId]);
    return (int) $stmt->fetchColumn();
}

function ai_check_create_job(array $project, int $userId, array $setting): array
{
    ensure_ai_check_jobs_table();
    $total = ai_check_count_targets((int) $project['id']);
    $counts = ai_check_default_counts();
    $jobId = random_alnum_id(16);
    $status = $total > 0 ? 'queued' : 'done';
    $stmt = db()->prepare(
        'INSERT INTO ' . table_name('ai_check_jobs') . ' (public_id, project_id, user_id, ai_provider, ai_model, status, total_count, counts_json, finished_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ' . ($status === 'done' ? 'NOW()' : 'NULL') . ')'
    );
    $stmt->execute([
        $jobId,
        (int) $project['id'],
        $userId,
        (string) $setting['provider'],
        (string) $setting['model'],
        $status,
        $total,
        json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return ai_check_load_job($jobId, $userId);
}

function ai_check_load_job(string $jobId, int $userId): array
{
    ensure_ai_check_jobs_table();
    $stmt = db()->prepare(
        'SELECT *
           FROM ' . table_name('ai_check_jobs') . '
          WHERE public_id = ? AND user_id = ?
          LIMIT 1'
    );
    $stmt->execute([$jobId, $userId]);
    $job = $stmt->fetch();
    if (!$job) {
        throw new RuntimeException('AI確認ジョブが見つかりません。');
    }
    return $job;
}

function ai_check_job_payload(array $job, string $message = ''): array
{
    $counts = ai_check_decode_counts($job['counts_json'] ?? null);
    $status = (string) $job['status'];
    $total = (int) $job['total_count'];
    $processed = (int) $job['processed_count'];
    return [
        'ok' => true,
        'job_id' => (string) $job['public_id'],
        'status' => $status,
        'done' => in_array($status, ['done', 'error'], true),
        'total' => $total,
        'processed' => $processed,
        'checked' => $processed,
        'failed' => (int) $job['failed_count'],
        'remaining' => max(0, $total - $processed),
        'counts' => $counts,
        'provider' => (string) ($job['ai_provider'] ?? ''),
        'model' => (string) ($job['ai_model'] ?? ''),
        'message' => $message !== '' ? $message : (($status === 'done') ? 'AI確認を完了しました。' : (($status === 'error') ? ((string) ($job['error_message'] ?? 'AI確認に失敗しました。')) : 'AI確認を処理中です。')),
        'error_message' => (string) ($job['error_message'] ?? ''),
    ];
}

function ai_check_save_job_progress(array $job, array $counts, int $processedDelta, int $failedDelta, ?string $status = null, ?string $error = null): array
{
    $nextStatus = $status ?? (string) $job['status'];
    $finishedSql = in_array($nextStatus, ['done', 'error'], true) ? ', finished_at = COALESCE(finished_at, NOW())' : '';
    $stmt = db()->prepare(
        'UPDATE ' . table_name('ai_check_jobs') . '
            SET status = ?,
                processed_count = processed_count + ?,
                failed_count = failed_count + ?,
                counts_json = ?,
                error_message = ?
                ' . $finishedSql . '
          WHERE id = ?'
    );
    $stmt->execute([
        $nextStatus,
        $processedDelta,
        $failedDelta,
        json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $error,
        (int) $job['id'],
    ]);

    return ai_check_load_job((string) $job['public_id'], (int) $job['user_id']);
}

function ai_check_process_job(array $job, array $project, int $userId, int $batchSize = 1): array
{
    if (in_array((string) $job['status'], ['done', 'error'], true)) {
        return $job;
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(75);
    }

    $jobProvider = normalize_ai_provider((string) ($job['ai_provider'] ?? ''));
    $jobModel = (string) ($job['ai_model'] ?? '');
    if ($jobProvider !== '' && $jobModel !== '') {
        $setting = ai_execution_setting_for_user_provider($userId, $jobProvider, $jobModel);
        $settings = $setting === [] ? [] : [$setting];
    } else {
        // Existing jobs created before provider/model snapshots were introduced.
        $settings = ai_check_execution_settings_for_user($userId);
        $jobProvider = (string) ($settings[0]['provider'] ?? '');
    }
    if ($settings === []) {
        $selectedProvider = ai_provider_definitions()[$jobProvider]['label'] ?? '選択中のプロバイダ';
        return ai_check_save_job_progress($job, ai_check_decode_counts($job['counts_json'] ?? null), 0, 0, 'error', $selectedProvider . ' のAPIキーが未設定です。アカウント設定でAI確認に使うプロバイダとAPIキーを確認してください。');
    }

    if ((string) $job['status'] === 'queued') {
        $job = ai_check_save_job_progress($job, ai_check_decode_counts($job['counts_json'] ?? null), 0, 0, 'running');
    }

    $limit = max(1, min(3, $batchSize));
    $stmt = db()->prepare(
        'SELECT c.id, c.file_path, c.selector, c.body
           FROM ' . table_name('comments') . ' c
          WHERE c.project_id = ?
            AND c.parent_id IS NULL
            AND COALESCE(c.ai_check_status, \'unchecked\') = \'unchecked\'
          ORDER BY c.file_path ASC, c.created_at ASC, c.id ASC
          LIMIT ' . $limit
    );
    $stmt->execute([(int) $project['id']]);
    $comments = $stmt->fetchAll();
    if ($comments === []) {
        return ai_check_save_job_progress($job, ai_check_decode_counts($job['counts_json'] ?? null), 0, 0, 'done');
    }

    $replyStmt = db()->prepare(
        'SELECT body
           FROM ' . table_name('comments') . '
          WHERE project_id = ? AND parent_id = ?
          ORDER BY created_at ASC, id ASC'
    );
    $updateStmt = db()->prepare(
        'UPDATE ' . table_name('comments') . '
            SET ai_check_status = ?,
                ai_check_summary = ?,
                ai_checked_at = NOW(),
                ai_check_provider = ?,
                ai_check_model = ?
          WHERE id = ? AND project_id = ? AND parent_id IS NULL'
    );

    $counts = ai_check_decode_counts($job['counts_json'] ?? null);
    $processed = 0;
    $failed = 0;
    $contextCache = [];

    foreach ($comments as $comment) {
        $replyStmt->execute([(int) $project['id'], (int) $comment['id']]);
        $replies = $replyStmt->fetchAll();
        try {
            $file = normalize_zip_path((string) $comment['file_path']);
            if (!isset($contextCache[$file])) {
                $contextCache[$file] = ai_check_page_context($project, $file);
            }
            $prompt = ai_check_prompt($project, $comment, $replies, $contextCache[$file]);
            $lastError = null;
            $result = null;
            foreach ($settings as $setting) {
                try {
                    $result = ai_check_call($setting, $prompt);
                    break;
                } catch (Throwable $e) {
                    $lastError = $e;
                }
            }
            if ($result === null) {
                throw new RuntimeException($lastError ? $lastError->getMessage() : 'AI確認に失敗しました。');
            }
            $updateStmt->execute([
                $result['status'],
                $result['summary'],
                $result['provider'],
                $result['model'],
                (int) $comment['id'],
                (int) $project['id'],
            ]);
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
        } catch (Throwable $e) {
            $summary = mb_substr('AI確認に失敗しました: ' . $e->getMessage(), 0, 5000);
            $provider = $settings[0]['provider'] ?? '';
            $model = $settings[0]['model'] ?? '';
            $updateStmt->execute(['error', $summary, $provider, $model, (int) $comment['id'], (int) $project['id']]);
            $counts['error']++;
            $failed++;
        }
        $processed++;
    }

    $remaining = ai_check_count_targets((int) $project['id']);
    $nextStatus = $remaining > 0 ? 'running' : 'done';
    return ai_check_save_job_progress($job, $counts, $processed, $failed, $nextStatus);
}

try {
    $user = current_user();
    if ($user === null) {
        ai_check_response(['ok' => false, 'message' => 'ログインしてください。'], 401);
    }

    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
        ai_check_response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }

    $payload = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new RuntimeException('AI確認リクエストを読み込めませんでした。');
        }
        if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($payload['csrf_token'] ?? ''))) {
            throw new RuntimeException('セッションの有効期限が切れました。もう一度お試しください。');
        }
    }

    ensure_comment_ai_check_columns();
    ensure_ai_check_jobs_table();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $projectRef = (string) ($payload['project_id'] ?? '');
        $project = find_project_for_user_ref($projectRef, (int) $user['id']);
        if ($project === null) {
            throw new RuntimeException('プロジェクトが見つかりません。');
        }
        $settings = ai_check_execution_settings_for_user((int) $user['id']);
        if ($settings === []) {
            $selectedProvider = ai_provider_definitions()[ai_check_provider_for_user((int) $user['id'])]['label'] ?? '選択中のプロバイダ';
            throw new RuntimeException($selectedProvider . ' のAPIキーが未設定です。アカウント設定でAI確認に使うプロバイダとAPIキーを確認してください。');
        }
        $job = ai_check_create_job($project, (int) $user['id'], $settings[0]);
        ai_check_response(ai_check_job_payload($job, ((int) $job['total_count'] > 0) ? 'AI確認を開始しました。' : 'AI確認が必要なコメントはありません。'));
    }

    $jobId = (string) ($_GET['job_id'] ?? '');
    $projectRef = (string) ($_GET['project_id'] ?? '');
    if ($jobId === '' || $projectRef === '') {
        throw new RuntimeException('AI確認ジョブ情報が不足しています。');
    }
    $project = find_project_for_user_ref($projectRef, (int) $user['id']);
    if ($project === null) {
        throw new RuntimeException('プロジェクトが見つかりません。');
    }
    $job = ai_check_load_job($jobId, (int) $user['id']);
    if ((int) $job['project_id'] !== (int) $project['id']) {
        throw new RuntimeException('AI確認ジョブとプロジェクトが一致しません。');
    }
    $batchSize = max(1, min(3, (int) ($_GET['batch'] ?? 1)));
    $job = ai_check_process_job($job, $project, (int) $user['id'], $batchSize);
    ai_check_response(ai_check_job_payload($job));
} catch (Throwable $e) {
    ai_check_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
