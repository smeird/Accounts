<?php
// API endpoint to manually run tagging and categorisation processes.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/CategoryTag.php';
require_once __DIR__ . '/../models/Segment.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

try {
    $response = [];
    if ($action === 'tagging') {
        $tagged = Tag::applyToAllTransactions();
        Log::write("Manual tagging applied to $tagged transactions");
        $response['tagged'] = $tagged;
    } elseif ($action === 'categories') {
        $categorised = CategoryTag::applyToAllTransactions();
        Log::write("Manual categorisation applied to $categorised transactions");
        $response['categorised'] = $categorised;
    } elseif ($action === 'segments') {
        $segmented = Segment::applyToTransactions();
        Log::write("Manual segment assignment applied to $segmented transactions");
        $response['segmented'] = $segmented;
    } elseif ($action === 'clear') {
        $clearedTags = Tag::clearFromTransactions();
        $clearedCategories = CategoryTag::clearFromTransactions();
        $clearedSegments = Segment::clearFromTransactions();
        Log::write("Cleared tags from $clearedTags transactions; categories from $clearedCategories transactions; segments from $clearedSegments transactions");
        $response['cleared_tags'] = $clearedTags;
        $response['cleared_categories'] = $clearedCategories;
        $response['cleared_segments'] = $clearedSegments;
    } elseif ($action === 'all' || $action === 'both') {
        $tagged = Tag::applyToAllTransactions();
        $categorised = CategoryTag::applyToAllTransactions();
        $segmented = Segment::applyToTransactions();
        Log::write("Manual tagging applied to $tagged transactions; categorised $categorised transactions; segment assignment applied to $segmented transactions");
        $response['tagged'] = $tagged;
        $response['categorised'] = $categorised;
        $response['segmented'] = $segmented;
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        exit;
    }
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Run processes error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
