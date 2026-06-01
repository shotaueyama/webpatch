<?php

require __DIR__ . '/_app.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');

function git_test_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function git_test_github_repo(string $repoUrl): array
{
    $repoUrl = trim($repoUrl);
    $path = '';
    if (preg_match('/^https:\/\/github\.com\/([^\/\s]+)\/([^\/\s]+?)(?:\.git)?(?:\/)?$/i', $repoUrl, $matches)) {
        $path = $matches[1] . '/' . $matches[2];
    } elseif (preg_match('/^git@github\.com:([^\/\s]+)\/([^\/\s]+?)(?:\.git)?$/i', $repoUrl, $matches)) {
        $path = $matches[1] . '/' . $matches[2];
    }
    if ($path === '') {
        throw new RuntimeException('接続確認は GitHub の https://github.com/owner/repo または git@github.com:owner/repo.git 形式に対応しています。');
    }
    return explode('/', $path, 2);
}

function git_test_http_json(string $url, array $headers, int $timeout = 20): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('GitHub接続を初期化できませんでした。');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/vnd.github+json', 'User-Agent: WebPatch'], $headers),
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $body === '') {
        throw new RuntimeException($error !== '' ? $error : 'GitHubレスポンスが空です。');
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new RuntimeException('GitHubレスポンスを解析できませんでした。');
    }
    if ($status < 200 || $status >= 300) {
        $message = $data['message'] ?? 'GitHub APIがエラーを返しました。';
        throw new RuntimeException((string) $message);
    }
    return $data;
}

try {
    $user = current_user();
    if ($user === null) {
        git_test_response(['ok' => false, 'message' => 'ログインしてください。'], 401);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        git_test_response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Git接続確認データを読み込めませんでした。');
    }
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($payload['csrf_token'] ?? ''))) {
        throw new RuntimeException('セッションの有効期限が切れました。もう一度お試しください。');
    }

    $repositoryUrl = normalize_git_repo_url((string) ($payload['repository_url'] ?? ''));
    $branchName = normalize_git_branch((string) ($payload['branch_name'] ?? 'main'));
    $accessToken = trim((string) ($payload['access_token'] ?? ''));
    if ($accessToken === '') {
        $accessToken = git_access_token_for_user((int) $user['id'], 'github');
    }
    if ($accessToken === '') {
        throw new RuntimeException('GitHubアクセストークンが未設定です。入力するか、保存済みトークンを利用してください。');
    }

    $headers = ['Authorization: Bearer ' . $accessToken];

    if ($repositoryUrl === '') {
        $userData = git_test_http_json('https://api.github.com/user', $headers);
        git_test_response([
            'ok' => true,
            'message' => 'GitHubアカウントに接続できました。',
            'repository' => (string) ($userData['login'] ?? ''),
            'branch' => '',
        ]);
    }

    [$owner, $repo] = git_test_github_repo($repositoryUrl);
    $repoData = git_test_http_json('https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo), $headers);
    git_test_http_json('https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/branches/' . rawurlencode($branchName), $headers);

    git_test_response([
        'ok' => true,
        'message' => 'GitHubリポジトリに接続できました。',
        'repository' => (string) ($repoData['full_name'] ?? ($owner . '/' . $repo)),
        'branch' => $branchName,
    ]);
} catch (Throwable $e) {
    git_test_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
