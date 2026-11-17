<?php

/**
 * „nasze zeszyty" — funkcje pomocnicze.
 *
 * Zawiera: połączenie z MariaDB (PDO), tworzenie schematu, serializację JSON,
 * obsługę czasu (UTC + DATETIME(3)), wykrywanie typów mediów oraz pomocnicze
 * narzędzia dla warstwy API.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/* ============================================================ baza danych */

/** @var PDO|null */
$GLOBALS['db_pdo'] = null;

/**
 * Zwraca współdzielone połączenie PDO do bazy MariaDB.
 *
 * @throws PDOException gdy połączenie z bazą nie jest możliwe.
 */
function db(): PDO
{
    if ($GLOBALS['db_pdo'] instanceof PDO) {
        return $GLOBALS['db_pdo'];
    }

    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException(
            'Brak rozszerzenia pdo_mysql. Zainstaluj pakiet php-mysql (php8.x-mysql).'
        );
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);

    $GLOBALS['db_pdo'] = $pdo;
    init_schema();
    return $pdo;
}

/**
 * Tworzy tabele, jeśli jeszcze nie istnieją (idempotentne).
 *
 * Pełny skrypt konfiguracyjny bazy (wraz z utworzeniem bazy i użytkownika)
 * znajduje się w pliku schema.sql — zalecany dla zewnętrznej instancji MariaDB.
 */
function init_schema(): void
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS notes (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            notebook   VARCHAR(16) NOT NULL,
            title      VARCHAR(200) NOT NULL DEFAULT '',
            content    MEDIUMTEXT NOT NULL,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_notes_notebook (notebook, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(64)  NOT NULL,
            `value`     TEXT         NOT NULL,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS moods (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            mood       VARCHAR(32) NOT NULL,
            created_at DATETIME(3) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS secrets (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            content    TEXT         NOT NULL,
            created_at DATETIME(3)  NOT NULL,
            reveal_at  DATETIME(3)  NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($statements as $sql) {
        try {
            db()->exec($sql);
        } catch (Throwable $e) {
            // Tabele mogły już powstać przez schema.sql — nie przerywamy pracy,
            // gdy użytkownik nie ma przywileju CREATE.
            error_log('[zeszyty] inicjalizacja schematu: ' . $e->getMessage());
        }
    }
}

/* ============================================================ ustawienia */

/** Odczytuje wartość ustawienia z bazy (lub $default, gdy brak). */
function setting_get(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT `value` FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    return $row ? (string)$row['value'] : $default;
}

/** Zapisuje (tworzy lub aktualizuje) ustawienie w bazie. */
function setting_put(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
    );
    $stmt->execute([$key, $value]);
}

/* ============================================================ czas */

/**
 * Bieżący czas UTC w formacie MariaDB DATETIME(3) (Y-m-d H:i:s.v).
 * Przechowujemy czas wyłącznie w UTC, żeby uniknąć problemów ze strefami.
 */
function now_db(): string
{
    return gmdate('Y-m-d H:i:s.v');
}

/**
 * Zamienia wartość z bazy (DATETIME(3) w UTC) na ISO 8601 z sufiksem „Z"
 * i milisekundami (format, którego oczekuje frontend js/app.js).
 */
function iso_z(?string $dbValue): ?string
{
    if ($dbValue === null || $dbValue === '') {
        return $dbValue;
    }

    $value = trim($dbValue);

    // Już pełne ISO z przesunięciem / „Z" — zwróć bez zmian.
    if (preg_match('/[+\-]\d{2}:\d{2}$/', $value) || str_ends_with($value, 'Z')) {
        return $value;
    }

    return str_replace(' ', 'T', $value) . 'Z';
}

/**
 * Zamienia ISO 8601 (np. z JS `toISOString()`) na DATETIME(3) w UTC.
 * Zwraca null, gdy wartość nie jest poprawną datą.
 */
function parse_iso_to_db($raw): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+\-]\d{2}:\d{2})$/', $raw)) {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($raw);
    } catch (Throwable $e) {
        return null;
    }

    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
}

/**
 * Sprawdza, czy podany moment (DATETIME(3) UTC) już nastąpił.
 */
function is_revealed(?string $momentDb): bool
{
    if ($momentDb === null || $momentDb === '') {
        return true;
    }

    foreach (['Y-m-d H:i:s.v', 'Y-m-d H:i:s'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, trim($momentDb), new DateTimeZone('UTC'));
        if ($dt instanceof DateTimeImmutable) {
            return $dt <= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
    }

    return true;
}

/* ============================================================ HTTP / JSON */

/**
 * Wysyła odpowiedź JSON i kończy działanie skryptu.
 */
function send_json($payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

/**
 * Wysyła błąd API w spójnym formacie {"error": ...}.
 */
function send_error(string $message, int $status = 400): void
{
    send_json(['error' => $message], $status);
}

/**
 * Odczytuje i dekoduje ciało żądania jako JSON.
 * Zwraca null, gdy ciało jest puste lub niepoprawne.
 */
function read_json_body(): ?array
{
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? $_SERVER['HTTP_CONTENT_LENGTH'] ?? 0);
    if ($contentLength > MAX_BODY_BYTES) {
        send_error('za dużo danych (limit ' . (int)(MAX_BODY_BYTES / 1024 / 1024) . ' MB)', 413);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return null;
    }

    return $data;
}

/** Liczba znaków w tekście UTF-8 (bez zależności od rozszerzenia mbstring). */
function char_count(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $count = preg_match_all('/./us', $value);
    return $count === false ? strlen($value) : $count;
}

/**
 * Obcina tekst do $max znaków (Unicode), nie rozcinając znaków wielobajtowych.
 */
function clip_chars(string $value, int $max): string
{
    if ($max <= 0 || $value === '') {
        return '';
    }
    if (preg_match('/^.{0,' . $max . '}/us', $value, $m) === 1) {
        return $m[0];
    }
    return substr($value, 0, $max);
}

/* ============================================================ multimedia */

/** Zwraca rodzaj medium na podstawie literału rozszerzenia (bez kropki). */
function kind_for(string $ext): ?string
{
    if (in_array($ext, IMAGE_EXT, true)) {
        return 'photo';
    }
    if (in_array($ext, AUDIO_EXT, true)) {
        return 'audio';
    }
    if (in_array($ext, VIDEO_EXT, true)) {
        return 'video';
    }
    return null;
}

/**
 * Wykrywa prawdziwy typ pliku po nagłówku (magic bytes), niezależnie od nazwy.
 * Zwraca 'photo' | 'audio' | 'video' lub null, gdy format nie jest obsługiwany.
 */
function media_kind(string $raw, string $ext): ?string
{
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            return str_starts_with($raw, "\xFF\xD8\xFF") ? 'photo' : null;

        case 'png':
            return str_starts_with($raw, "\x89PNG\r\n\x1a\n") ? 'photo' : null;

        case 'webp':
            return (str_starts_with($raw, 'RIFF') && substr($raw, 8, 4) === 'WEBP') ? 'photo' : null;

        case 'gif':
            return (str_starts_with($raw, 'GIF87a') || str_starts_with($raw, 'GIF89a')) ? 'photo' : null;

        case 'avif':
            $brand = substr($raw, 4, 8);
            return ($brand === 'ftypavif' || $brand === 'ftypavis') ? 'photo' : null;

        case 'mp3':
            return (str_starts_with($raw, 'ID3')
                    || str_starts_with($raw, "\xFF\xFB")
                    || str_starts_with($raw, "\xFF\xF3")
                    || str_starts_with($raw, "\xFF\xF2")) ? 'audio' : null;

        case 'wav':
            return (str_starts_with($raw, 'RIFF') && substr($raw, 8, 4) === 'WAVE') ? 'audio' : null;

        case 'ogg':
        case 'oga':
        case 'opus':
            return str_starts_with($raw, 'OggS') ? 'audio' : null;

        case 'm4a':
        case 'aac':
            return str_starts_with(substr($raw, 4, 4), 'ftyp') ? 'audio' : null;

        case 'weba':
            return (str_starts_with($raw, 'RIFF') && substr($raw, 8, 4) === 'WEBP') ? 'audio' : null;

        case 'mp4':
        case 'm4v':
            return str_starts_with(substr($raw, 4, 4), 'ftyp') ? 'video' : null;

        case 'webm':
            return str_starts_with($raw, "\x1A\x45\xDF\xA3") ? 'video' : null;

        case 'mov':
            // MOV używa atomów: ftyp, mdat, moov, free, wide…
            $brand = substr($raw, 4, 4);
            return ($brand === 'ftyp' || $brand === 'moov' || $brand === 'mdat'
                    || $brand === 'free' || $brand === 'wide') ? 'video' : null;

        default:
            return null;
    }
}
/* ================================================== ścieżki i serwowanie plików */

/**
 * Czy ścieżka ścieżka URL prowadzi do pliku, który nie może być publiczny.
 */
function is_private_web_path(string $path): bool
{
    $path = '/' . ltrim($path, '/');

    $forbiddenFiles = [
        '/server.py', '/requirements.txt', '/config.php', '/helpers.php',
        '/schema.sql', '/router.php', '/.htaccess', '/.env', '/README.md',
    ];
    if (in_array($path, $forbiddenFiles, true)) {
        return true;
    }

    return (preg_match('#^/(data|\.git|__pycache__)(/|$)#', $path) === 1);
}

/** Typ MIME dla pliku statycznego; tekstowe typy dostają charset=utf-8. */
function static_mime(string $file): string
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    $textTypes = [
        'html' => 'text/html',
        'htm'  => 'text/html',
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'mjs'  => 'application/javascript',
        'json' => 'application/json',
        'txt'  => 'text/plain',
        'md'   => 'text/plain',
    ];

    if (isset($textTypes[$ext])) {
        return $textTypes[$ext] . '; charset=utf-8';
    }

    $misc = [
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'xml' => 'application/xml',
    ];
    if (isset($misc[$ext])) {
        return $misc[$ext];
    }

    if (isset(MEDIA_TYPES[$ext])) {
        return MEDIA_TYPES[$ext];
    }

    return 'application/octet-stream';
}

/**
 * Serwuje plik statyczny z obsługą Range (potrzebną dla wideo/audio, m.in.
 * na iPhonie) oraz If-Modified-Since (304).
 */
function send_file(string $file): void
{
    if (!is_file($file)) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $size  = (int) filesize($file);
    $mtime = (int) filemtime($file);

    header('Content-Type: ' . static_mime($file));
    header('Accept-Ranges: bytes');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Content-Type-Options: nosniff');

    // 304 — plik się nie zmienił.
    $since = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if ($since !== '') {
        $sinceTs = strtotime($since);
        if ($sinceTs !== false && floor($sinceTs) === floor((float) $mtime)) {
            http_response_code(304);
            return;
        }
    }

    // Obsługa pojedynczego zakresu bytes=start-end.
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));

    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) === 1) {
        $start = $m[1] === '' ? null : (int)$m[1];
        $end   = $m[2] === '' ? null : (int)$m[2];

        if ($start === null && $end === null) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            return;
        }

        if ($start === null) {
            // Sufiks: bytes=-N → ostatnie N bajtów.
            $n = $end;
            if ($n <= 0) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }
            $start = max(0, $size - $n);
            $end   = $size - 1;
        } elseif ($end === null || $end > $size - 1) {
            $end = $size - 1;
        }

        if ($start < 0 || $start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            return;
        }

        $length = $end - $start + 1;
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . $length);

        $fp = fopen($file, 'rb');
        if ($fp === false) {
            return;
        }
        if ($start > 0) {
            fseek($fp, $start);
        }
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $chunk = fread($fp, min(8192, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($fp);
        return;
    }

    header('Content-Length: ' . $size);
    readfile($file);
}

/**
 * Obsługuje żądanie pliku statycznego (bez listowania katalogów).
 */
function serve_static(string $path): void
{
    if ($path === '/') {
        $file = BASE_DIR . '/index.html';
        if (is_file($file)) {
            send_file($file);
            return;
        }
    }

    $file = BASE_DIR . $path;

    if (is_dir($file)) {
        http_response_code(403);
        echo 'Forbidden';
        return;
    }

    // Nie serwujemy źródeł PHP jako statyki.
    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php') {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    send_file($file);
}