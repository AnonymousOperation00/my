<?php

/**
 * „nasze zeszyty" — punkt wejścia API.
 *
 * Wykonywany przy każdym żądaniu /api/* (w wbudowanym serwerze przez
 * router.php — plik wczytywany jest zwykłym `require`, a w Apache przez
 * mod_rewrite). Definicje funkcji żyją w api/handlers.php (wczytane raz);
 * tutaj uruchamiamy jedynie obsługę bieżącego żądania.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/handlers.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rawurldecode((string) $path);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

api_dispatch($method, $path);