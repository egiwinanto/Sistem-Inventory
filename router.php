<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$protected = ['/src/', '/storage/', '/database/'];
foreach ($protected as $prefix) {
    if (str_starts_with($path, $prefix)) {
        http_response_code(403);
        exit('Akses ditolak.');
    }
}

$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
