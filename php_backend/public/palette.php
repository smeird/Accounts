<?php
require_once __DIR__ . '/../nocache.php';
require_once __DIR__ . '/../models/Segment.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    switch ($method) {
        case 'GET':
            echo json_encode([
                'segments' => Segment::allWithCategories()
            ]);
            break;
        case 'POST':
            if (!empty($input['segments']) && is_array($input['segments'])) {
                foreach ($input['segments'] as $seg) {
                    Segment::updatePalette(
                        (int)$seg['id'],
                        (float)$seg['hue_deg'],
                        (float)$seg['base_l_pct'],
                        (float)$seg['base_c'],
                        !empty($seg['locked'])
                    );
                }
                Log::write('Updated palette segments');
            }
            if (!empty($input['categories']) && is_array($input['categories'])) {
                foreach ($input['categories'] as $cat) {
                    Category::setShadeIndex((int)$cat['id'], $cat['shade_index'] !== null ? (int)$cat['shade_index'] : null);
                }
                Log::write('Updated category shade indices');
            }
            echo json_encode(['status' => 'ok']);
            break;
        default:
            http_response_code(405);
            echo json_encode([]);
    }
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Palette error: ' . $e->getMessage(), 'ERROR');
    echo json_encode([]);
}
