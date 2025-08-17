<?php

// API endpoint returning all segments.
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Segment.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

try {
    echo json_encode(Segment::all());
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Segment error: ' . $e->getMessage(), 'ERROR');
    echo json_encode([]);
}

?>
