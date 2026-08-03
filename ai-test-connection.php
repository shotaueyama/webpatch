<?php

require __DIR__ . '/_app.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');

function ai_test_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ai_test_http_json(string $url, array $headers, array $payload, int $timeout = 25): array
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

function ai_saved_key_for_user(int $userId, string $provider): string
{
    ensure_ai_settings_table();
    $stmt = db()->prepare('SELECT api_key_cipher FROM ' . table_name('ai_settings') . ' WHERE user_id = ? AND provider = ? LIMIT 1');
    $stmt->execute([$userId, $provider]);
    $row = $stmt->fetch();
    return $row ? decrypt_ai_api_key($row['api_key_cipher'] ?? null) : '';
}

function ai_test_connection(string $provider, string $model, string $apiKey): void
{
    if ($provider === 'openai') {
        ai_test_http_json(
            'https://api.openai.com/v1/responses',
            ['Authorization: Bearer ' . $apiKey],
            [
                'model' => $model,
                'instructions' => 'Reply with exactly OK.',
                'input' => 'Connection test.',
                'max_output_tokens' => 16,
            ]
        );
        return;
    }

    if ($provider === 'gemini') {
        ai_test_http_json(
            'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
            ['x-goog-api-key: ' . $apiKey],
            [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => 'Connection test. Reply with OK.'],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => 16,
                ],
            ]
        );
        return;
    }

    ai_test_http_json(
        'https://api.x.ai/v1/chat/completions',
        ['Authorization: Bearer ' . $apiKey],
        [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => 'Connection test. Reply with OK.'],
            ],
            'max_tokens' => 16,
        ]
    );
}

try {
    $user = current_user();
    if ($user === null) {
        ai_test_response(['ok' => false, 'message' => 'ログインしてください。'], 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ai_test_response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('接続確認データを読み込めませんでした。');
    }
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($payload['csrf_token'] ?? ''))) {
        throw new RuntimeException('セッションの有効期限が切れました。もう一度お試しください。');
    }

    $provider = normalize_ai_provider((string) ($payload['provider'] ?? ''));
    if ($provider === '') {
        throw new RuntimeException('AIプロバイダが不正です。');
    }
    $model = normalize_ai_model($provider, (string) ($payload['model'] ?? ''));
    $apiKey = trim((string) ($payload['api_key'] ?? ''));
    if ($apiKey === '') {
        $apiKey = ai_saved_key_for_user((int) $user['id'], $provider);
    }
    if ($apiKey === '') {
        throw new RuntimeException('APIキーが未設定です。入力するか、保存済みキーを利用してください。');
    }

    ai_test_connection($provider, $model, $apiKey);
    ai_test_response([
        'ok' => true,
        'message' => ai_provider_definitions()[$provider]['label'] . ' に接続できました。',
        'model' => $model,
    ]);
} catch (Throwable $e) {
    ai_test_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
