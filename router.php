<?php

/**
 * „nasze zeszyty" — front controller dla wbudowanego serwera PHP.
 *
 * Uruchomienie (bez Apache/Nginx):
 *     php -S 0.0.0.0:8000 router.php
 *
 * Router:
 *   * przekazuje żądania /api/* do obsługi PHP,
 *   * blokuje dostęp do plików prywatnych (baza, konfiguracja, .git, api/...),
 *   * pozostałe zasoby serwuje statycznie z poprawnymi typami MIME oraz
 *     obsługą Range (wymaganą do odtwarzania wideo/audio, m.in. na iPhonie).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

$uri  = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);
if (!is_string($path) || $path === '') {
    $path = '/';
}
$path = rawurldecode($path);

// Odrzucamy próby wyjścia poza katalog oraz znaki NUL.
if (str_contains($path, "\0") || str_contains($path, '..')) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bad request';
    return true;
}

// API aplikacji.
if ($path === '/api' || str_starts_with($path, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

// Pliki, które nie mogą być dostępne z zewnątrz.
if (is_private_web_path($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    return true;
}

// Wszystko inne: pliki statyczne.
serve_static($path);
return true;