<?php
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Log.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}
$url = $_GET['url'] ?? '';
if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
    Log::write('Invalid link preview URL: ' . $url, 'WARN');
    echo json_encode(['error' => 'Invalid URL']);
    exit;
}

$context = stream_context_create([
    'http' => [
        'user_agent' => 'Mozilla/5.0'
    ],
    'https' => [
        'user_agent' => 'Mozilla/5.0'
    ]
]);

$html = @file_get_contents($url, false, $context);
if ($html === false) {
    Log::write('Link preview fetch failed: ' . $url, 'ERROR');
    echo json_encode(['error' => 'Unable to fetch URL']);
    exit;
}

libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML($html);
libxml_clear_errors();
$meta = [];
foreach ($doc->getElementsByTagName('meta') as $m) {
    $prop = $m->getAttribute('property');
    if (!$prop) {
        $prop = $m->getAttribute('name');
    }
    $content = $m->getAttribute('content');
    if ($prop && $content) {
        $meta[strtolower($prop)] = $content;
    }
}
$title = $meta['og:title'] ?? ($doc->getElementsByTagName('title')->item(0)->textContent ?? '');
$desc = $meta['og:description'] ?? ($meta['description'] ?? '');
$image = $meta['og:image'] ?? '';
$data = ['title' => $title, 'description' => $desc, 'image' => $image];
echo json_encode($data);
