<?php

require __DIR__ . '/_app.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');

function ai_models_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $user = current_user();
    if ($user === null) {
        ai_models_response(['ok' => false, 'message' => 'ログインしてください。'], 401);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ai_models_response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('モデル一覧の取得データを読み込めませんでした。');
    }
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($payload['csrf_token'] ?? ''))) {
        throw new RuntimeException('セッションの有効期限が切れました。もう一度お試しください。');
    }

    $provider = normalize_ai_provider((string) ($payload['provider'] ?? ''));
    if ($provider === '') {
        throw new RuntimeException('AIプロバイダが不正です。');
    }
    $apiKey = trim((string) ($payload['api_key'] ?? ''));
    if ($apiKey === '') {
        $apiKey = ai_api_key_for_user((int) $user['id'], $provider);
    }
    if ($apiKey === '') {
        throw new RuntimeException('APIキーを入力または保存してから更新してください。');
    }

    $forceRefresh = !empty($payload['refresh']);
    $models = $forceRefresh ? null : cached_available_ai_models($provider, $apiKey);
    $cacheHit = $models !== null;
    if ($models === null) {
        $models = available_ai_models($provider, $apiKey);
        cache_available_ai_models($provider, $apiKey, $models);
    }
    $definitions = ai_provider_definitions();
    $declaredDefault = (string) ($definitions[$provider]['default_model'] ?? '');
    ai_models_response([
        'ok' => true,
        'provider' => $provider,
        'models' => $models,
        'count' => count($models),
        'compatible_count' => count(array_filter($models, static fn (array $model): bool => !empty($model['compatible']))),
        'default_model' => preferred_available_ai_model($provider, $models, $declaredDefault),
        'cached' => $cacheHit,
    ]);
} catch (Throwable $e) {
    ai_models_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
