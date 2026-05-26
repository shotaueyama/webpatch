<?php

require __DIR__ . '/_app.php';

$user = require_user();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

function markdown_without_note_title(string $markdown): string
{
    return trim(preg_replace('/^\s*#\s+.+(?:\R|$)/u', '', $markdown, 1) ?? $markdown);
}

function has_meaningful_markdown_lines(array $lines): bool
{
    foreach ($lines as $line) {
        if (trim((string) $line) !== '') {
            return true;
        }
    }
    return false;
}

function split_note_markdown_documents(string $markdownBody): array
{
    $lines = preg_split('/\R/u', str_replace(["\r\n", "\r"], "\n", $markdownBody)) ?: [];
    $documents = [];
    $current = [];

    foreach ($lines as $line) {
        $isDocumentHeading = preg_match('/^\s*#\s+.+$/u', $line) === 1;
        if ($isDocumentHeading && has_meaningful_markdown_lines($current)) {
            $documents[] = trim(implode("\n", $current));
            $current = [];
        }
        if ($current !== [] || trim($line) !== '') {
            $current[] = $line;
        }
    }

    if (has_meaningful_markdown_lines($current)) {
        $documents[] = trim(implode("\n", $current));
    }

    return $documents;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
        exit;
    }

    verify_csrf();

    $noteRef = (string) ($_POST['note_id'] ?? '');
    $documentIndex = (int) ($_POST['document_index'] ?? 0);
    if ($documentIndex < 1) {
        throw new RuntimeException('差し替え対象のドキュメントを特定できません。');
    }

    $note = find_note_for_user_ref($noteRef, (int) $user['id']);
    if ($note === null || !user_owns_note($note, (int) $user['id'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'このノートは編集できません。'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $uploaded = read_note_markdown_upload($_FILES['note_md'] ?? []);
    $replacementMarkdown = trim((string) $uploaded['markdown']);
    if (!preg_match('/^\s*#\s+.+$/mu', $replacementMarkdown)) {
        $replacementMarkdown = '# ' . title_from_markdown($replacementMarkdown, (string) $uploaded['filename']) . "\n\n" . $replacementMarkdown;
    }

    $title = trim((string) $note['title']);
    $safeTitle = preg_replace('/\s+/u', ' ', $title) ?: 'Untitled note';
    $documents = split_note_markdown_documents(markdown_without_note_title((string) $note['markdown']));
    if ($documents === []) {
        throw new RuntimeException('差し替え対象のドキュメントがありません。');
    }
    if (!array_key_exists($documentIndex - 1, $documents)) {
        throw new RuntimeException('差し替え対象のドキュメントが見つかりません。');
    }

    $documents[$documentIndex - 1] = $replacementMarkdown;
    $nextMarkdown = '# ' . $safeTitle . "\n\n" . implode("\n\n", $documents);
    if (strlen($nextMarkdown) > WEBPATCH_MAX_NOTE_BYTES) {
        throw new RuntimeException('差し替え後のノート本文が2MBを超えます。');
    }

    $stmt = db()->prepare(
        'UPDATE ' . table_name('notes') . '
            SET markdown = ?, updated_at = NOW()
          WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$nextMarkdown, (int) $note['id'], (int) $user['id']]);

    echo json_encode([
        'ok' => true,
        'message' => 'ドキュメントを差し替えました。',
        'html' => render_markdown_document($replacementMarkdown),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
