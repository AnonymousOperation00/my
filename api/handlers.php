<?php

/**
 * „nasze zeszyty" — handlery REST API.
 *
 * Zawiera wyłącznie definicje funkcji (router + obsługa endpointów).
 * Plik wczytywany jest raz (require_once); pojedyncze żądanie obsługuje
 * funkcja api_dispatch(), wywoływana przez api/index.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';

/**
 * Prosty router oparty o metodę i ścieżkę. Obsłużone funkcje kończą żądanie
 * przez send_json()/send_error() (wywołują `exit`).
 */
function route(string $method, string $path): void
{
    if ($path === '/api/notes') {
        if ($method === 'GET') {
            notes_list();
        }
        if ($method === 'POST') {
            notes_create();
        }
        send_error('nie znaleziono', 404);
    }

    if (preg_match('#^/api/notes/([0-9]+)$#', $path, $m) === 1) {
        $id = (int) $m[1];
        if ($method === 'PUT') {
            notes_update($id);
        }
        if ($method === 'DELETE') {
            notes_delete($id);
        }
        send_error('nie znaleziono', 404);
    }

    if ($path === '/api/photos') {
        if ($method === 'GET') {
            photos_list();
        }
        if ($method === 'POST') {
            photos_upload();
        }
        send_error('nie znaleziono', 404);
    }

    if ($path === '/api/meeting') {
        if ($method === 'GET') {
            meeting_get();
        }
        if ($method === 'PUT') {
            meeting_put();
        }
        send_error('nie znaleziono', 404);
    }

    if ($path === '/api/since') {
        if ($method === 'GET') {
            since_get();
        }
        if ($method === 'PUT') {
            since_put();
        }
        send_error('nie znaleziono', 404);
    }

    if ($path === '/api/status') {
        if ($method === 'GET') {
            status_get();
        }
        if ($method === 'PUT') {
            status_put();
        }
        send_error('nie znaleziono', 404);
    }

    if ($path === '/api/moods') {
        if ($method === 'GET') {
            moods_list();
        }
        if ($method === 'POST') {
            moods_create();
        }
        send_error('nie znaleziono', 404);
    }

    if ($path === '/api/secrets') {
        if ($method === 'GET') {
            secrets_list();
        }
        if ($method === 'POST') {
            secrets_create();
        }
        send_error('nie znaleziono', 404);
    }

    send_error('nie znaleziono', 404);
}

/* ============================================================ notatki */

/** Pobiera jeden wiersz notatki (lub null). */
function note_row(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, notebook, title, content, created_at, updated_at
         FROM notes WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** Serializacja wiersza notatki na JSON zgodny z frontendem. */
function note_to_api(array $row): array
{
    return [
        'id'         => (int) $row['id'],
        'notebook'   => (string) $row['notebook'],
        'title'      => (string) $row['title'],
        'content'    => (string) $row['content'],
        'created_at' => iso_z($row['created_at']),
        'updated_at' => iso_z($row['updated_at']),
    ];
}

function notes_list(): void
{
    $notebook = $_GET['notebook'] ?? 'moj';
    if (!in_array($notebook, NOTEBOOKS, true)) {
        send_error('nieznany zeszyt', 400);
    }

    $stmt = db()->prepare(
        'SELECT id, notebook, title, content, created_at, updated_at
         FROM notes WHERE notebook = ? ORDER BY id ASC'
    );
    $stmt->execute([$notebook]);
    $rows = $stmt->fetchAll();

    send_json(array_map('note_to_api', $rows));
}

function notes_create(): void
{
    $data = read_json_body();
    if ($data === null) {
        send_error('nieprawidłowe dane', 400);
    }

    $notebook = $data['notebook'] ?? null;
    if (!in_array($notebook, NOTEBOOKS, true)) {
        send_error('nieznany zeszyt', 400);
    }

    $title   = clip_chars((string) ($data['title'] ?? ''), 200);
    $content = (string) ($data['content'] ?? '');
    $stamp   = now_db();

    $stmt = db()->prepare(
        'INSERT INTO notes (notebook, title, content, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$notebook, $title, $content, $stamp, $stamp]);
    $id = (int) db()->lastInsertId();

    send_json(note_to_api(note_row($id)), 201);
}

function notes_update(int $id): void
{
    $data = read_json_body();
    if ($data === null) {
        send_error('nieprawidłowe dane', 400);
    }

    $row = note_row($id);
    if ($row === null) {
        send_error('nie ma takiej strony', 404);
    }

    $title   = $data['title'] === null ? $row['title'] : clip_chars((string) $data['title'], 200);
    $content = $data['content'] === null ? $row['content'] : (string) $data['content'];
    $stamp   = now_db();

    $stmt = db()->prepare('UPDATE notes SET title = ?, content = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$title, $content, $stamp, $id]);

    send_json(note_to_api(note_row($id)));
}

function notes_delete(int $id): void
{
    $stmt = db()->prepare('DELETE FROM notes WHERE id = ?');
    $stmt->execute([$id]);

    send_json(['ok' => true]);
}

/* ============================================================ galeria */

function photos_list(): void
{
    $items = [];
    $dir = MEMORIES_DIR;

    if (is_dir($dir)) {
        $entries = scandir($dir);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $dir . '/' . $entry;
                if (!is_file($full)) {
                    continue;
                }

                $kind = kind_for(strtolower(pathinfo($entry, PATHINFO_EXTENSION)));
                if ($kind === null) {
                    continue;
                }

                $items[] = [
                    'name'  => $entry,
                    'url'   => '/photos/wspomnienia/' . rawurlencode($entry),
                    'kind'  => $kind,
                    'size'  => (int) filesize($full),
                    'mtime' => (int) filemtime($full),
                ];
            }
        }
    }

    usort($items, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

    send_json(['items' => $items]);
}

function photos_upload(): void
{
    $data = read_json_body();
    if ($data === null) {
        send_error('nieprawidłowe dane', 400);
    }

    $category = $data['category'] ?? null;
    if ($category !== null && $category !== 'wspomnienia') {
        send_error('zdjęcia trafiają tylko do wspomnień', 400);
    }

    // Bezpieczna nazwa pliku: tylko dozwolone znaki.
    $name = basename((string) ($data['filename'] ?? 'zdjecie.jpg'));
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? '';
    $name = trim($name, '-.');
    if ($name === '') {
        $name = 'zdjecie.jpg';
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, MEDIA_EXT, true)) {
        send_error('nieobsługiwany format pliku', 400);
    }

    $b64 = (string) ($data['data'] ?? '');
    $raw = base64_decode($b64, true);
    if ($raw === false) {
        send_error('nieprawidłowe dane pliku', 400);
    }
    if ($raw === '') {
        send_error('pusty plik', 400);
    }
    if (strlen($raw) > MAX_MEDIA_BYTES) {
        send_error('plik jest za duży (limit ' . (int) (MAX_MEDIA_BYTES / 1024 / 1024) . ' MB)', 413);
    }

    $kind = media_kind($raw, $ext);
    if ($kind === null) {
        send_error('to nie jest zdjęcie ani nagranie', 400);
    }

    $dir = MEMORIES_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        send_error('nie udało się utworzyć katalogu na zdjęcia', 500);
    }

    // Unikalna nazwa, żeby nie nadpisać istniejącego pliku.
    $stem = pathinfo($name, PATHINFO_FILENAME);
    $target = $name;
    $counter = 1;
    while (file_exists($dir . '/' . $target)) {
        $target = $stem . '-' . $counter . '.' . $ext;
        $counter++;
    }

    if (file_put_contents($dir . '/' . $target, $raw) === false) {
        send_error('nie udało się zapisać pliku', 500);
    }

    send_json([
        'ok'   => true,
        'name' => $target,
        'kind' => $kind,
        'url'  => '/photos/wspomnienia/' . rawurlencode($target),
    ], 201);
}

/* ============================================================ odliczanie */

function meeting_get(): void
{
    $value = setting_get('meeting');

    if ($value === null) {
        // Pierwszy raz: spotkanie za tydzień.
        $value = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+7 days')
            ->format('Y-m-d H:i:s.v');
        setting_put('meeting', $value);
    }

    send_json(['target' => iso_z($value)]);
}

function meeting_put(): void
{
    $data = read_json_body();
    if ($data === null) {
        send_error('nieprawidłowe dane', 400);
    }

    $value = parse_iso_to_db($data['target'] ?? null);
    if ($value === null) {
        send_error('nieprawidłowa data', 400);
    }

    setting_put('meeting', $value);
    send_json(['target' => iso_z($value)]);
}

function since_get(): void
{
    $value = setting_get('poznanie');

    if ($value === null) {
        setting_put('poznanie', DEFAULT_SINCE);
        $value = DEFAULT_SINCE;
    }

    send_json(['since' => $value]);
}

function since_put(): void
{
    $data = read_json_body();
    if ($data === null) {
        send_error('nieprawidłowe dane', 400);
    }

    $raw = trim((string) ($data['since'] ?? ''));
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$dt || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        send_error('nieprawidłowa data', 400);
    }

    $value = $dt->format('Y-m-d');
    setting_put('poznanie', $value);
    send_json(['since' => $value]);
}
/* ============================================================ stan */

function status_get(): void
{
    $state = setting_get('status', 'razem');
    if (!in_array($state, ['razem', 'rozstanie'], true)) {
        $state = 'razem';
    }

    send_json(['state' => $state, 'at' => iso_z(setting_get('status_at'))]);
}

function status_put(): void
{
    $data = read_json_body();
    if ($data === null) {
        send_error('nieprawidłowe dane', 400);
    }

    $state = trim((string) ($data['state'] ?? ''));
    if (!in_array($state, ['razem', 'rozstanie'], true)) {
        send_error('nieznany stan', 400);
    }

    $at = $state === 'rozstanie' ? now_db() : null;

    setting_put('status', $state);
    db()->prepare('DELETE FROM settings WHERE setting_key = ?')->execute(['status_at']);
    if ($at !== null) {
        setting_put('status_at', $at);
    }

    send_json(['state' => $state, 'at' => iso_z($at)]);
}

/* ============================================================ nastrój */

function mood_to_api(array $row): array
{
    return [
        'id'         => (int) $row['id'],
        'mood'       => (string) $row['mood'],
        'created_at' => iso_z($row['created_at']),
    ];
}

function moods_list(): void
{
    $stmt = db()->prepare('SELECT id, mood, created_at FROM moods ORDER BY id DESC LIMIT 8');
    $stmt->execute();
    $items = array_map('mood_to_api', $stmt->fetchAll());

    send_json(['current' => $items[0] ?? null, 'history' => $items]);
}

function moods_create(): void
{
    $data = read_json_body();
    if ($data === null) {
        send_error('nieprawidłowe dane', 400);
    }

    $mood = trim((string) ($data['mood'] ?? ''));
    if (!in_array($mood, MOOD_SLUGS, true)) {
        send_error('nieznany nastrój', 400);
    }

    $stmt = db()->prepare('INSERT INTO moods (mood, created_at) VALUES (?, ?)');
    $stmt->execute([$mood, now_db()]);
    $id = (int) db()->lastInsertId();

    $stmt = db()->prepare('SELECT id, mood, created_at FROM moods WHERE id = ?');
    $stmt->execute([$id]);

    send_json(['current' => mood_to_api($stmt->fetch())], 201);
}

/* ============================================================ sekrety */

function secrets_list(): void
{
    $meeting = setting_get('meeting');

    $stmt = db()->prepare('SELECT id, content, created_at, reveal_at FROM secrets ORDER BY id DESC LIMIT 50');
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Sekrety otwierają się w dniu spotkania — jeśli data spotkania się zmieni,
    // widoczność przesuwa się razem z nią.
    $revealAt = $meeting;

    $items = [];
    foreach ($rows as $row) {
        $moment = $revealAt !== null ? $revealAt : $row['reveal_at'];
        $unlocked = is_revealed($moment);

        $item = [
            'id'         => (int) $row['id'],
            'created_at' => iso_z($row['created_at']),
            'reveal_at'  => iso_z($moment),
            'locked'     => !$unlocked,
        ];
        if ($unlocked) {
            $item['content'] = (string) $row['content'];
        }
        $items[] = $item;
    }

    send_json(['items' => $items]);
}

function secrets_create(): void
{
    $data = read_json_body();
    if ($data === null) {
        send_error('nieprawidłowe dane', 400);
    }

    $content = trim((string) ($data['content'] ?? ''));
    if ($content === '') {
        send_error('pusty sekret', 400);
    }
    if (char_count($content) > MAX_SECRET_CHARS) {
        send_error('sekret jest za długi', 400);
    }

    $revealAt = setting_get('meeting');
    if ($revealAt === null) {
        // Brak daty spotkania: sekret otworzy się za tydzień.
        $revealAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+7 days')
            ->format('Y-m-d H:i:s.v');
        setting_put('meeting', $revealAt);
    }

    $stmt = db()->prepare('INSERT INTO secrets (content, created_at, reveal_at) VALUES (?, ?, ?)');
    $stmt->execute([$content, now_db(), $revealAt]);
    $id = (int) db()->lastInsertId();

    send_json(['ok' => true, 'id' => $id, 'reveal_at' => iso_z($revealAt)], 201);
}
/**
 * Obsługuje pojedyncze żądanie API. Wywoływana przy każdym żądaniu przez
 * api/index.php; jawne wywołanie (a nie kod na najwyższym poziomie) pozwala
 * wczytać ten plik raz (require_once) bez utraty obsługi kolejnych żądań
 * w wbudowanym serwerze PHP (php -S).
 */
function api_dispatch(string $method, string $path): void
{
    try {
        route($method, $path);
    } catch (Throwable $e) {
        error_log('[zeszyty] błąd API: ' . $e->getMessage());
        send_json(['error' => 'wewnętrzny błąd serwera'], 500);
    }
}