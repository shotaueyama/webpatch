<?php

declare(strict_types=1);

const WEBPATCH_MAX_UPLOAD_BYTES = 104857600;
const WEBPATCH_MAX_EXTRACTED_BYTES = 104857600;
const WEBPATCH_SESSION_SECONDS = 259200;
const WEBPATCH_MAX_USERS = 5;
const WEBPATCH_MAX_URL_IMPORTS = 50;
const WEBPATCH_MAX_URL_HTML_BYTES = 10485760;
const WEBPATCH_URL_FETCH_TIMEOUT = 10;
const WEBPATCH_MAX_NOTE_BYTES = 2097152;
const WEBPATCH_MAX_COMMENT_IMAGES = 8;
const WEBPATCH_MAX_COMMENT_IMAGE_BYTES = 5242880;

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    $configFile = __DIR__ . '/config.example.php';
}

$config = require $configFile;

$sessionPath = rtrim((string) ($config['storage_root'] ?? sys_get_temp_dir()), '/') . '/sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0750, true);
}
if (is_dir($sessionPath) && is_writable($sessionPath)) {
    session_save_path($sessionPath);
}
ini_set('session.gc_maxlifetime', (string) WEBPATCH_SESSION_SECONDS);
ini_set('session.cookie_lifetime', (string) WEBPATCH_SESSION_SECONDS);

session_name('webpatch_session');
session_set_cookie_params([
    'lifetime' => WEBPATCH_SESSION_SECONDS,
    'path' => $config['base_url'] ?? '/webpatch',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function app_config(?string $key = null): mixed
{
    global $config;
    if ($key === null) {
        return $config;
    }
    return $config[$key] ?? null;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $database = app_config('database');
    $pdo = new PDO($database['dsn'], $database['user'], $database['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function table_name(string $base): string
{
    $database = app_config('database');
    $prefix = (string) ($database['table_prefix'] ?? '');
    $name = $prefix . $base;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('テーブル設定が不正です。');
    }
    return $name;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(string $path = ''): string
{
    $base = rtrim((string) app_config('base_url'), '/');
    $path = preg_replace('/(^|\/)([^\/?]+)\.php(?=\?|$)/', '$1$2', $path) ?? $path;
    return $base . '/' . ltrim($path, '/');
}

function absolute_url(string $path = ''): string
{
    $scheme = 'http';
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        $scheme = 'https';
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'cognify.works';
    return $scheme . '://' . $host . base_url($path);
}

function redirect_to(string $path): never
{
    header('Location: ' . base_url($path), true, 302);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function random_alnum_id(int $length = 12): string
{
    $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $max = strlen($alphabet) - 1;
    $id = '';
    for ($i = 0; $i < $length; $i++) {
        $id .= $alphabet[random_int(0, $max)];
    }
    return $id;
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new RuntimeException('セッションの有効期限が切れました。もう一度お試しください。');
    }
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $user = null;
    if ($user !== null) {
        return $user;
    }

    $stmt = db()->prepare('SELECT id, name, email, created_at FROM ' . table_name('users') . ' WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;

    if ($user === null) {
        unset($_SESSION['user_id']);
    }

    return $user;
}

function require_user(): array
{
    $user = current_user();
    if ($user === null) {
        set_flash('error', 'ログインしてください。');
        redirect_to('login.php');
    }
    return $user;
}

function webpatch_user_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM ' . table_name('users'))->fetchColumn();
}

function ensure_user_capacity(): void
{
    if (webpatch_user_count() >= WEBPATCH_MAX_USERS) {
        throw new RuntimeException('現在、新規登録の上限人数に達しています。');
    }
}

function sign_in(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    unset($_SESSION['csrf_token']);
    csrf_token();
}

function generate_project_public_id(): string
{
    $stmt = db()->prepare('SELECT 1 FROM ' . table_name('projects') . ' WHERE public_id = ? LIMIT 1');
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $publicId = random_alnum_id(12);
        $stmt->execute([$publicId]);
        if (!$stmt->fetchColumn()) {
            return $publicId;
        }
    }
    throw new RuntimeException('プロジェクトIDを生成できませんでした。');
}

function project_public_ref(array $project): string
{
    return (string) ($project['public_id'] ?? $project['id']);
}

function project_path(array $project, string $file = ''): string
{
    $path = 'project.php?id=' . rawurlencode(project_public_ref($project));
    if ($file !== '') {
        $path .= '&file=' . rawurlencode($file);
    }
    return $path;
}

function project_path_by_id(int $projectId, string $file = ''): string
{
    $stmt = db()->prepare('SELECT id, public_id FROM ' . table_name('projects') . ' WHERE id = ? LIMIT 1');
    $stmt->execute([$projectId]);
    $project = $stmt->fetch();
    return $project ? project_path($project, $file) : 'dashboard.php';
}

function find_project_for_user(int $projectId, int $userId): ?array
{
    $projectsTable = table_name('projects');
    $sharesTable = table_name('project_shares');
    $stmt = db()->prepare(
        'SELECT p.*, CASE WHEN p.user_id = ? THEN \'owner\' ELSE COALESCE(ps.role, \'comment\') END AS access_role
           FROM ' . $projectsTable . ' p
           LEFT JOIN ' . $sharesTable . ' ps ON ps.project_id = p.id AND ps.user_id = ?
          WHERE p.id = ? AND (p.user_id = ? OR ps.user_id = ?)
          LIMIT 1'
    );
    $stmt->execute([$userId, $userId, $projectId, $userId, $userId]);
    $project = $stmt->fetch();
    return $project ?: null;
}

function find_project_for_user_ref(string $projectRef, int $userId): ?array
{
    if (!preg_match('/^[A-Za-z0-9]{12}$/', $projectRef)) {
        return null;
    }

    $projectsTable = table_name('projects');
    $sharesTable = table_name('project_shares');
    $stmt = db()->prepare(
        'SELECT p.*, CASE WHEN p.user_id = ? THEN \'owner\' ELSE COALESCE(ps.role, \'comment\') END AS access_role
           FROM ' . $projectsTable . ' p
           LEFT JOIN ' . $sharesTable . ' ps ON ps.project_id = p.id AND ps.user_id = ?
          WHERE p.public_id = ? AND (p.user_id = ? OR ps.user_id = ?)
          LIMIT 1'
    );
    $stmt->execute([$userId, $userId, $projectRef, $userId, $userId]);
    $project = $stmt->fetch();
    return $project ?: null;
}

function user_owns_project(array $project, int $userId): bool
{
    return (int) $project['user_id'] === $userId;
}

function project_role_allows_edit(array $project, int $userId): bool
{
    return user_owns_project($project, $userId) || ($project['access_role'] ?? 'comment') === 'edit';
}

function project_source_type(array $project): string
{
    return ($project['source_type'] ?? 'zip') === 'url' ? 'url' : 'zip';
}

function project_is_url_source(array $project): bool
{
    return project_source_type($project) === 'url';
}

function html_page_title_for_project_file(array $project, string $file): string
{
    try {
        $path = safe_project_file($project, $file);
        $html = (string) file_get_contents($path);
    } catch (Throwable $e) {
        return '';
    }

    if (!preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $matches)) {
        return '';
    }

    $title = html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    return mb_substr($title, 0, 180);
}

function project_file_display_titles(array $project, array $files): array
{
    $titles = [];
    foreach ($files as $file) {
        $title = html_page_title_for_project_file($project, (string) $file);
        if ($title === '') {
            $title = pathinfo((string) $file, PATHINFO_FILENAME) ?: (string) $file;
        }
        $titles[(string) $file] = $title;
    }
    return $titles;
}

function project_file_copy_targets(array $project, array $files): array
{
    $targets = [];
    foreach ($files as $file) {
        $targets[(string) $file] = (string) $file;
    }
    if (!project_is_url_source($project)) {
        return $targets;
    }

    $map = url_project_map($project);
    $fileToUrl = is_array($map['file_to_url'] ?? null) ? $map['file_to_url'] : [];
    foreach ($fileToUrl as $file => $url) {
        $file = (string) $file;
        $url = trim((string) $url);
        if ($file !== '' && $url !== '') {
            $targets[$file] = $url;
        }
    }

    return $targets;
}

function comment_selector_tokens(string $selector): array
{
    $ids = [];
    $classes = [];
    if (preg_match_all('/#([A-Za-z0-9_-]+)/', $selector, $matches)) {
        $ids = array_values(array_unique($matches[1]));
    }
    if (preg_match_all('/\.([A-Za-z0-9_-]+)/', $selector, $matches)) {
        $classes = array_values(array_unique(array_filter(
            $matches[1],
            static fn (string $class): bool => !str_starts_with($class, 'webpatch-')
        )));
    }
    return ['ids' => $ids, 'classes' => $classes];
}

function html_matches_comment_selector_hint(string $html, string $selector): bool
{
    $tokens = comment_selector_tokens($selector);
    if ($tokens['ids'] === [] && $tokens['classes'] === []) {
        return true;
    }

    foreach ($tokens['ids'] as $id) {
        $quoted = preg_quote($id, '/');
        if (!preg_match('/\bid\s*=\s*(["\'])' . $quoted . '\1/i', $html)) {
            return false;
        }
    }

    foreach ($tokens['classes'] as $class) {
        $quoted = preg_quote($class, '/');
        if (!preg_match('/\bclass\s*=\s*(["\'])(?:(?!\1).)*\b' . $quoted . '\b(?:(?!\1).)*\1/is', $html)) {
            return false;
        }
    }

    return true;
}

function project_html_files(array $project): array
{
    $files = [];
    $root = project_root($project);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
        if (str_starts_with($relative, '_originals/')) {
            continue;
        }
        if (is_html_file($relative)) {
            $files[] = $relative;
        }
    }
    sort($files);
    return $files;
}

function is_project_sidebar_page_file(string $path): bool
{
    $normalized = str_replace('\\', '/', trim($path));
    if (!is_html_file($normalized)) {
        return false;
    }

    $segments = array_values(array_filter(explode('/', strtolower($normalized)), static fn (string $segment): bool => $segment !== ''));
    $excludedDirs = ['components', 'component', 'partials', 'partial', 'includes', 'include', 'templates', 'template'];
    foreach (array_slice($segments, 0, -1) as $segment) {
        if (in_array($segment, $excludedDirs, true)) {
            return false;
        }
    }

    $basename = basename($normalized);
    return !str_starts_with($basename, '_');
}

function project_sidebar_html_files(array $project): array
{
    $files = array_values(array_filter(
        project_html_files($project),
        static fn (string $file): bool => is_project_sidebar_page_file($file)
    ));

    if ($files === [] && !empty($project['entry_file']) && is_html_file((string) $project['entry_file'])) {
        $files[] = (string) $project['entry_file'];
    }

    return apply_project_page_order($project, $files);
}

function project_webpatch_metadata_dir(array $project): string
{
    return project_root($project) . '/_webpatch';
}

function project_page_order_path(array $project): string
{
    return project_webpatch_metadata_dir($project) . '/page-order.json';
}

function project_page_order(array $project): array
{
    $path = project_page_order_path($project);
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return [];
    }
    $order = $data['files'] ?? $data;
    if (!is_array($order)) {
        return [];
    }
    return array_values(array_filter(array_map('strval', $order), static fn (string $file): bool => $file !== ''));
}

function apply_project_page_order(array $project, array $files): array
{
    $files = array_values(array_unique(array_map('strval', $files)));
    sort($files);
    $available = array_fill_keys($files, true);
    $ordered = [];
    foreach (project_page_order($project) as $file) {
        if (isset($available[$file])) {
            $ordered[] = $file;
            unset($available[$file]);
        }
    }
    return array_merge($ordered, array_keys($available));
}

function save_project_page_order(array $project, array $requestedOrder): array
{
    $visibleFiles = project_sidebar_html_files($project);
    $visibleLookup = array_fill_keys($visibleFiles, true);
    $ordered = [];

    foreach ($requestedOrder as $file) {
        $file = normalize_zip_path((string) $file);
        if (isset($visibleLookup[$file])) {
            $ordered[] = $file;
            unset($visibleLookup[$file]);
        }
    }
    $ordered = array_merge(array_values(array_unique($ordered)), array_keys($visibleLookup));

    $dir = project_webpatch_metadata_dir($project);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('ページ順の保存ディレクトリを作成できませんでした。');
    }
    $path = project_page_order_path($project);
    $json = json_encode(['files' => $ordered], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('ページ順を保存できませんでした。');
    }
    chmod($path, 0640);

    return $ordered;
}

function remap_project_page_order_files(array $oldOrder, array $newHtmlFiles): array
{
    if ($oldOrder === [] || $newHtmlFiles === []) {
        return [];
    }

    $newFiles = array_values(array_unique(array_map('strval', $newHtmlFiles)));
    $newFileSet = array_fill_keys($newFiles, true);
    $byInnerPath = [];
    foreach ($newFiles as $newFile) {
        $innerPath = path_without_top_directory($newFile);
        if ($innerPath === '') {
            continue;
        }
        if (array_key_exists($innerPath, $byInnerPath)) {
            $byInnerPath[$innerPath] = null;
            continue;
        }
        $byInnerPath[$innerPath] = $newFile;
    }

    $remapped = [];
    foreach ($oldOrder as $oldFile) {
        $oldFile = (string) $oldFile;
        if (isset($newFileSet[$oldFile])) {
            $remapped[] = $oldFile;
            continue;
        }

        $innerPath = path_without_top_directory($oldFile);
        $newFile = $byInnerPath[$innerPath] ?? null;
        if (is_string($newFile)) {
            $remapped[] = $newFile;
        }
    }

    return array_values(array_unique($remapped));
}

function resolve_comment_file_for_selector(array $project, string $file, string $selector): string
{
    return $file;
}

function page_comment_marker_states_for_project(int $projectId, ?int $clientShareId = null, bool $normalOnly = false): array
{
    ensure_comment_confirmation_columns();
    ensure_comment_client_share_column();
    $where = 'WHERE project_id = ?
            AND parent_id IS NULL
            AND resolved_at IS NULL';
    $params = [$projectId];
    if ($clientShareId !== null) {
        $where .= ' AND client_share_id = ?';
        $params[] = $clientShareId;
    } elseif ($normalOnly) {
        $where .= ' AND client_share_id IS NULL';
    }
    $stmt = db()->prepare(
        'SELECT file_path,
                COUNT(*) AS open_count,
                SUM(CASE WHEN confirmation_pending_at IS NULL THEN 1 ELSE 0 END) AS needs_attention_count,
                SUM(CASE WHEN confirmation_pending_at IS NOT NULL THEN 1 ELSE 0 END) AS pending_count
           FROM ' . table_name('comments') . '
          ' . $where . '
          GROUP BY file_path'
    );
    $stmt->execute($params);

    $states = [];
    foreach ($stmt->fetchAll() as $row) {
        $needsAttention = (int) $row['needs_attention_count'];
        $pending = (int) $row['pending_count'];
        $open = (int) $row['open_count'];
        if ($needsAttention > 0) {
            $states[(string) $row['file_path']] = [
                'state' => 'attention',
                'count' => $open,
            ];
            continue;
        }
        if ($pending > 0) {
            $states[(string) $row['file_path']] = [
                'state' => 'pending',
                'count' => $pending,
            ];
        }
    }
    return $states;
}

function unresolved_comment_counts_for_project(int $projectId): array
{
    $states = page_comment_marker_states_for_project($projectId);
    $counts = [];
    foreach ($states as $file => $state) {
        $counts[$file] = (int) ($state['count'] ?? 0);
    }
    return $counts;
}

function normalize_project_share_role(string $role): string
{
    return $role === 'edit' ? 'edit' : 'comment';
}

function shared_users_for_project(int $projectId): array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.name, u.email, ps.role, ps.created_at
           FROM ' . table_name('project_shares') . ' ps
           INNER JOIN ' . table_name('users') . ' u ON u.id = ps.user_id
          WHERE ps.project_id = ?
          ORDER BY ps.created_at DESC'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

function public_link_for_project(int $projectId): ?array
{
    $stmt = db()->prepare(
        'SELECT *
           FROM ' . table_name('project_public_links') . '
          WHERE project_id = ?
          LIMIT 1'
    );
    $stmt->execute([$projectId]);
    $link = $stmt->fetch();
    return $link ?: null;
}

function public_project_for_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT p.*, pl.token AS public_token, pl.enabled AS public_link_enabled
           FROM ' . table_name('project_public_links') . ' pl
           INNER JOIN ' . table_name('projects') . ' p ON p.id = pl.project_id
          WHERE pl.token = ? AND pl.enabled = 1
          LIMIT 1'
    );
    $stmt->execute([$token]);
    $project = $stmt->fetch();
    return $project ?: null;
}

function public_project_for_ref(string $projectRef): ?array
{
    if (!preg_match('/^[A-Za-z0-9]{12}$/', $projectRef)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT p.*, pl.token AS public_token, pl.enabled AS public_link_enabled
           FROM ' . table_name('projects') . ' p
           INNER JOIN ' . table_name('project_public_links') . ' pl ON pl.project_id = p.id
          WHERE p.public_id = ? AND pl.enabled = 1
          LIMIT 1'
    );
    $stmt->execute([$projectRef]);
    $project = $stmt->fetch();
    return $project ?: null;
}

function ensure_public_project_link(int $projectId, int $createdBy): array
{
    $link = public_link_for_project($projectId);
    if ($link !== null) {
        if ((int) $link['enabled'] !== 1) {
            $stmt = db()->prepare('UPDATE ' . table_name('project_public_links') . ' SET enabled = 1 WHERE id = ?');
            $stmt->execute([(int) $link['id']]);
            $link['enabled'] = 1;
        }
        return $link;
    }

    $token = bin2hex(random_bytes(32));
    $stmt = db()->prepare(
        'INSERT INTO ' . table_name('project_public_links') . ' (project_id, token, created_by)
         VALUES (?, ?, ?)'
    );
    $stmt->execute([$projectId, $token, $createdBy]);

    return public_link_for_project($projectId) ?? [
        'project_id' => $projectId,
        'token' => $token,
        'enabled' => 1,
        'created_by' => $createdBy,
    ];
}

function disable_public_project_link(int $projectId): void
{
    $stmt = db()->prepare('UPDATE ' . table_name('project_public_links') . ' SET enabled = 0 WHERE project_id = ?');
    $stmt->execute([$projectId]);
}

function regenerate_public_project_link(int $projectId, int $createdBy): array
{
    $link = public_link_for_project($projectId);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $token = bin2hex(random_bytes(32));
        try {
            if ($link !== null) {
                $stmt = db()->prepare(
                    'UPDATE ' . table_name('project_public_links') . '
                        SET token = ?, enabled = 1, created_by = ?
                      WHERE project_id = ?'
                );
                $stmt->execute([$token, $createdBy, $projectId]);
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO ' . table_name('project_public_links') . ' (project_id, token, enabled, created_by)
                     VALUES (?, ?, 1, ?)'
                );
                $stmt->execute([$projectId, $token, $createdBy]);
            }
            break;
        } catch (PDOException $e) {
            if ($attempt === 4) {
                throw $e;
            }
        }
    }

    return public_link_for_project($projectId) ?? [
        'project_id' => $projectId,
        'token' => $token,
        'enabled' => 1,
        'created_by' => $createdBy,
    ];
}

function public_project_url(string $token, string $file = ''): string
{
    $path = 'public-project.php?token=' . rawurlencode($token);
    if ($file !== '') {
        $path .= '&file=' . rawurlencode($file);
    }
    return absolute_url($path);
}

function ensure_project_client_links_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS ' . table_name('project_client_links') . ' (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            label VARCHAR(160) NOT NULL,
            token CHAR(64) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY project_client_links_token_unique (token),
            KEY project_client_links_project_enabled_index (project_id, enabled),
            KEY project_client_links_created_by_index (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $done = true;
}

function ensure_comment_client_share_column(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!table_column_exists('comments', 'client_share_id')) {
        try {
            db()->exec('ALTER TABLE `' . table_name('comments') . '` ADD COLUMN client_share_id BIGINT UNSIGNED NULL AFTER project_id');
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1060) {
                throw $e;
            }
        }
    }

    try {
        db()->exec('ALTER TABLE `' . table_name('comments') . '` ADD INDEX comments_client_share_id_index (client_share_id)');
    } catch (PDOException $e) {
        if (!in_array((int) ($e->errorInfo[1] ?? 0), [1061, 1068], true)) {
            throw $e;
        }
    }

    $done = true;
}

function normalize_client_link_label(string $label): string
{
    $label = trim($label);
    if ($label === '') {
        throw new RuntimeException('クライアント共有リンク名を入力してください。');
    }
    return mb_substr($label, 0, 160);
}

function client_link_row_payload(array $link, array $project): array
{
    $files = project_sidebar_html_files($project);
    $file = $files[0] ?? (string) ($project['entry_file'] ?? '');
    return [
        'id' => (int) $link['id'],
        'label' => (string) $link['label'],
        'enabled' => (int) $link['enabled'] === 1,
        'url' => client_project_url((string) $link['token'], $file),
        'created_at' => $link['created_at'] ?? null,
        'updated_at' => $link['updated_at'] ?? null,
    ];
}

function client_links_for_project(int $projectId): array
{
    ensure_project_client_links_table();
    $stmt = db()->prepare(
        'SELECT *
           FROM ' . table_name('project_client_links') . '
          WHERE project_id = ?
          ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

function client_project_for_token(string $token): ?array
{
    ensure_project_client_links_table();
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT p.*, cl.id AS client_share_id, cl.label AS client_share_label, cl.token AS client_token, cl.enabled AS client_link_enabled
           FROM ' . table_name('project_client_links') . ' cl
           INNER JOIN ' . table_name('projects') . ' p ON p.id = cl.project_id
          WHERE cl.token = ? AND cl.enabled = 1
          LIMIT 1'
    );
    $stmt->execute([$token]);
    $project = $stmt->fetch();
    return $project ?: null;
}

function create_project_client_link(int $projectId, string $label, int $createdBy): array
{
    ensure_project_client_links_table();
    $label = normalize_client_link_label($label);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $token = bin2hex(random_bytes(32));
            $stmt = db()->prepare(
                'INSERT INTO ' . table_name('project_client_links') . ' (project_id, label, token, enabled, created_by)
                 VALUES (?, ?, ?, 1, ?)'
            );
            $stmt->execute([$projectId, $label, $token, $createdBy]);
            break;
        } catch (PDOException $e) {
            if ($attempt === 4 || (int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }
    }

    $stmt = db()->prepare('SELECT * FROM ' . table_name('project_client_links') . ' WHERE id = ? LIMIT 1');
    $stmt->execute([(int) db()->lastInsertId()]);
    $link = $stmt->fetch();
    if (!$link) {
        throw new RuntimeException('クライアント共有リンクを作成できませんでした。');
    }
    return $link;
}

function regenerate_project_client_link(int $linkId, int $projectId, int $createdBy): array
{
    ensure_project_client_links_table();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $token = bin2hex(random_bytes(32));
            $stmt = db()->prepare(
                'UPDATE ' . table_name('project_client_links') . '
                    SET token = ?, enabled = 1, created_by = ?
                  WHERE id = ? AND project_id = ?'
            );
            $stmt->execute([$token, $createdBy, $linkId, $projectId]);
            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('クライアント共有リンクが見つかりません。');
            }
            break;
        } catch (PDOException $e) {
            if ($attempt === 4 || (int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }
    }

    $stmt = db()->prepare('SELECT * FROM ' . table_name('project_client_links') . ' WHERE id = ? AND project_id = ? LIMIT 1');
    $stmt->execute([$linkId, $projectId]);
    $link = $stmt->fetch();
    if (!$link) {
        throw new RuntimeException('クライアント共有リンクが見つかりません。');
    }
    return $link;
}

function disable_project_client_link(int $linkId, int $projectId): void
{
    ensure_project_client_links_table();
    $stmt = db()->prepare(
        'UPDATE ' . table_name('project_client_links') . '
            SET enabled = 0
          WHERE id = ? AND project_id = ?'
    );
    $stmt->execute([$linkId, $projectId]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('クライアント共有リンクが見つかりません。');
    }
}

function delete_project_client_link(int $linkId, int $projectId): void
{
    ensure_project_client_links_table();
    $stmt = db()->prepare('DELETE FROM ' . table_name('project_client_links') . ' WHERE id = ? AND project_id = ?');
    $stmt->execute([$linkId, $projectId]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('クライアント共有リンクが見つかりません。');
    }
}

function client_project_url(string $token, string $file = ''): string
{
    $path = 'client-project?token=' . rawurlencode($token);
    if ($file !== '') {
        $path .= '&file=' . rawurlencode($file);
    }
    return absolute_url($path);
}

function ensure_comment_sheet_api_tokens_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $table = table_name('comment_sheet_api_tokens');
    db()->exec(
        'CREATE TABLE IF NOT EXISTS ' . $table . ' (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            token_prefix VARCHAR(16) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_used_at DATETIME NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_token_hash (token_hash),
            KEY idx_project_enabled (project_id, enabled),
            KEY idx_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}

function comment_sheet_api_token_meta(int $projectId): ?array
{
    ensure_comment_sheet_api_tokens_table();
    $stmt = db()->prepare(
        'SELECT id, token_prefix, enabled, last_used_at, created_at
           FROM ' . table_name('comment_sheet_api_tokens') . '
          WHERE project_id = ?
          ORDER BY enabled DESC, created_at DESC, id DESC
          LIMIT 1'
    );
    $stmt->execute([$projectId]);
    $token = $stmt->fetch();
    return $token ?: null;
}

function issue_comment_sheet_api_token(int $projectId, int $createdBy): array
{
    ensure_comment_sheet_api_tokens_table();
    $token = 'wps_' . bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $prefix = substr($token, 0, 12);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE ' . table_name('comment_sheet_api_tokens') . ' SET enabled = 0 WHERE project_id = ?');
        $stmt->execute([$projectId]);

        $stmt = $pdo->prepare(
            'INSERT INTO ' . table_name('comment_sheet_api_tokens') . ' (project_id, token_hash, token_prefix, created_by)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$projectId, $hash, $prefix, $createdBy]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'token' => $token,
        'token_prefix' => $prefix,
        'enabled' => 1,
        'last_used_at' => null,
        'created_at' => date('Y-m-d H:i:s'),
    ];
}

function disable_comment_sheet_api_token(int $projectId): void
{
    ensure_comment_sheet_api_tokens_table();
    $stmt = db()->prepare('UPDATE ' . table_name('comment_sheet_api_tokens') . ' SET enabled = 0 WHERE project_id = ?');
    $stmt->execute([$projectId]);
}

function project_for_comment_sheet_api_token(string $token): ?array
{
    if (!preg_match('/^wps_[A-Fa-f0-9]{64}$/', $token)) {
        return null;
    }

    ensure_comment_sheet_api_tokens_table();
    $stmt = db()->prepare(
        'SELECT p.*, api.id AS api_token_id
           FROM ' . table_name('comment_sheet_api_tokens') . ' api
           INNER JOIN ' . table_name('projects') . ' p ON p.id = api.project_id
          WHERE api.token_hash = ?
            AND api.enabled = 1
          LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $project = $stmt->fetch();
    return $project ?: null;
}

function pending_project_invite(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT i.*, p.title AS project_title
           FROM ' . table_name('project_invites') . ' i
           INNER JOIN ' . table_name('projects') . ' p ON p.id = i.project_id
          WHERE i.token_hash = ?
            AND i.accepted_at IS NULL
            AND i.expires_at > NOW()
          LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $invite = $stmt->fetch();
    return $invite ?: null;
}

function create_project_invite(int $projectId, string $email, int $createdBy, string $role = 'comment'): string
{
    $token = bin2hex(random_bytes(32));
    $role = normalize_project_share_role($role);
    $stmt = db()->prepare(
        'INSERT INTO ' . table_name('project_invites') . ' (project_id, email, token_hash, role, created_by, expires_at)
         VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))'
    );
    $stmt->execute([$projectId, mb_strtolower($email), hash('sha256', $token), $role, $createdBy]);
    return $token;
}

function accept_pending_project_invites_for_user(string $email, int $userId): void
{
    $stmt = db()->prepare(
        'SELECT id, project_id, role, created_by
           FROM ' . table_name('project_invites') . '
          WHERE email = ?
            AND accepted_at IS NULL
            AND expires_at > NOW()'
    );
    $stmt->execute([mb_strtolower($email)]);
    $invites = $stmt->fetchAll();

    if ($invites === []) {
        return;
    }

    $share = db()->prepare(
        'INSERT INTO ' . table_name('project_shares') . ' (project_id, user_id, role, created_by)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE role = VALUES(role)'
    );
    $accept = db()->prepare(
        'UPDATE ' . table_name('project_invites') . ' SET accepted_at = NOW() WHERE id = ?'
    );

    foreach ($invites as $invite) {
        $share->execute([(int) $invite['project_id'], $userId, normalize_project_share_role((string) $invite['role']), (int) $invite['created_by']]);
        $accept->execute([(int) $invite['id']]);
    }
}

function generate_note_public_id(): string
{
    $stmt = db()->prepare('SELECT 1 FROM ' . table_name('notes') . ' WHERE public_id = ? LIMIT 1');
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $publicId = random_alnum_id(12);
        $stmt->execute([$publicId]);
        if (!$stmt->fetchColumn()) {
            return $publicId;
        }
    }
    throw new RuntimeException('ノートIDを生成できませんでした。');
}

function note_public_ref(array $note): string
{
    return (string) ($note['public_id'] ?? $note['id']);
}

function note_path(array $note): string
{
    return 'note.php?id=' . rawurlencode(note_public_ref($note));
}

function note_path_by_id(int $noteId): string
{
    $stmt = db()->prepare('SELECT id, public_id FROM ' . table_name('notes') . ' WHERE id = ? LIMIT 1');
    $stmt->execute([$noteId]);
    $note = $stmt->fetch();
    return $note ? note_path($note) : 'notes.php';
}

function find_note_for_user(int $noteId, int $userId): ?array
{
    $notesTable = table_name('notes');
    $sharesTable = table_name('note_shares');
    $stmt = db()->prepare(
        'SELECT n.*, CASE WHEN n.user_id = ? THEN \'owner\' ELSE \'view\' END AS access_role
           FROM ' . $notesTable . ' n
           LEFT JOIN ' . $sharesTable . ' ns ON ns.note_id = n.id AND ns.user_id = ?
          WHERE n.id = ? AND (n.user_id = ? OR ns.user_id = ?)
          LIMIT 1'
    );
    $stmt->execute([$userId, $userId, $noteId, $userId, $userId]);
    $note = $stmt->fetch();
    return $note ?: null;
}

function find_note_for_user_ref(string $noteRef, int $userId): ?array
{
    if (!preg_match('/^[A-Za-z0-9]{12}$/', $noteRef)) {
        return null;
    }

    $notesTable = table_name('notes');
    $sharesTable = table_name('note_shares');
    $stmt = db()->prepare(
        'SELECT n.*, CASE WHEN n.user_id = ? THEN \'owner\' ELSE \'view\' END AS access_role
           FROM ' . $notesTable . ' n
           LEFT JOIN ' . $sharesTable . ' ns ON ns.note_id = n.id AND ns.user_id = ?
          WHERE n.public_id = ? AND (n.user_id = ? OR ns.user_id = ?)
          LIMIT 1'
    );
    $stmt->execute([$userId, $userId, $noteRef, $userId, $userId]);
    $note = $stmt->fetch();
    return $note ?: null;
}

function user_owns_note(array $note, int $userId): bool
{
    return (int) $note['user_id'] === $userId;
}

function shared_users_for_note(int $noteId): array
{
    $stmt = db()->prepare(
        'SELECT u.id, u.name, u.email, ns.created_at
           FROM ' . table_name('note_shares') . ' ns
           INNER JOIN ' . table_name('users') . ' u ON u.id = ns.user_id
          WHERE ns.note_id = ?
          ORDER BY ns.created_at DESC'
    );
    $stmt->execute([$noteId]);
    return $stmt->fetchAll();
}

function public_link_for_note(int $noteId): ?array
{
    $stmt = db()->prepare(
        'SELECT *
           FROM ' . table_name('note_public_links') . '
          WHERE note_id = ?
          LIMIT 1'
    );
    $stmt->execute([$noteId]);
    $link = $stmt->fetch();
    return $link ?: null;
}

function public_note_for_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT n.*, npl.token AS public_token, npl.enabled AS public_link_enabled
           FROM ' . table_name('note_public_links') . ' npl
           INNER JOIN ' . table_name('notes') . ' n ON n.id = npl.note_id
          WHERE npl.token = ? AND npl.enabled = 1
          LIMIT 1'
    );
    $stmt->execute([$token]);
    $note = $stmt->fetch();
    return $note ?: null;
}

function ensure_public_note_link(int $noteId, int $createdBy): array
{
    $link = public_link_for_note($noteId);
    if ($link !== null) {
        if ((int) $link['enabled'] !== 1) {
            $stmt = db()->prepare('UPDATE ' . table_name('note_public_links') . ' SET enabled = 1 WHERE id = ?');
            $stmt->execute([(int) $link['id']]);
            $link['enabled'] = 1;
        }
        return $link;
    }

    $token = bin2hex(random_bytes(32));
    $stmt = db()->prepare(
        'INSERT INTO ' . table_name('note_public_links') . ' (note_id, token, created_by)
         VALUES (?, ?, ?)'
    );
    $stmt->execute([$noteId, $token, $createdBy]);

    return public_link_for_note($noteId) ?? [
        'note_id' => $noteId,
        'token' => $token,
        'enabled' => 1,
        'created_by' => $createdBy,
    ];
}

function disable_public_note_link(int $noteId): void
{
    $stmt = db()->prepare('UPDATE ' . table_name('note_public_links') . ' SET enabled = 0 WHERE note_id = ?');
    $stmt->execute([$noteId]);
}

function regenerate_public_note_link(int $noteId, int $createdBy): array
{
    $link = public_link_for_note($noteId);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $token = bin2hex(random_bytes(32));
        try {
            if ($link !== null) {
                $stmt = db()->prepare(
                    'UPDATE ' . table_name('note_public_links') . '
                        SET token = ?, enabled = 1, created_by = ?
                      WHERE note_id = ?'
                );
                $stmt->execute([$token, $createdBy, $noteId]);
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO ' . table_name('note_public_links') . ' (note_id, token, enabled, created_by)
                     VALUES (?, ?, 1, ?)'
                );
                $stmt->execute([$noteId, $token, $createdBy]);
            }
            break;
        } catch (PDOException $e) {
            if ($attempt === 4) {
                throw $e;
            }
        }
    }

    return public_link_for_note($noteId) ?? [
        'note_id' => $noteId,
        'token' => $token,
        'enabled' => 1,
        'created_by' => $createdBy,
    ];
}

function public_note_url(string $token): string
{
    return absolute_url('public-note.php?token=' . rawurlencode($token));
}

function pending_note_invite(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT i.*, n.title AS note_title
           FROM ' . table_name('note_invites') . ' i
           INNER JOIN ' . table_name('notes') . ' n ON n.id = i.note_id
          WHERE i.token_hash = ?
            AND i.accepted_at IS NULL
            AND i.expires_at > NOW()
          LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $invite = $stmt->fetch();
    return $invite ?: null;
}

function create_note_invite(int $noteId, string $email, int $createdBy): string
{
    $token = bin2hex(random_bytes(32));
    $stmt = db()->prepare(
        'INSERT INTO ' . table_name('note_invites') . ' (note_id, email, token_hash, created_by, expires_at)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))'
    );
    $stmt->execute([$noteId, mb_strtolower($email), hash('sha256', $token), $createdBy]);
    return $token;
}

function accept_pending_note_invites_for_user(string $email, int $userId): void
{
    $stmt = db()->prepare(
        'SELECT id, note_id, created_by
           FROM ' . table_name('note_invites') . '
          WHERE email = ?
            AND accepted_at IS NULL
            AND expires_at > NOW()'
    );
    $stmt->execute([mb_strtolower($email)]);
    $invites = $stmt->fetchAll();

    if ($invites === []) {
        return;
    }

    $share = db()->prepare(
        'INSERT INTO ' . table_name('note_shares') . ' (note_id, user_id, created_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE created_by = VALUES(created_by)'
    );
    $accept = db()->prepare(
        'UPDATE ' . table_name('note_invites') . ' SET accepted_at = NOW() WHERE id = ?'
    );

    foreach ($invites as $invite) {
        $share->execute([(int) $invite['note_id'], $userId, (int) $invite['created_by']]);
        $accept->execute([(int) $invite['id']]);
    }
}

function accept_pending_invites_for_user(string $email, int $userId): void
{
    accept_pending_project_invites_for_user($email, $userId);
    accept_pending_note_invites_for_user($email, $userId);
}

function ai_provider_definitions(): array
{
    return [
        'openai' => [
            'label' => 'OpenAI',
            'models' => [
                'gpt-5.5' => 'GPT-5.5',
                'gpt-5.5-pro' => 'GPT-5.5 pro',
                'gpt-5.4' => 'GPT-5.4',
                'gpt-5.4-mini' => 'GPT-5.4 mini',
                'gpt-4.1' => 'GPT-4.1',
            ],
            'default_model' => 'gpt-5.5',
        ],
        'gemini' => [
            'label' => 'Gemini',
            'models' => [
                'gemini-3.1-pro-preview' => 'Gemini 3.1 Pro Preview',
                'gemini-3-flash-preview' => 'Gemini 3 Flash Preview',
                'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash-Lite',
                'gemini-2.5-pro' => 'Gemini 2.5 Pro',
                'gemini-2.5-flash' => 'Gemini 2.5 Flash',
            ],
            'default_model' => 'gemini-3.1-pro-preview',
        ],
        'grok' => [
            'label' => 'Grok',
            'models' => [
                'grok-4.3' => 'Grok 4.3',
                'grok-4.3-latest' => 'Grok 4.3 latest',
                'grok-latest' => 'Grok latest',
                'grok-4.20-multi-agent-0309' => 'Grok 4.20 Multi-Agent',
                'grok-4.20-0309-reasoning' => 'Grok 4.20 Reasoning',
                'grok-4.20-0309-non-reasoning' => 'Grok 4.20 Non-Reasoning',
            ],
            'default_model' => 'grok-4.3',
        ],
    ];
}

function normalize_ai_provider(string $provider): string
{
    return array_key_exists($provider, ai_provider_definitions()) ? $provider : '';
}

function normalize_ai_model(string $provider, string $model): string
{
    $providers = ai_provider_definitions();
    if (!isset($providers[$provider])) {
        return '';
    }
    return array_key_exists($model, $providers[$provider]['models']) ? $model : (string) $providers[$provider]['default_model'];
}

function ensure_ai_settings_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS ' . table_name('ai_settings') . ' (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            provider VARCHAR(32) NOT NULL,
            api_key_cipher TEXT NULL,
            api_key_hint VARCHAR(32) NOT NULL DEFAULT \'\',
            model VARCHAR(120) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_provider (user_id, provider),
            KEY idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}

function ai_settings_secret(): string
{
    $secret = (string) (app_config('key_encryption_secret') ?? '');
    if ($secret === '') {
        $database = app_config('database');
        $secret = (string) ($database['password'] ?? '') . '|' . __DIR__;
    }
    return hash('sha256', $secret, true);
}

function encrypt_ai_api_key(string $apiKey): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($apiKey, 'aes-256-gcm', ai_settings_secret(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false || $tag === '') {
        throw new RuntimeException('APIキーを暗号化できませんでした。');
    }
    return base64_encode($iv . $tag . $cipher);
}

function decrypt_ai_api_key(?string $cipherText): string
{
    if ($cipherText === null || $cipherText === '') {
        return '';
    }
    $raw = base64_decode($cipherText, true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', ai_settings_secret(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

function ai_key_hint(string $apiKey): string
{
    $length = strlen($apiKey);
    if ($length <= 8) {
        return str_repeat('*', $length);
    }
    return substr($apiKey, 0, 4) . '...' . substr($apiKey, -4);
}

function ai_settings_for_user(int $userId): array
{
    ensure_ai_settings_table();
    $providers = ai_provider_definitions();
    $settings = [];
    foreach ($providers as $provider => $definition) {
        $settings[$provider] = [
            'provider' => $provider,
            'model' => $definition['default_model'],
            'api_key_hint' => '',
            'has_api_key' => false,
        ];
    }

    $stmt = db()->prepare('SELECT provider, api_key_cipher, api_key_hint, model FROM ' . table_name('ai_settings') . ' WHERE user_id = ?');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $provider = normalize_ai_provider((string) $row['provider']);
        if ($provider === '') {
            continue;
        }
        $settings[$provider]['model'] = normalize_ai_model($provider, (string) $row['model']);
        $settings[$provider]['api_key_hint'] = (string) $row['api_key_hint'];
        $settings[$provider]['has_api_key'] = (string) ($row['api_key_cipher'] ?? '') !== '';
    }
    return $settings;
}

function save_ai_setting(int $userId, string $provider, string $model, string $apiKey, bool $clearKey = false): void
{
    $provider = normalize_ai_provider($provider);
    if ($provider === '') {
        throw new RuntimeException('AIプロバイダが不正です。');
    }
    $model = normalize_ai_model($provider, $model);
    ensure_ai_settings_table();

    $existing = db()->prepare('SELECT api_key_cipher, api_key_hint FROM ' . table_name('ai_settings') . ' WHERE user_id = ? AND provider = ? LIMIT 1');
    $existing->execute([$userId, $provider]);
    $row = $existing->fetch() ?: null;

    $cipher = $row['api_key_cipher'] ?? null;
    $hint = (string) ($row['api_key_hint'] ?? '');
    if ($clearKey) {
        $cipher = null;
        $hint = '';
    } elseif ($apiKey !== '') {
        $cipher = encrypt_ai_api_key($apiKey);
        $hint = ai_key_hint($apiKey);
    }

    $stmt = db()->prepare(
        'INSERT INTO ' . table_name('ai_settings') . ' (user_id, provider, api_key_cipher, api_key_hint, model)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE api_key_cipher = VALUES(api_key_cipher), api_key_hint = VALUES(api_key_hint), model = VALUES(model)'
    );
    $stmt->execute([$userId, $provider, $cipher, $hint, $model]);
}

function normalize_git_provider(string $provider): string
{
    $provider = strtolower(trim($provider));
    return in_array($provider, ['github', 'git'], true) ? $provider : 'github';
}

function normalize_git_repo_url(string $repoUrl): string
{
    $repoUrl = trim($repoUrl);
    if ($repoUrl === '') {
        return '';
    }
    if (strlen($repoUrl) > 500) {
        throw new RuntimeException('GitリポジトリURLが長すぎます。');
    }
    if (!preg_match('/^(https:\/\/|git@)[^\s]+$/i', $repoUrl)) {
        throw new RuntimeException('GitリポジトリURLは https:// または git@ で始まる形式で入力してください。');
    }
    return $repoUrl;
}

function normalize_git_branch(string $branch): string
{
    $branch = trim($branch);
    if ($branch === '') {
        return 'main';
    }
    if (strlen($branch) > 120 || preg_match('/[\s~^:?*\[\\\\]/', $branch) || str_contains($branch, '..') || str_starts_with($branch, '/') || str_ends_with($branch, '/') || str_ends_with($branch, '.')) {
        throw new RuntimeException('Gitブランチ名が不正です。');
    }
    return $branch;
}

function ensure_git_settings_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS ' . table_name('git_settings') . ' (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            provider VARCHAR(32) NOT NULL DEFAULT \'github\',
            repository_url VARCHAR(500) NOT NULL DEFAULT \'\',
            branch_name VARCHAR(120) NOT NULL DEFAULT \'main\',
            username VARCHAR(190) NOT NULL DEFAULT \'\',
            access_token_cipher TEXT NULL,
            access_token_hint VARCHAR(32) NOT NULL DEFAULT \'\',
            author_name VARCHAR(190) NOT NULL DEFAULT \'\',
            author_email VARCHAR(190) NOT NULL DEFAULT \'\',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_git_provider (user_id, provider),
            KEY idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}

function git_settings_for_user(int $userId): array
{
    ensure_git_settings_table();
    $defaults = [
        'provider' => 'github',
        'repository_url' => '',
        'branch_name' => 'main',
        'username' => '',
        'access_token_hint' => '',
        'has_access_token' => false,
        'author_name' => '',
        'author_email' => '',
    ];
    $stmt = db()->prepare('SELECT provider, repository_url, branch_name, username, access_token_cipher, access_token_hint, author_name, author_email FROM ' . table_name('git_settings') . ' WHERE user_id = ? AND provider = ? LIMIT 1');
    $stmt->execute([$userId, 'github']);
    $row = $stmt->fetch();
    if (!$row) {
        return $defaults;
    }
    return [
        'provider' => normalize_git_provider((string) $row['provider']),
        'repository_url' => (string) $row['repository_url'],
        'branch_name' => (string) $row['branch_name'],
        'username' => (string) $row['username'],
        'access_token_hint' => (string) $row['access_token_hint'],
        'has_access_token' => (string) ($row['access_token_cipher'] ?? '') !== '',
        'author_name' => (string) $row['author_name'],
        'author_email' => (string) $row['author_email'],
    ];
}

function git_access_token_for_user(int $userId, string $provider = 'github'): string
{
    ensure_git_settings_table();
    $provider = normalize_git_provider($provider);
    $stmt = db()->prepare('SELECT access_token_cipher FROM ' . table_name('git_settings') . ' WHERE user_id = ? AND provider = ? LIMIT 1');
    $stmt->execute([$userId, $provider]);
    $row = $stmt->fetch();
    return $row ? decrypt_ai_api_key($row['access_token_cipher'] ?? null) : '';
}

function save_git_setting(int $userId, array $input): void
{
    ensure_git_settings_table();
    $provider = normalize_git_provider((string) ($input['provider'] ?? 'github'));
    $repositoryUrl = normalize_git_repo_url((string) ($input['repository_url'] ?? ''));
    $branchName = normalize_git_branch((string) ($input['branch_name'] ?? 'main'));
    $username = trim((string) ($input['username'] ?? ''));
    $authorName = trim((string) ($input['author_name'] ?? ''));
    $authorEmail = trim((string) ($input['author_email'] ?? ''));
    $accessToken = trim((string) ($input['access_token'] ?? ''));
    $clearToken = !empty($input['clear_access_token']);

    if ($username !== '' && mb_strlen($username) > 190) {
        throw new RuntimeException('Gitユーザー名が長すぎます。');
    }
    if ($authorName !== '' && mb_strlen($authorName) > 190) {
        throw new RuntimeException('Gitコミット名が長すぎます。');
    }
    if ($authorEmail !== '' && (!filter_var($authorEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($authorEmail) > 190)) {
        throw new RuntimeException('Gitコミットメールアドレスが不正です。');
    }

    $existing = db()->prepare('SELECT access_token_cipher, access_token_hint FROM ' . table_name('git_settings') . ' WHERE user_id = ? AND provider = ? LIMIT 1');
    $existing->execute([$userId, $provider]);
    $row = $existing->fetch() ?: null;

    $cipher = $row['access_token_cipher'] ?? null;
    $hint = (string) ($row['access_token_hint'] ?? '');
    if ($clearToken) {
        $cipher = null;
        $hint = '';
    } elseif ($accessToken !== '') {
        $cipher = encrypt_ai_api_key($accessToken);
        $hint = ai_key_hint($accessToken);
    }

    $stmt = db()->prepare(
        'INSERT INTO ' . table_name('git_settings') . ' (user_id, provider, repository_url, branch_name, username, access_token_cipher, access_token_hint, author_name, author_email)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE repository_url = VALUES(repository_url), branch_name = VALUES(branch_name), username = VALUES(username), access_token_cipher = VALUES(access_token_cipher), access_token_hint = VALUES(access_token_hint), author_name = VALUES(author_name), author_email = VALUES(author_email)'
    );
    $stmt->execute([$userId, $provider, $repositoryUrl, $branchName, $username, $cipher, $hint, $authorName, $authorEmail]);
}

function ensure_project_git_settings_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS ' . table_name('project_git_settings') . ' (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            repository_url VARCHAR(500) NOT NULL DEFAULT \'\',
            branch_name VARCHAR(120) NOT NULL DEFAULT \'main\',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_project_id (project_id),
            KEY idx_project_id (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}

function project_git_settings(array $project): array
{
    ensure_project_git_settings_table();
    $defaults = [
        'repository_url' => '',
        'branch_name' => 'main',
    ];
    $stmt = db()->prepare('SELECT repository_url, branch_name FROM ' . table_name('project_git_settings') . ' WHERE project_id = ? LIMIT 1');
    $stmt->execute([(int) $project['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        return $defaults;
    }
    return [
        'repository_url' => (string) $row['repository_url'],
        'branch_name' => (string) $row['branch_name'],
    ];
}

function save_project_git_settings(int $projectId, string $repositoryUrl, string $branchName): array
{
    ensure_project_git_settings_table();
    $repositoryUrl = normalize_git_repo_url($repositoryUrl);
    $branchName = normalize_git_branch($branchName);

    $stmt = db()->prepare(
        'INSERT INTO ' . table_name('project_git_settings') . ' (project_id, repository_url, branch_name)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE repository_url = VALUES(repository_url), branch_name = VALUES(branch_name)'
    );
    $stmt->execute([$projectId, $repositoryUrl, $branchName]);

    return [
        'repository_url' => $repositoryUrl,
        'branch_name' => $branchName,
    ];
}

function ensure_ai_user_preferences_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS ' . table_name('ai_user_preferences') . ' (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            ai_check_provider VARCHAR(32) NOT NULL DEFAULT \'openai\',
            app_language VARCHAR(8) NOT NULL DEFAULT \'ja\',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    if (!table_column_exists('ai_user_preferences', 'app_language')) {
        $table = table_name('ai_user_preferences');
        try {
            db()->exec('ALTER TABLE `' . $table . '` ADD COLUMN app_language VARCHAR(8) NOT NULL DEFAULT \'ja\' AFTER ai_check_provider');
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1060) {
                throw $e;
            }
        }
    }
    $done = true;
}

function ai_check_provider_for_user(int $userId): string
{
    ensure_ai_user_preferences_table();
    $stmt = db()->prepare('SELECT ai_check_provider FROM ' . table_name('ai_user_preferences') . ' WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $provider = normalize_ai_provider((string) ($stmt->fetchColumn() ?: ''));
    return $provider !== '' ? $provider : 'openai';
}

function save_ai_check_provider_for_user(int $userId, string $provider): void
{
    $provider = normalize_ai_provider($provider);
    if ($provider === '') {
        throw new RuntimeException('AI確認で使うLLMが不正です。');
    }
    ensure_ai_user_preferences_table();
    $stmt = db()->prepare(
        'INSERT INTO ' . table_name('ai_user_preferences') . ' (user_id, ai_check_provider)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE ai_check_provider = VALUES(ai_check_provider)'
    );
    $stmt->execute([$userId, $provider]);
}

function normalize_app_language(string $language): string
{
    $language = strtolower(trim($language));
    return in_array($language, ['ja', 'en'], true) ? $language : 'ja';
}

function app_language_for_user(int $userId): string
{
    ensure_ai_user_preferences_table();
    $stmt = db()->prepare('SELECT app_language FROM ' . table_name('ai_user_preferences') . ' WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return normalize_app_language((string) ($stmt->fetchColumn() ?: 'ja'));
}

function save_app_language_for_user(int $userId, string $language): void
{
    $language = normalize_app_language($language);
    ensure_ai_user_preferences_table();
    $stmt = db()->prepare(
        'INSERT INTO ' . table_name('ai_user_preferences') . ' (user_id, app_language)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE app_language = VALUES(app_language)'
    );
    $stmt->execute([$userId, $language]);
}

function current_app_language(): string
{
    $user = current_user();
    return $user === null ? 'ja' : app_language_for_user((int) $user['id']);
}

function app_text(string $key, ?string $language = null): string
{
    $language = normalize_app_language($language ?? current_app_language());
    $texts = [
        'dashboard' => ['ja' => 'サイト一覧', 'en' => 'Sites'],
        'notes' => ['ja' => 'ノート', 'en' => 'Notes'],
        'account_settings' => ['ja' => 'アカウント設定', 'en' => 'Account Settings'],
        'main_navigation' => ['ja' => 'メインナビゲーション', 'en' => 'Main navigation'],
        'menu' => ['ja' => 'メニュー', 'en' => 'Menu'],
        'dashboard_aria' => ['ja' => 'WebPatch ダッシュボード', 'en' => 'WebPatch dashboard'],
    ];
    return $texts[$key][$language] ?? $texts[$key]['ja'] ?? $key;
}

function table_column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare(
        'SELECT 1
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1'
    );
    $stmt->execute([table_name($table), $column]);
    return (bool) $stmt->fetch();
}

function ensure_comment_ai_check_columns(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $table = table_name('comments');
    $columns = [
        'ai_check_status' => 'ALTER TABLE `' . $table . '` ADD COLUMN ai_check_status VARCHAR(24) NOT NULL DEFAULT \'unchecked\' AFTER desired_due_at',
        'ai_check_summary' => 'ALTER TABLE `' . $table . '` ADD COLUMN ai_check_summary TEXT NULL AFTER ai_check_status',
        'ai_checked_at' => 'ALTER TABLE `' . $table . '` ADD COLUMN ai_checked_at DATETIME NULL AFTER ai_check_summary',
        'ai_check_provider' => 'ALTER TABLE `' . $table . '` ADD COLUMN ai_check_provider VARCHAR(32) NULL AFTER ai_checked_at',
        'ai_check_model' => 'ALTER TABLE `' . $table . '` ADD COLUMN ai_check_model VARCHAR(120) NULL AFTER ai_check_provider',
    ];
    foreach ($columns as $column => $sql) {
        if (!table_column_exists('comments', $column)) {
            try {
                db()->exec($sql);
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1060) {
                    throw $e;
                }
            }
        }
    }

    $done = true;
}

function ensure_ai_check_jobs_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS ' . table_name('ai_check_jobs') . ' (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(32) NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'queued\',
            total_count INT UNSIGNED NOT NULL DEFAULT 0,
            processed_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            counts_json TEXT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            finished_at DATETIME NULL,
            UNIQUE KEY uniq_public_id (public_id),
            KEY idx_project_user (project_id, user_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}

function ensure_comment_confirmation_columns(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!table_column_exists('comments', 'confirmation_pending_at')) {
        try {
            db()->exec('ALTER TABLE `' . table_name('comments') . '` ADD COLUMN confirmation_pending_at DATETIME NULL AFTER resolved_at');
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1060) {
                throw $e;
            }
        }
    }

    $done = true;
}

function ensure_comment_viewport_column(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!table_column_exists('comments', 'viewport_mode')) {
        try {
            db()->exec('ALTER TABLE `' . table_name('comments') . '` ADD COLUMN viewport_mode VARCHAR(16) NULL AFTER selector');
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1060) {
                throw $e;
            }
        }
    }

    $done = true;
}

function ensure_comment_thread_reads_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS ' . table_name('comment_thread_reads') . ' (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id BIGINT UNSIGNED NOT NULL,
            thread_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            last_read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_thread_user (thread_id, user_id),
            KEY idx_project_user (project_id, user_id),
            KEY idx_thread_id (thread_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}

function normalize_comment_viewport_mode(string $mode): string
{
    return in_array($mode, ['desktop', 'tablet', 'mobile'], true) ? $mode : '';
}

function normalize_ai_check_status(string $status): string
{
    return in_array($status, ['unchecked', 'not_applicable', 'reflected', 'not_reflected', 'uncertain', 'error'], true)
        ? $status
        : 'uncertain';
}

function reset_comment_ai_checks(int $projectId, ?string $file = null, ?int $commentId = null): void
{
    ensure_comment_ai_check_columns();
    $where = 'project_id = ?';
    $params = [$projectId];
    if ($file !== null) {
        $where .= ' AND file_path = ?';
        $params[] = $file;
    }
    if ($commentId !== null) {
        $where .= ' AND id = ?';
        $params[] = $commentId;
    } else {
        $where .= ' AND parent_id IS NULL';
    }

    $stmt = db()->prepare(
        'UPDATE ' . table_name('comments') . '
            SET ai_check_status = \'unchecked\',
                ai_check_summary = NULL,
                ai_checked_at = NULL,
                ai_check_provider = NULL,
                ai_check_model = NULL
          WHERE ' . $where
    );
    $stmt->execute($params);
}

function reset_comment_ai_check_for_comment(int $projectId, int $commentId): void
{
    $stmt = db()->prepare('SELECT id, parent_id FROM ' . table_name('comments') . ' WHERE id = ? AND project_id = ? LIMIT 1');
    $stmt->execute([$commentId, $projectId]);
    $comment = $stmt->fetch();
    if (!$comment) {
        return;
    }
    reset_comment_ai_checks($projectId, null, (int) ($comment['parent_id'] ?: $comment['id']));
}

function ai_execution_settings_for_user(int $userId): array
{
    ensure_ai_settings_table();
    $stmt = db()->prepare('SELECT provider, api_key_cipher, model FROM ' . table_name('ai_settings') . ' WHERE user_id = ?');
    $stmt->execute([$userId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $provider = normalize_ai_provider((string) $row['provider']);
        if ($provider === '') {
            continue;
        }
        $apiKey = decrypt_ai_api_key($row['api_key_cipher'] ?? null);
        if ($apiKey === '') {
            continue;
        }
        $rows[$provider] = [
            'provider' => $provider,
            'model' => normalize_ai_model($provider, (string) $row['model']),
            'api_key' => $apiKey,
        ];
    }

    $ordered = [];
    foreach (['openai', 'gemini', 'grok'] as $provider) {
        if (isset($rows[$provider])) {
            $ordered[] = $rows[$provider];
        }
    }
    return $ordered;
}

function ai_check_execution_settings_for_user(int $userId): array
{
    ensure_ai_settings_table();
    $provider = ai_check_provider_for_user($userId);
    $stmt = db()->prepare('SELECT provider, api_key_cipher, model FROM ' . table_name('ai_settings') . ' WHERE user_id = ? AND provider = ? LIMIT 1');
    $stmt->execute([$userId, $provider]);
    $row = $stmt->fetch();
    if (!$row) {
        return [];
    }
    $apiKey = decrypt_ai_api_key($row['api_key_cipher'] ?? null);
    if ($apiKey === '') {
        return [];
    }
    return [[
        'provider' => $provider,
        'model' => normalize_ai_model($provider, (string) $row['model']),
        'api_key' => $apiKey,
    ]];
}

function ensure_project_user_settings_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    db()->exec(
        'CREATE TABLE IF NOT EXISTS ' . table_name('project_user_settings') . ' (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            copy_prompt TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_project_user (project_id, user_id),
            KEY idx_user_id (user_id),
            KEY idx_project_id (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}

function project_copy_prompt_for_user(int $projectId, int $userId): string
{
    ensure_project_user_settings_table();
    $stmt = db()->prepare(
        'SELECT copy_prompt
           FROM ' . table_name('project_user_settings') . '
          WHERE project_id = ? AND user_id = ?
          LIMIT 1'
    );
    $stmt->execute([$projectId, $userId]);
    return (string) ($stmt->fetchColumn() ?: '');
}

function save_project_copy_prompt_for_user(int $projectId, int $userId, string $copyPrompt): void
{
    ensure_project_user_settings_table();
    $copyPrompt = trim($copyPrompt);
    if (mb_strlen($copyPrompt) > 5000) {
        throw new RuntimeException('追加プロンプトは5000文字以内で入力してください。');
    }

    $stmt = db()->prepare(
        'INSERT INTO ' . table_name('project_user_settings') . ' (project_id, user_id, copy_prompt)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE copy_prompt = VALUES(copy_prompt)'
    );
    $stmt->execute([$projectId, $userId, $copyPrompt]);
}

function storage_root(): string
{
    return rtrim((string) app_config('storage_root'), '/');
}

function project_root(array $project): string
{
    return storage_root() . '/' . ltrim((string) $project['storage_path'], '/');
}

function original_project_root(array $project): string
{
    return storage_root() . '/_originals/' . (int) $project['id'];
}

function copy_directory_contents(string $source, string $destination): void
{
    if (!is_dir($source)) {
        throw new RuntimeException('コピー元ディレクトリが見つかりません。');
    }
    if (!is_dir($destination) && !mkdir($destination, 0750, true) && !is_dir($destination)) {
        throw new RuntimeException('原本保存ディレクトリを作成できませんでした。');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $target = $destination . '/' . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0750, true) && !is_dir($target)) {
                throw new RuntimeException('原本保存ディレクトリを作成できませんでした。');
            }
            continue;
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('原本保存ディレクトリを作成できませんでした。');
        }
        if (!copy($item->getPathname(), $target)) {
            throw new RuntimeException('原本ファイルを保存できませんでした。');
        }
        chmod($target, 0640);
    }
}

function delete_directory_recursive(string $path): void
{
    $storageRoot = realpath(storage_root());
    $realPath = realpath($path);
    if ($storageRoot === false || $realPath === false) {
        return;
    }
    if ($realPath === $storageRoot || !str_starts_with($realPath, $storageRoot . '/')) {
        throw new RuntimeException('削除対象ディレクトリが不正です。');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
            continue;
        }
        unlink($item->getPathname());
    }
    rmdir($realPath);
}

function ensure_original_project_snapshot(array $project): void
{
    $originalRoot = original_project_root($project);
    if (is_dir($originalRoot)) {
        return;
    }
    copy_directory_contents(project_root($project), $originalRoot);
}

function safe_original_project_file(array $project, string $file): string
{
    $normalized = normalize_zip_path($file);
    if (!is_html_file($normalized)) {
        throw new RuntimeException('HTMLファイルではありません。');
    }

    ensure_original_project_snapshot($project);
    $root = realpath(original_project_root($project));
    if ($root === false) {
        throw new RuntimeException('原本ファイルが見つかりません。');
    }

    $fullPath = realpath($root . '/' . $normalized);
    if ($fullPath === false || !str_starts_with($fullPath, $root . '/') || !is_file($fullPath)) {
        throw new RuntimeException('原本ファイルが見つかりません。');
    }

    return $fullPath;
}

function reset_project_file_to_original(array $project, string $file): void
{
    $source = safe_original_project_file($project, $file);
    $target = safe_project_file($project, $file);
    if (!copy($source, $target)) {
        throw new RuntimeException('ページをリセットできませんでした。');
    }
    chmod($target, 0640);
}

function delete_comment_images_for_comments(int $projectId, array $commentIds): void
{
    $commentIds = array_values(array_unique(array_filter(array_map('intval', $commentIds), static fn (int $id): bool => $id > 0)));
    if ($commentIds === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
    $stmt = db()->prepare(
        'SELECT storage_path
           FROM ' . table_name('comment_images') . '
          WHERE project_id = ? AND comment_id IN (' . $placeholders . ')'
    );
    $stmt->execute(array_merge([$projectId], $commentIds));

    $storageRoot = realpath(storage_root());
    foreach ($stmt->fetchAll() as $row) {
        $path = storage_root() . '/' . ltrim((string) $row['storage_path'], '/');
        $realPath = realpath($path);
        if ($storageRoot !== false && $realPath !== false && str_starts_with($realPath, $storageRoot . '/comment_images/') && is_file($realPath)) {
            @unlink($realPath);
        }
    }

    $stmt = db()->prepare(
        'DELETE FROM ' . table_name('comment_images') . '
          WHERE project_id = ? AND comment_id IN (' . $placeholders . ')'
    );
    $stmt->execute(array_merge([$projectId], $commentIds));

    foreach ($commentIds as $commentId) {
        $dir = storage_root() . '/comment_images/' . $projectId . '/' . $commentId;
        if (is_dir($dir)) {
            delete_directory_recursive($dir);
        }
    }
}

function delete_comments_for_project_file(int $projectId, string $file): void
{
    $stmt = db()->prepare('SELECT id FROM ' . table_name('comments') . ' WHERE project_id = ? AND file_path = ?');
    $stmt->execute([$projectId, $file]);
    $commentIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    if ($commentIds === []) {
        return;
    }

    delete_comment_images_for_comments($projectId, $commentIds);

    $stmt = db()->prepare('DELETE FROM ' . table_name('comments') . ' WHERE project_id = ? AND file_path = ?');
    $stmt->execute([$projectId, $file]);
}

function remove_empty_project_directories(array $project, string $directory): void
{
    $root = realpath(project_root($project));
    $dir = realpath($directory);
    if ($root === false || $dir === false) {
        return;
    }

    while ($dir !== $root && str_starts_with($dir, $root . '/')) {
        if (!@rmdir($dir)) {
            break;
        }
        $next = dirname($dir);
        if ($next === $dir) {
            break;
        }
        $dir = $next;
    }
}

function remove_project_file_from_url_map(array $project, string $file): void
{
    if (!project_is_url_source($project)) {
        return;
    }

    $path = url_project_map_path($project);
    if (!is_file($path)) {
        return;
    }

    $map = url_project_map($project);
    if (isset($map['file_to_url'][$file])) {
        unset($map['file_to_url'][$file]);
    }
    foreach (($map['url_to_file'] ?? []) as $url => $mappedFile) {
        if ((string) $mappedFile === $file) {
            unset($map['url_to_file'][$url]);
        }
    }

    file_put_contents($path, json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    chmod($path, 0640);
}

function delete_project_page(array $project, string $file): array
{
    $normalized = normalize_zip_path($file);
    if (!is_html_file($normalized)) {
        throw new RuntimeException('削除対象はHTMLファイルのみです。');
    }

    $files = project_html_files($project);
    if (!in_array($normalized, $files, true)) {
        throw new RuntimeException('削除対象のページが見つかりません。');
    }
    if (count($files) <= 1) {
        throw new RuntimeException('最後の1ページは削除できません。');
    }

    $nextFile = '';
    foreach ($files as $candidate) {
        if ($candidate !== $normalized) {
            $nextFile = $candidate;
            break;
        }
    }
    if ($nextFile === '') {
        throw new RuntimeException('削除後に表示するページが見つかりません。');
    }

    $target = safe_project_file($project, $normalized);
    $targetDir = dirname($target);
    if (!unlink($target)) {
        throw new RuntimeException('ページを削除できませんでした。');
    }

    remove_empty_project_directories($project, $targetDir);
    remove_project_file_from_url_map($project, $normalized);
    delete_comments_for_project_file((int) $project['id'], $normalized);

    if ((string) ($project['entry_file'] ?? '') === $normalized) {
        $stmt = db()->prepare('UPDATE ' . table_name('projects') . ' SET entry_file = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$nextFile, (int) $project['id']]);
    } else {
        $stmt = db()->prepare('UPDATE ' . table_name('projects') . ' SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([(int) $project['id']]);
    }

    return [
        'deleted_file' => $normalized,
        'next_file' => $nextFile,
    ];
}

function delete_project(array $project): void
{
    $projectId = (int) $project['id'];
    if ($projectId <= 0) {
        throw new RuntimeException('削除対象のサイトが不正です。');
    }

    ensure_comment_sheet_api_tokens_table();
    ensure_project_user_settings_table();
    ensure_project_git_settings_table();
    ensure_project_client_links_table();
    ensure_ai_check_jobs_table();
    ensure_comment_thread_reads_table();

    $stmt = db()->prepare('SELECT id FROM ' . table_name('comments') . ' WHERE project_id = ?');
    $stmt->execute([$projectId]);
    $commentIds = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    delete_comment_images_for_comments($projectId, $commentIds);

    $projectRoot = project_root($project);
    $originalRoot = original_project_root($project);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $tables = [
            'comment_sheet_api_tokens',
            'project_user_settings',
            'project_git_settings',
            'ai_check_jobs',
            'comment_thread_reads',
            'comments',
            'project_client_links',
            'project_public_links',
            'project_invites',
            'project_shares',
        ];
        foreach ($tables as $table) {
            $stmt = $pdo->prepare('DELETE FROM ' . table_name($table) . ' WHERE project_id = ?');
            $stmt->execute([$projectId]);
        }

        $stmt = $pdo->prepare('DELETE FROM ' . table_name('projects') . ' WHERE id = ?');
        $stmt->execute([$projectId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('サイトを削除できませんでした。');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if (is_dir($projectRoot)) {
        delete_directory_recursive($projectRoot);
    }
    if (is_dir($originalRoot)) {
        delete_directory_recursive($originalRoot);
    }
}

function normalize_zip_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = trim($path);
    while (str_starts_with($path, './')) {
        $path = substr($path, 2);
    }

    if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\\//', $path)) {
        throw new RuntimeException('ZIP内に不正なパスが含まれています。');
    }

    $parts = explode('/', $path);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.')) {
            throw new RuntimeException('ZIP内に不正なパスが含まれています。');
        }
    }

    return implode('/', $parts);
}

function path_without_top_directory(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $parts = array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== ''));
    if (count($parts) > 1) {
        return implode('/', array_slice($parts, 1));
    }

    return implode('/', $parts);
}

function is_ignorable_zip_entry(string $path): bool
{
    $path = str_replace('\\', '/', trim($path));
    while (str_starts_with($path, './')) {
        $path = substr($path, 2);
    }

    if ($path === '') {
        return true;
    }

    foreach (explode('/', $path) as $part) {
        if ($part === '__MACOSX' || $part === '.DS_Store' || str_starts_with($part, '._')) {
            return true;
        }
    }

    return false;
}

function is_rejected_extension(string $path): bool
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, [
        'php', 'phtml', 'phar', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh',
        'exe', 'com', 'bat', 'cmd', 'msi', 'jsp', 'asp', 'aspx',
    ], true);
}

function is_html_file(string $path): bool
{
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['html', 'htm'], true);
}

function safe_project_file(array $project, string $file): string
{
    $normalized = normalize_zip_path($file);
    if ($normalized === '_webpatch' || str_starts_with($normalized, '_webpatch/')) {
        throw new RuntimeException('このファイルは表示できません。');
    }
    if (is_rejected_extension($normalized)) {
        throw new RuntimeException('このファイルは表示できません。');
    }

    $root = realpath(project_root($project));
    if ($root === false) {
        throw new RuntimeException('プロジェクトファイルが見つかりません。');
    }

    $fullPath = realpath($root . '/' . $normalized);
    if ($fullPath === false || !str_starts_with($fullPath, $root . '/') || !is_file($fullPath)) {
        throw new RuntimeException('ファイルが見つかりません。');
    }

    return $fullPath;
}

function resolve_relative_file(string $currentFile, string $target): string
{
    $target = trim($target);
    if ($target === '' || preg_match('/^(?:https?:|\/\/|data:|mailto:|tel:|#|javascript:)/i', $target)) {
        return $target;
    }

    $withoutFragment = explode('#', $target, 2)[0];
    $withoutQuery = explode('?', $withoutFragment, 2)[0];
    $currentFile = normalize_zip_path($currentFile);
    $baseDir = dirname($currentFile);
    if ($baseDir === '.') {
        $baseDir = '';
    }
    $combined = str_starts_with($withoutQuery, '/')
        ? $withoutQuery
        : (($baseDir === '' ? '' : $baseDir . '/') . $withoutQuery);
    $normalized = ltrim(remove_dot_segments('/' . ltrim($combined, '/')), '/');
    if ($normalized === '') {
        return $currentFile;
    }

    return normalize_zip_path($normalized);
}

function should_rewrite_embedded_url(string $target): bool
{
    $target = trim($target);
    return $target !== '' && !preg_match('/^(?:https?:|\/\/|data:|mailto:|tel:|#|javascript:)/i', $target);
}

function should_skip_url_source_rewrite(string $target): bool
{
    $target = trim($target);
    return $target === '' || preg_match('/^(?:data:|mailto:|tel:|#|javascript:)/i', $target);
}

function route_for_embedded_file(int|string $projectId, string $file, bool $isLink): string
{
    $path = is_html_file($file) && $isLink ? 'preview.php' : 'asset.php';
    return base_url($path . '?id=' . rawurlencode((string) $projectId) . '&file=' . rawurlencode($file));
}

function url_project_map_path(array $project): string
{
    return project_root($project) . '/_webpatch/url-map.json';
}

function url_project_map(array $project): array
{
    $path = url_project_map_path($project);
    if (!is_file($path)) {
        return ['base_url' => '', 'host' => '', 'url_to_file' => [], 'file_to_url' => []];
    }
    $map = json_decode((string) file_get_contents($path), true);
    if (!is_array($map)) {
        return ['base_url' => '', 'host' => '', 'url_to_file' => [], 'file_to_url' => []];
    }
    $map['url_to_file'] = is_array($map['url_to_file'] ?? null) ? $map['url_to_file'] : [];
    $map['file_to_url'] = is_array($map['file_to_url'] ?? null) ? $map['file_to_url'] : [];
    return $map;
}

function url_project_saved_basic_auth(array $map): ?array
{
    $saved = $map['basic_auth'] ?? null;
    if (!is_array($saved)) {
        return null;
    }

    $username = trim((string) ($saved['username'] ?? ''));
    $password = (string) ($saved['password'] ?? '');
    if ($username === '' && $password === '') {
        return null;
    }
    if ($username === '') {
        return null;
    }

    return ['username' => $username, 'password' => $password];
}

function basic_auth_input_was_provided(array $basicAuth): bool
{
    return trim((string) ($basicAuth['username'] ?? '')) !== '' || (string) ($basicAuth['password'] ?? '') !== '';
}

function normalize_import_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
        return null;
    }

    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    $path = (string) ($parts['path'] ?? '/');
    if ($path === '') {
        $path = '/';
    }
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    $portPart = ($port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) ? ':' . $port : '';
    return $scheme . '://' . $host . $portPart . $path . $query;
}

function remove_dot_segments(string $path): string
{
    $output = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($output);
            continue;
        }
        $output[] = $segment;
    }
    return '/' . implode('/', $output);
}

function resolve_url_reference(string $baseUrl, string $target): string
{
    $target = trim($target);
    if (preg_match('/^https?:\/\//i', $target)) {
        return normalize_import_url($target) ?? $target;
    }

    $base = parse_url($baseUrl);
    if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
        return $target;
    }
    $scheme = strtolower((string) $base['scheme']);
    $host = strtolower((string) $base['host']);
    $port = isset($base['port']) ? ':' . (int) $base['port'] : '';

    if (str_starts_with($target, '//')) {
        return normalize_import_url($scheme . ':' . $target) ?? ($scheme . ':' . $target);
    }

    $fragment = '';
    $withoutFragment = $target;
    if (str_contains($withoutFragment, '#')) {
        [$withoutFragment, $fragmentRaw] = explode('#', $withoutFragment, 2);
        $fragment = '#' . $fragmentRaw;
    }

    $query = '';
    $withoutQuery = $withoutFragment;
    if (str_contains($withoutQuery, '?')) {
        [$withoutQuery, $queryRaw] = explode('?', $withoutQuery, 2);
        $query = '?' . $queryRaw;
    }

    if (str_starts_with($withoutQuery, '/')) {
        $path = remove_dot_segments($withoutQuery);
    } else {
        $basePath = (string) ($base['path'] ?? '/');
        $baseDir = str_ends_with($basePath, '/') ? $basePath : dirname($basePath) . '/';
        $path = remove_dot_segments($baseDir . $withoutQuery);
    }

    return $scheme . '://' . $host . $port . $path . $query . $fragment;
}

function url_project_preview_target(array $project, array $map, string $currentFile, string $target, bool $isLink, ?callable $routeBuilder): string
{
    $currentUrl = (string) ($map['file_to_url'][$currentFile] ?? '');
    if ($currentUrl === '') {
        return $target;
    }

    $absolute = resolve_url_reference($currentUrl, $target);
    $fragment = '';
    if (str_contains($absolute, '#')) {
        [$absoluteNoFragment, $fragmentRaw] = explode('#', $absolute, 2);
        $absolute = $absoluteNoFragment;
        $fragment = '#' . $fragmentRaw;
    }

    $normalized = normalize_import_url($absolute) ?? $absolute;
    if ($isLink && isset($map['url_to_file'][$normalized])) {
        $file = (string) $map['url_to_file'][$normalized];
        $route = $routeBuilder
            ? $routeBuilder(project_public_ref($project), $file, true)
            : route_for_embedded_file(project_public_ref($project), $file, true);
        return $route . $fragment;
    }

    return $normalized . $fragment;
}

function rewrite_url_project_inline_asset_references(string $content, array $project, array $map, string $currentFile, ?callable $routeBuilder): string
{
    return preg_replace_callback(
        '/([\'"`])((?:\\.\\.?\\/|\\/)[^\'"`\\s<>]+\\.(?:css|js|mjs|png|jpe?g|gif|webp|svg|ico|avif|woff2?|ttf|otf|mp4|webm|mov)(?:\\?[^\'"`\\s<>]*)?)(\\1)/i',
        static function (array $matches) use ($project, $map, $currentFile, $routeBuilder): string {
            $quote = $matches[1];
            $target = $matches[2];
            $rewritten = url_project_preview_target($project, $map, $currentFile, $target, false, $routeBuilder);
            return $quote . $rewritten . $quote;
        },
        $content
    ) ?? $content;
}

function decode_non_ascii_numeric_entities(string $html): string
{
    return preg_replace_callback('/&#(x[0-9A-Fa-f]+|[0-9]+);/', static function (array $matches): string {
        $raw = $matches[1];
        $codepoint = str_starts_with(strtolower($raw), 'x')
            ? hexdec(substr($raw, 1))
            : (int) $raw;

        if ($codepoint < 128 || $codepoint > 0x10FFFF) {
            return $matches[0];
        }

        return mb_chr($codepoint, 'UTF-8');
    }, $html) ?? $html;
}

function rewrite_html_for_preview(string $html, array $project, string $currentFile, ?callable $routeBuilder = null): string
{
    $projectId = project_public_ref($project);
    $isUrlProject = project_is_url_source($project);
    $urlMap = $isUrlProject ? url_project_map($project) : [];
    $inlineScripts = [];
    $html = preg_replace_callback(
        '/(<script\b(?![^>]*\bsrc\s*=)[^>]*>)(.*?)(<\/script>)/is',
        static function (array $matches) use (&$inlineScripts): string {
            $placeholder = '__WEBPATCH_INLINE_SCRIPT_' . count($inlineScripts) . '__';
            $inlineScripts[$placeholder] = $matches[2];
            return $matches[1] . $placeholder . $matches[3];
        },
        $html
    ) ?? $html;
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NODEFDTD);

    foreach ($dom->getElementsByTagName('*') as $element) {
        foreach (['src', 'poster'] as $attr) {
            if (!$element->hasAttribute($attr)) {
                continue;
            }
            $target = $element->getAttribute($attr);
            if ($isUrlProject && !should_skip_url_source_rewrite($target)) {
                $element->setAttribute('data-webpatch-original-' . $attr, $target);
                $element->setAttribute($attr, url_project_preview_target($project, $urlMap, $currentFile, $target, false, $routeBuilder));
            } elseif (!$isUrlProject && should_rewrite_embedded_url($target)) {
                $resolved = resolve_relative_file($currentFile, $target);
                $element->setAttribute('data-webpatch-original-' . $attr, $target);
                $element->setAttribute($attr, $routeBuilder ? $routeBuilder($projectId, $resolved, false) : route_for_embedded_file($projectId, $resolved, false));
            }
        }

        if ($element->hasAttribute('href')) {
            $target = $element->getAttribute('href');
            if ($isUrlProject && !should_skip_url_source_rewrite($target)) {
                $element->setAttribute('data-webpatch-original-href', $target);
                $element->setAttribute('href', url_project_preview_target($project, $urlMap, $currentFile, $target, true, $routeBuilder));
            } elseif (!$isUrlProject && should_rewrite_embedded_url($target)) {
                $resolved = resolve_relative_file($currentFile, $target);
                $element->setAttribute('data-webpatch-original-href', $target);
                $element->setAttribute('href', $routeBuilder ? $routeBuilder($projectId, $resolved, true) : route_for_embedded_file($projectId, $resolved, true));
            }
        }

        if ($element->hasAttribute('srcset')) {
            $originalSrcset = $element->getAttribute('srcset');
            $items = array_map('trim', explode(',', $element->getAttribute('srcset')));
            $rewritten = [];
            $changed = false;
            foreach ($items as $item) {
                if ($item === '') {
                    continue;
                }
                $parts = preg_split('/\s+/', $item, 2);
                $url = $parts[0];
                if ($isUrlProject && !should_skip_url_source_rewrite($parts[0])) {
                    $url = url_project_preview_target($project, $urlMap, $currentFile, $parts[0], false, $routeBuilder);
                    $changed = true;
                } elseif (!$isUrlProject && should_rewrite_embedded_url($parts[0])) {
                    $resolved = resolve_relative_file($currentFile, $parts[0]);
                    $url = $routeBuilder ? $routeBuilder($projectId, $resolved, false) : route_for_embedded_file($projectId, $resolved, false);
                    $changed = true;
                }
                $rewritten[] = isset($parts[1]) ? $url . ' ' . $parts[1] : $url;
            }
            if ($changed) {
                $element->setAttribute('data-webpatch-original-srcset', $originalSrcset);
            }
            $element->setAttribute('srcset', implode(', ', $rewritten));
        }

        // Do not rewrite inline script bodies here. Replacing script text through
        // DOMDocument can corrupt JS strings containing HTML fragments.
    }

    if ($dom->documentElement instanceof DOMElement) {
        $dom->documentElement->setAttribute('data-webpatch-file', $currentFile);
    }

    $output = $dom->saveHTML();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $rewritten = str_replace('<?xml encoding="UTF-8">', '', $output ?? $html);
    foreach ($inlineScripts as $placeholder => $script) {
        $rewritten = str_replace($placeholder, $script, $rewritten);
    }
    return decode_non_ascii_numeric_entities($rewritten);
}

function csv_upload_rows(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('URLリストCSVを選択してください。');
    }
    if (($file['size'] ?? 0) > 1048576) {
        throw new RuntimeException('URLリストCSVは1MBまでです。');
    }

    $handle = fopen((string) $file['tmp_name'], 'rb');
    if ($handle === false) {
        throw new RuntimeException('URLリストCSVを読み込めませんでした。');
    }

    $urls = [];
    $line = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $line++;
        if ($line === 1 && isset($row[0])) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]) ?? (string) $row[0];
            if (mb_strtolower(trim((string) $row[0])) === 'url') {
                continue;
            }
        }
        $url = trim((string) ($row[0] ?? ''));
        if ($url !== '') {
            $urls[] = $url;
        }
    }
    fclose($handle);

    return $urls;
}

function safe_url_file_segment(string $segment): string
{
    $segment = rawurldecode($segment);
    $segment = preg_replace('/[^A-Za-z0-9._-]+/', '_', $segment) ?? '';
    $segment = trim($segment, '._-');
    if ($segment === '') {
        $segment = 'page';
    }
    if (str_starts_with($segment, '.')) {
        $segment = '_' . ltrim($segment, '.');
    }
    return $segment;
}

function file_path_for_import_url(string $url, array &$used): string
{
    $parts = parse_url($url);
    $path = (string) ($parts['path'] ?? '/');
    $query = (string) ($parts['query'] ?? '');
    $path = remove_dot_segments($path === '' ? '/' : $path);
    $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => $segment !== ''));
    $endsAsDirectory = $path === '/' || str_ends_with($path, '/') || pathinfo(end($segments) ?: '', PATHINFO_EXTENSION) === '';

    if ($endsAsDirectory) {
        $segments[] = 'index.html';
    } else {
        $last = array_pop($segments);
        $base = safe_url_file_segment(pathinfo((string) $last, PATHINFO_FILENAME));
        $segments[] = $base . '.html';
    }

    $safe = array_map('safe_url_file_segment', $segments);
    $lastIndex = count($safe) - 1;
    if ($query !== '') {
        $base = pathinfo($safe[$lastIndex], PATHINFO_FILENAME);
        $safe[$lastIndex] = $base . '-' . substr(hash('sha256', $url), 0, 8) . '.html';
    }

    $candidate = implode('/', $safe);
    while (isset($used[$candidate])) {
        $base = pathinfo($safe[$lastIndex], PATHINFO_FILENAME);
        $safe[$lastIndex] = $base . '-' . substr(hash('sha256', $url . count($used)), 0, 8) . '.html';
        $candidate = implode('/', $safe);
    }
    $used[$candidate] = true;
    return normalize_zip_path($candidate);
}

function normalize_basic_auth_credentials(array $basicAuth): ?array
{
    $username = trim((string) ($basicAuth['username'] ?? ''));
    $password = (string) ($basicAuth['password'] ?? '');
    if ($username === '' && $password === '') {
        return null;
    }
    if ($username === '') {
        throw new RuntimeException('Basic認証ユーザー名を入力してください。');
    }

    return ['username' => $username, 'password' => $password];
}

function fetch_import_html(string $url, ?array $basicAuth = null): array
{
    $buffer = '';
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('URL取得を開始できませんでした。');
    }
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT => WEBPATCH_URL_FETCH_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => WEBPATCH_URL_FETCH_TIMEOUT,
        CURLOPT_USERAGENT => 'WebPatch URL Importer/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer): int {
            $buffer .= $chunk;
            if (strlen($buffer) > WEBPATCH_MAX_URL_HTML_BYTES) {
                return 0;
            }
            return strlen($chunk);
        },
    ]);
    if ($basicAuth !== null) {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, (string) $basicAuth['username'] . ':' . (string) $basicAuth['password']);
    }
    curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effectiveUrl = normalize_import_url((string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)) ?? $url;
    curl_close($ch);

    if ($error !== '') {
        throw new RuntimeException('取得失敗');
    }
    if ($status !== 200) {
        throw new RuntimeException('HTTP ' . $status);
    }
    if (!preg_match('/(?:text\/html|application\/xhtml\+xml)/i', $contentType)) {
        throw new RuntimeException('HTMLではありません');
    }
    if (trim($buffer) === '') {
        throw new RuntimeException('HTMLが空です');
    }

    return ['html' => $buffer, 'effective_url' => $effectiveUrl, 'content_type' => $contentType];
}

function upload_url_project(array $csvFile, string $title, string $baseUrl, int $userId, array $basicAuth = []): array
{
    $baseUrl = normalize_import_url($baseUrl) ?? '';
    $baseParts = $baseUrl !== '' ? parse_url($baseUrl) : false;
    if (!is_array($baseParts) || empty($baseParts['host'])) {
        throw new RuntimeException('基準URLを正しく入力してください。');
    }
    $baseHost = strtolower((string) $baseParts['host']);
    $rows = csv_upload_rows($csvFile);
    if ($rows === []) {
        throw new RuntimeException('CSVにURLがありません。');
    }
    if (count($rows) > WEBPATCH_MAX_URL_IMPORTS) {
        throw new RuntimeException('URLリストCSVは最大' . WEBPATCH_MAX_URL_IMPORTS . '件までです。');
    }
    $basicAuthCredentials = normalize_basic_auth_credentials($basicAuth);

    $urls = [];
    $skipped = [];
    foreach ($rows as $rawUrl) {
        $normalized = normalize_import_url($rawUrl);
        if ($normalized === null) {
            $skipped[] = ['url' => $rawUrl, 'reason' => 'URL形式が不正'];
            continue;
        }
        $host = strtolower((string) (parse_url($normalized, PHP_URL_HOST) ?? ''));
        if ($host !== $baseHost) {
            $skipped[] = ['url' => $rawUrl, 'reason' => '別ドメイン'];
            continue;
        }
        if (isset($urls[$normalized])) {
            $skipped[] = ['url' => $rawUrl, 'reason' => '重複'];
            continue;
        }
        $urls[$normalized] = true;
    }

    if ($urls === []) {
        throw new RuntimeException('登録できる同一ドメインURLがありません。');
    }

    $storageKey = 'projects/' . $userId . '/' . bin2hex(random_bytes(16));
    $targetRoot = storage_root() . '/' . $storageKey;
    if (!mkdir($targetRoot, 0750, true) && !is_dir($targetRoot)) {
        throw new RuntimeException('保存ディレクトリを作成できませんでした。');
    }

    $usedFiles = [];
    $urlToFile = [];
    $fileToUrl = [];
    $entryFile = '';
    foreach (array_keys($urls) as $url) {
        try {
            $fetched = fetch_import_html($url, $basicAuthCredentials);
            $effectiveUrl = normalize_import_url((string) $fetched['effective_url']) ?? $url;
            $effectiveHost = strtolower((string) (parse_url($effectiveUrl, PHP_URL_HOST) ?? ''));
            if ($effectiveHost !== $baseHost) {
                throw new RuntimeException('別ドメインへリダイレクト');
            }
            $filePath = file_path_for_import_url($effectiveUrl, $usedFiles);
            $target = $targetRoot . '/' . $filePath;
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException('保存先ディレクトリを作成できませんでした。');
            }
            file_put_contents($target, (string) $fetched['html'], LOCK_EX);
            chmod($target, 0640);
            $urlToFile[$url] = $filePath;
            $urlToFile[$effectiveUrl] = $filePath;
            $fileToUrl[$filePath] = $effectiveUrl;
            if ($entryFile === '') {
                $entryFile = $filePath;
            }
        } catch (Throwable $e) {
            $skipped[] = ['url' => $url, 'reason' => $e->getMessage()];
        }
    }

    if ($entryFile === '') {
        throw new RuntimeException('取得できたHTMLページがありません。');
    }

    $metadataDir = $targetRoot . '/_webpatch';
    if (!is_dir($metadataDir) && !mkdir($metadataDir, 0750, true) && !is_dir($metadataDir)) {
        throw new RuntimeException('管理ファイルを保存できませんでした。');
    }
    file_put_contents($metadataDir . '/url-map.json', json_encode([
        'base_url' => $baseUrl,
        'host' => $baseHost,
        'url_to_file' => $urlToFile,
        'file_to_url' => $fileToUrl,
        'basic_auth' => $basicAuthCredentials,
        'skipped' => $skipped,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    chmod($metadataDir . '/url-map.json', 0640);

    $projectTitle = trim($title) !== '' ? trim($title) : $baseHost;
    $stmt = db()->prepare('INSERT INTO ' . table_name('projects') . ' (public_id, user_id, title, original_filename, entry_file, storage_path, source_type) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([generate_project_public_id(), $userId, mb_substr($projectTitle, 0, 160), $baseUrl, $entryFile, $storageKey, 'url']);

    $projectId = (int) db()->lastInsertId();
    ensure_original_project_snapshot(['id' => $projectId, 'storage_path' => $storageKey]);

    return ['project_id' => $projectId, 'imported' => count($fileToUrl), 'skipped' => $skipped];
}

function refresh_url_project(array $project, array $basicAuth = []): array
{
    if (!project_is_url_source($project)) {
        throw new RuntimeException('URL登録サイトではありません。');
    }

    $map = url_project_map($project);
    $fileToUrl = is_array($map['file_to_url'] ?? null) ? $map['file_to_url'] : [];
    if ($fileToUrl === []) {
        throw new RuntimeException('再取得するURL情報が見つかりません。');
    }

    $baseHost = strtolower((string) ($map['host'] ?? ''));
    if ($baseHost === '') {
        $baseHost = strtolower((string) (parse_url((string) ($map['base_url'] ?? ''), PHP_URL_HOST) ?? ''));
    }
    if ($baseHost === '') {
        throw new RuntimeException('基準ドメインを確認できません。');
    }

    $newBasicAuthProvided = basic_auth_input_was_provided($basicAuth);
    $savedBasicAuthCredentials = url_project_saved_basic_auth($map);
    $basicAuthCredentials = $newBasicAuthProvided
        ? normalize_basic_auth_credentials($basicAuth)
        : $savedBasicAuthCredentials;
    $updatedFiles = [];
    $urlToFile = is_array($map['url_to_file'] ?? null) ? $map['url_to_file'] : [];
    $skipped = [];
    $removedFiles = [];

    foreach ($fileToUrl as $file => $url) {
        $file = normalize_zip_path((string) $file);
        $url = normalize_import_url((string) $url);
        if ($url === null || !is_html_file($file)) {
            $skipped[] = ['url' => (string) $url, 'reason' => 'URL情報が不正'];
            continue;
        }

        try {
            $fetched = fetch_import_html($url, $basicAuthCredentials);
            $effectiveUrl = normalize_import_url((string) $fetched['effective_url']) ?? $url;
            $effectiveHost = strtolower((string) (parse_url($effectiveUrl, PHP_URL_HOST) ?? ''));
            if ($effectiveHost !== $baseHost) {
                throw new RuntimeException('別ドメインへリダイレクト');
            }

            $root = realpath(project_root($project));
            if ($root === false) {
                throw new RuntimeException('プロジェクトファイルが見つかりません。');
            }
            $target = $root . '/' . $file;
            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
                throw new RuntimeException('保存ディレクトリを作成できませんでした。');
            }
            $realTargetDir = realpath($targetDir);
            if ($realTargetDir === false || ($realTargetDir !== $root && !str_starts_with($realTargetDir, $root . '/'))) {
                throw new RuntimeException('保存先が不正です。');
            }
            file_put_contents($target, (string) $fetched['html'], LOCK_EX);
            chmod($target, 0640);
            $updatedFiles[] = $file;
            $urlToFile[$url] = $file;
            $urlToFile[$effectiveUrl] = $file;
            $fileToUrl[$file] = $effectiveUrl;
        } catch (Throwable $e) {
            if (in_array($e->getMessage(), ['HTTP 404', 'HTTP 410'], true)) {
                $removedFiles[$file] = $url;
            }
            $skipped[] = ['url' => $url, 'reason' => $e->getMessage()];
        }
    }

    $remainingFileToUrl = $fileToUrl;
    foreach ($removedFiles as $file => $url) {
        unset($remainingFileToUrl[$file]);
    }
    if ($remainingFileToUrl === []) {
        $removedFiles = [];
        $remainingFileToUrl = $fileToUrl;
    }

    foreach ($removedFiles as $file => $url) {
        unset($fileToUrl[$file]);
        foreach ($urlToFile as $mappedUrl => $mappedFile) {
            if ((string) $mappedFile === (string) $file) {
                unset($urlToFile[$mappedUrl]);
            }
        }
        try {
            $target = safe_project_file($project, $file);
            unlink($target);
        } catch (Throwable $e) {
        }
    }

    if ($updatedFiles === [] && $removedFiles === []) {
        throw new RuntimeException('再取得できたHTMLページがありません。');
    }

    $entryFile = (string) $project['entry_file'];
    if (!isset($fileToUrl[$entryFile])) {
        $entryFile = (string) array_key_first($fileToUrl);
    }

    $map['url_to_file'] = $urlToFile;
    $map['file_to_url'] = $fileToUrl;
    if ($newBasicAuthProvided) {
        $map['basic_auth'] = $basicAuthCredentials;
    } elseif ($savedBasicAuthCredentials !== null) {
        $map['basic_auth'] = $savedBasicAuthCredentials;
    }
    $map['skipped'] = $skipped;
    file_put_contents(url_project_map_path($project), json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    chmod(url_project_map_path($project), 0640);

    $stmt = db()->prepare('UPDATE ' . table_name('projects') . ' SET entry_file = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$entryFile, (int) $project['id']]);

    return ['updated' => count($updatedFiles), 'removed' => count($removedFiles), 'skipped' => $skipped, 'entry_file' => $entryFile];
}

function extract_zip_upload_to_storage(array $file, int $userId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('ZIPファイルを選択してください。');
    }
    if (($file['size'] ?? 0) > WEBPATCH_MAX_UPLOAD_BYTES) {
        throw new RuntimeException('アップロードできるZIPは100MBまでです。');
    }
    if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'zip') {
        throw new RuntimeException('HTMLとアセットを含むZIPファイルをアップロードしてください。');
    }

    $zip = new ZipArchive();
    if ($zip->open((string) $file['tmp_name']) !== true) {
        throw new RuntimeException('ZIPファイルを読み込めませんでした。');
    }

    $entries = [];
    $htmlFiles = [];
    $totalBytes = 0;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) {
            continue;
        }

        $name = (string) $stat['name'];
        if (str_ends_with($name, '/')) {
            continue;
        }
        if (is_ignorable_zip_entry($name)) {
            continue;
        }

        $normalized = normalize_zip_path($name);
        if (is_rejected_extension($normalized)) {
            continue;
        }

        $size = (int) ($stat['size'] ?? 0);
        $totalBytes += $size;
        if ($totalBytes > WEBPATCH_MAX_EXTRACTED_BYTES) {
            throw new RuntimeException('展開後のファイルサイズが大きすぎます。');
        }

        $entries[] = ['index' => $i, 'path' => $normalized];
        if (is_html_file($normalized)) {
            $htmlFiles[] = $normalized;
        }
    }

    if ($htmlFiles === []) {
        throw new RuntimeException('ZIP内にHTMLファイルが見つかりません。');
    }

    usort($htmlFiles, static function (string $a, string $b): int {
        $score = static fn(string $path): int => strtolower(basename($path)) === 'index.html' ? 0 : substr_count($path, '/');
        return $score($a) <=> $score($b) ?: strcmp($a, $b);
    });

    $storageKey = 'projects/' . $userId . '/' . bin2hex(random_bytes(16));
    $targetRoot = storage_root() . '/' . $storageKey;
    if (!mkdir($targetRoot, 0750, true) && !is_dir($targetRoot)) {
        throw new RuntimeException('保存ディレクトリを作成できませんでした。');
    }

    foreach ($entries as $entry) {
        $target = $targetRoot . '/' . $entry['path'];
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('展開先ディレクトリを作成できませんでした。');
        }

        $source = $zip->getStream($zip->getNameIndex($entry['index']));
        if ($source === false) {
            throw new RuntimeException('ZIP内のファイルを読み込めませんでした。');
        }
        $dest = fopen($target, 'wb');
        if ($dest === false) {
            fclose($source);
            throw new RuntimeException('ファイルを保存できませんでした。');
        }
        stream_copy_to_stream($source, $dest);
        fclose($source);
        fclose($dest);
        chmod($target, 0640);
    }
    $zip->close();

    return [
        'storage_key' => $storageKey,
        'target_root' => $targetRoot,
        'entry_file' => $htmlFiles[0],
        'html_files' => $htmlFiles,
        'original_filename' => (string) $file['name'],
    ];
}

function remap_project_comment_file_paths(int $projectId, array $newHtmlFiles): void
{
    if ($newHtmlFiles === []) {
        return;
    }

    $newFiles = array_values(array_unique(array_map('strval', $newHtmlFiles)));
    $newFileSet = array_fill_keys($newFiles, true);
    $byInnerPath = [];
    foreach ($newFiles as $newFile) {
        $innerPath = path_without_top_directory($newFile);
        if ($innerPath === '') {
            continue;
        }
        if (array_key_exists($innerPath, $byInnerPath)) {
            $byInnerPath[$innerPath] = null;
            continue;
        }
        $byInnerPath[$innerPath] = $newFile;
    }

    $stmt = db()->prepare('SELECT DISTINCT file_path FROM ' . table_name('comments') . ' WHERE project_id = ?');
    $stmt->execute([$projectId]);
    $update = db()->prepare('UPDATE ' . table_name('comments') . ' SET file_path = ? WHERE project_id = ? AND file_path = ?');

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $oldFile) {
        $oldFile = (string) $oldFile;
        if (isset($newFileSet[$oldFile])) {
            continue;
        }
        $innerPath = path_without_top_directory($oldFile);
        $newFile = $byInnerPath[$innerPath] ?? null;
        if (is_string($newFile) && $newFile !== $oldFile) {
            $update->execute([$newFile, $projectId, $oldFile]);
        }
    }
}

function markdown_inline_to_html(string $text): string
{
    $html = h($text);
    $html = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $html) ?? $html;
    $html = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $html) ?? $html;
    $html = preg_replace('/__([^_]+)__/u', '<strong>$1</strong>', $html) ?? $html;
    $html = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/u', '<em>$1</em>', $html) ?? $html;
    $html = preg_replace('/(?<!_)_([^_]+)_(?!_)/u', '<em>$1</em>', $html) ?? $html;
    $html = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/u', static function (array $matches): string {
        return '<a href="' . h(html_entity_decode($matches[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '" target="_blank" rel="noopener noreferrer">' . $matches[1] . '</a>';
    }, $html) ?? $html;

    return $html;
}

function markdown_table_cells(string $line): array
{
    $line = trim($line);
    if ($line === '' || !str_contains($line, '|')) {
        return [];
    }
    if (str_starts_with($line, '|')) {
        $line = substr($line, 1);
    }
    if (str_ends_with($line, '|')) {
        $line = substr($line, 0, -1);
    }
    $parts = preg_split('/(?<!\\\\)\|/u', $line) ?: [];
    $cells = array_map(static function (string $cell): string {
        return trim(str_replace('\\|', '|', $cell));
    }, $parts);
    return count($cells) >= 2 ? $cells : [];
}

function markdown_table_alignments(string $line, int $cellCount): ?array
{
    $cells = markdown_table_cells($line);
    if (count($cells) < $cellCount) {
        return null;
    }
    $alignments = [];
    for ($i = 0; $i < $cellCount; $i++) {
        $cell = preg_replace('/\s+/u', '', $cells[$i]) ?? '';
        if (!preg_match('/^:?-{3,}:?$/u', $cell)) {
            return null;
        }
        $alignments[] = str_starts_with($cell, ':') && str_ends_with($cell, ':')
            ? 'center'
            : (str_ends_with($cell, ':') ? 'right' : (str_starts_with($cell, ':') ? 'left' : ''));
    }
    return $alignments;
}

function normalize_markdown_table_row(array $cells, int $cellCount): array
{
    $cells = array_slice($cells, 0, $cellCount);
    while (count($cells) < $cellCount) {
        $cells[] = '';
    }
    return $cells;
}

function render_markdown_table(array $headerCells, array $alignments, array $bodyRows): string
{
    $cellCount = count($headerCells);
    $renderCell = static function (string $tag, string $value, string $alignment = ''): string {
        $align = $alignment !== '' ? ' style="text-align: ' . h($alignment) . ';"' : '';
        return '<' . $tag . $align . '>' . markdown_inline_to_html($value) . '</' . $tag . '>';
    };

    $html = ['<div class="note-table-wrap"><table><thead><tr>'];
    foreach (normalize_markdown_table_row($headerCells, $cellCount) as $index => $cell) {
        $html[] = $renderCell('th', $cell, $alignments[$index] ?? '');
    }
    $html[] = '</tr></thead><tbody>';
    foreach ($bodyRows as $row) {
        $html[] = '<tr>';
        foreach (normalize_markdown_table_row($row, $cellCount) as $index => $cell) {
            $html[] = $renderCell('td', $cell, $alignments[$index] ?? '');
        }
        $html[] = '</tr>';
    }
    $html[] = '</tbody></table></div>';
    return implode('', $html);
}

function render_markdown_document(string $markdown): string
{
    $lines = preg_split('/\R/u', str_replace(["\r\n", "\r"], "\n", $markdown)) ?: [];
    $html = [];
    $paragraph = [];
    $inList = false;
    $inCode = false;
    $codeLines = [];

    $flushParagraph = static function () use (&$html, &$paragraph): void {
        if ($paragraph === []) {
            return;
        }
        $html[] = '<p>' . markdown_inline_to_html(implode(' ', $paragraph)) . '</p>';
        $paragraph = [];
    };
    $closeList = static function () use (&$html, &$inList): void {
        if ($inList) {
            $html[] = '</ul>';
            $inList = false;
        }
    };

    $lineCount = count($lines);
    for ($lineIndex = 0; $lineIndex < $lineCount; $lineIndex++) {
        $line = $lines[$lineIndex];
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '```')) {
            if ($inCode) {
                $html[] = '<pre><code>' . h(implode("\n", $codeLines)) . '</code></pre>';
                $codeLines = [];
                $inCode = false;
                continue;
            }
            $flushParagraph();
            $closeList();
            $inCode = true;
            continue;
        }
        if ($inCode) {
            $codeLines[] = $line;
            continue;
        }
        if ($trimmed === '') {
            $flushParagraph();
            $closeList();
            continue;
        }
        if (preg_match('/^(?:-{3,}|\*{3,}|_{3,})$/u', $trimmed)) {
            $flushParagraph();
            $closeList();
            $html[] = '<hr>';
            continue;
        }
        $tableHeader = markdown_table_cells($trimmed);
        if ($tableHeader !== [] && $lineIndex + 1 < $lineCount) {
            $alignments = markdown_table_alignments((string) $lines[$lineIndex + 1], count($tableHeader));
            if ($alignments !== null) {
                $flushParagraph();
                $closeList();
                $bodyRows = [];
                $lineIndex += 2;
                while ($lineIndex < $lineCount) {
                    $row = markdown_table_cells((string) $lines[$lineIndex]);
                    if ($row === []) {
                        $lineIndex--;
                        break;
                    }
                    $bodyRows[] = $row;
                    $lineIndex++;
                }
                if ($lineIndex >= $lineCount) {
                    $lineIndex = $lineCount - 1;
                }
                $html[] = render_markdown_table($tableHeader, $alignments, $bodyRows);
                continue;
            }
        }
        if (preg_match('/^(#{1,3})\s+(.+)$/u', $trimmed, $matches)) {
            $flushParagraph();
            $closeList();
            $level = strlen($matches[1]);
            $html[] = '<h' . $level . '>' . markdown_inline_to_html($matches[2]) . '</h' . $level . '>';
            continue;
        }
        if (preg_match('/^>\s?(.*)$/u', $trimmed, $matches)) {
            $flushParagraph();
            $closeList();
            $html[] = '<blockquote>' . markdown_inline_to_html($matches[1]) . '</blockquote>';
            continue;
        }
        if (preg_match('/^[-*]\s+(.+)$/u', $trimmed, $matches)) {
            $flushParagraph();
            if (!$inList) {
                $html[] = '<ul>';
                $inList = true;
            }
            $html[] = '<li>' . markdown_inline_to_html($matches[1]) . '</li>';
            continue;
        }
        $paragraph[] = $trimmed;
    }

    if ($inCode) {
        $html[] = '<pre><code>' . h(implode("\n", $codeLines)) . '</code></pre>';
    }
    $flushParagraph();
    $closeList();

    return implode("\n", $html);
}

function title_from_markdown(string $markdown, string $filename): string
{
    if (preg_match('/^\s*#\s+(.+)$/mu', $markdown, $matches)) {
        return mb_substr(trim($matches[1]), 0, 180);
    }
    $basename = pathinfo($filename, PATHINFO_FILENAME);
    $title = trim(str_replace(['_', '-'], ' ', $basename));
    return $title !== '' ? mb_substr($title, 0, 180) : 'Untitled note';
}

function read_note_markdown_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Markdownファイルを選択してください。');
    }
    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > WEBPATCH_MAX_NOTE_BYTES) {
        throw new RuntimeException('Markdownファイルは2MB以内でアップロードしてください。');
    }
    $filename = (string) ($file['name'] ?? '');
    if (!preg_match('/\.md$/i', $filename)) {
        throw new RuntimeException('アップロードできるファイルは.mdのみです。');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $markdown = is_uploaded_file($tmp) ? file_get_contents($tmp) : false;
    if ($markdown === false || trim($markdown) === '') {
        throw new RuntimeException('Markdownファイルを読み込めませんでした。');
    }

    return [
        'filename' => $filename,
        'markdown' => $markdown,
    ];
}

function upload_note_file(array $file, int $userId): string
{
    $uploaded = read_note_markdown_upload($file);
    $filename = (string) $uploaded['filename'];
    $markdown = (string) $uploaded['markdown'];

    $publicId = generate_note_public_id();
    $title = title_from_markdown($markdown, $filename);
    $stmt = db()->prepare('INSERT INTO ' . table_name('notes') . ' (public_id, user_id, title, original_filename, markdown) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$publicId, $userId, $title, mb_substr($filename, 0, 255), $markdown]);

    return $publicId;
}

function upload_project(array $file, string $title, int $userId): int
{
    $extracted = extract_zip_upload_to_storage($file, $userId);

    $projectTitle = trim($title) !== '' ? trim($title) : pathinfo((string) $extracted['original_filename'], PATHINFO_FILENAME);
    $stmt = db()->prepare('INSERT INTO ' . table_name('projects') . ' (public_id, user_id, title, original_filename, entry_file, storage_path, source_type) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([generate_project_public_id(), $userId, mb_substr($projectTitle, 0, 160), (string) $extracted['original_filename'], (string) $extracted['entry_file'], (string) $extracted['storage_key'], 'zip']);

    $projectId = (int) db()->lastInsertId();
    ensure_original_project_snapshot(['id' => $projectId, 'storage_path' => (string) $extracted['storage_key']]);

    return $projectId;
}

function replace_project_with_zip(array $project, array $file): string
{
    if (project_is_url_source($project)) {
        throw new RuntimeException('URL登録サイトはZIPで差し替えできません。');
    }

    $userId = (int) $project['user_id'];
    $oldRoot = project_root($project);
    $oldOriginalRoot = original_project_root($project);
    $oldPageOrder = project_page_order($project);
    $extracted = extract_zip_upload_to_storage($file, $userId);

    try {
        db()->beginTransaction();
        $stmt = db()->prepare(
            'UPDATE ' . table_name('projects') . '
                SET original_filename = ?, entry_file = ?, storage_path = ?, source_type = ?, updated_at = NOW()
              WHERE id = ?'
        );
        $stmt->execute([
            (string) $extracted['original_filename'],
            (string) $extracted['entry_file'],
            (string) $extracted['storage_key'],
            'zip',
            (int) $project['id'],
        ]);
        remap_project_comment_file_paths((int) $project['id'], (array) $extracted['html_files']);
        if ($oldPageOrder !== []) {
            $newProject = $project;
            $newProject['entry_file'] = (string) $extracted['entry_file'];
            $newProject['storage_path'] = (string) $extracted['storage_key'];
            save_project_page_order($newProject, remap_project_page_order_files($oldPageOrder, (array) $extracted['html_files']));
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        delete_directory_recursive((string) $extracted['target_root']);
        throw $e;
    }

    if (is_dir($oldOriginalRoot)) {
        delete_directory_recursive($oldOriginalRoot);
    }
    ensure_original_project_snapshot(['id' => (int) $project['id'], 'storage_path' => (string) $extracted['storage_key']]);

    if (is_dir($oldRoot) && realpath($oldRoot) !== realpath((string) $extracted['target_root'])) {
        delete_directory_recursive($oldRoot);
    }

    return (string) $extracted['entry_file'];
}
