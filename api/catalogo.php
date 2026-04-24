<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$file = __DIR__ . '/../_data/catalogo.json';

if (!file_exists($file)) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

readfile($file);
