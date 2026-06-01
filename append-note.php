<?php

require __DIR__ . '/_app.php';

$user = require_user();
$noteRef = (string) ($_POST['note_id'] ?? '');
$note = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('notes.php');
}

try {
    verify_csrf();

    $note = find_note_for_user_ref($noteRef, (int) $user['id']);
    if ($note === null) {
        throw new RuntimeException('このノートは編集できません。');
    }

    $uploaded = read_note_markdown_upload($_FILES['note_md'] ?? []);
    $currentMarkdown = rtrim((string) $note['markdown']);
    $appendMarkdown = trim((string) $uploaded['markdown']);
    if (!preg_match('/^\s*#\s+.+$/mu', $appendMarkdown)) {
        $appendTitle = title_from_markdown($appendMarkdown, (string) $uploaded['filename']);
        $appendMarkdown = '# ' . $appendTitle . "\n\n" . $appendMarkdown;
    }
    $nextMarkdown = $currentMarkdown . "\n\n" . $appendMarkdown;
    if (strlen($nextMarkdown) > WEBPATCH_MAX_NOTE_BYTES) {
        throw new RuntimeException('追加後のノート本文が2MBを超えます。');
    }

    $stmt = db()->prepare(
        'UPDATE ' . table_name('notes') . '
            SET markdown = ?, updated_at = NOW()
          WHERE id = ?'
    );
    $stmt->execute([$nextMarkdown, (int) $note['id']]);

    set_flash('success', 'Markdownを追加しました。');
} catch (Throwable $e) {
    set_flash('error', $e->getMessage());
}

redirect_to($note !== null ? note_path($note) : 'notes.php');
