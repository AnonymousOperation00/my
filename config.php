<?php

/**
 * „nasze zeszyty" — konfiguracja.
 *
 * Dane do połączenia z zewnętrzną bazą MariaDB/MySQL czyta się z zmiennych
 * środowiskowych (12-factor). Dzięki temu ten sam kod działa lokalnie i na
 * serwerze bez edytowania plików. Jeśli zmienne nie są ustawione, używane są
 * domyślne wartości deweloperskie (patrz schema.sql).
 *
 * Przykład uruchomienia z własnymi danymi:
 *   DB_HOST=db.example.com DB_NAME=nasze DB_USER=nasze DB_PASSWORD=tajne \
 *     php -S 0.0.0.0:8000 router.php
 */

declare(strict_types=1);

/** Katalog główny projektu. */
define('BASE_DIR', __DIR__);

/** Folder ze wspomnieniami (zdjęcia i nagrania w galerii). */
define('MEMORIES_DIR', BASE_DIR . '/photos/wspomnienia');

/* ---------------- baza danych (MariaDB) ---------------- */

define('DB_HOST', (string)(getenv('DB_HOST') ?: '127.0.0.1'));
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME', (string)(getenv('DB_NAME') ?: 'nasze_zeszyty'));
define('DB_USER', (string)(getenv('DB_USER') ?: 'nasze_zeszyty'));
define('DB_PASSWORD', (string)(getenv('DB_PASSWORD') ?: 'nasze_zeszyty'));
define('DB_CHARSET', (string)(getenv('DB_CHARSET') ?: 'utf8mb4'));

/* ---------------- stałe domenowe ---------------- */

/** Dozwolone zeszyty. */
const NOTEBOOKS = ['moj', 'twoj'];

/** Dozwolone nastroje (klucze ASCII — etykiety są po stronie przeglądarki). */
const MOOD_SLUGS = [
    'radosny', 'zakochany', 'spokojny', 'zadumany',
    'zmeczony', 'tesknie', 'smutny', 'zly',
];

/** Data poznania się, używana przy pierwszym uruchomieniu. */
const DEFAULT_SINCE = '2025-03-18';

/** Maksymalna długość sekretu (w znakach). */
const MAX_SECRET_CHARS = 4000;

/** Maksymalny rozmiar jednego pliku multimedialnego (60 MB). */
const MAX_MEDIA_BYTES = 60 * 1024 * 1024;

/** Maksymalny rozmiar całego ciała żądania (96 MB). */
const MAX_BODY_BYTES = 96 * 1024 * 1024;

/* ---------------- multimedia ---------------- */

const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
const AUDIO_EXT = ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'weba'];
const VIDEO_EXT = ['mp4', 'webm', 'mov', 'm4v', 'ogv'];

/** Typ MIME dla każdego rozszerzenia, aby przeglądarka wiedziała jak odtworzyć plik. */
const MEDIA_TYPES = [
    'jpg'  => 'image/jpeg',  'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'webp' => 'image/webp',  'gif'  => 'image/gif',  'avif' => 'image/avif',
    'mp3'  => 'audio/mpeg',  'm4a'  => 'audio/mp4',  'aac' => 'audio/aac',
    'ogg'  => 'audio/ogg',   'oga'  => 'audio/ogg',  'opus' => 'audio/ogg',
    'wav'  => 'audio/wav',   'weba' => 'audio/webm',
    'mp4'  => 'video/mp4',   'webm' => 'video/webm', 'mov' => 'video/quicktime',
    'm4v'  => 'video/mp4',   'ogv'  => 'video/ogg',
];

/** Wszystkie obsługiwane rozszerzenia mediów. */
const MEDIA_EXT = ['jpg','jpeg','png','webp','gif','avif','mp3','m4a','aac','ogg','oga','opus','wav','weba','mp4','webm','mov','m4v','ogv'];