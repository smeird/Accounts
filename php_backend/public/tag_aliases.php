<?php
// API endpoint for creating, listing, updating, and deleting tag aliases.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/TagAlias.php';
require_once __DIR__ . '/../models/Tag.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        echo json_encode(TagAlias::all());
    } catch (Exception $e) {
        http_response_code(500);
        Log::write('Tag alias error: ' . $e->getMessage(), 'ERROR');
        echo json_encode([]);
    }
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

$alias = trim($data['alias'] ?? '');
$tagId = (int)($data['tag_id'] ?? 0);
$matchType = $data['match_type'] ?? 'contains';
$active = isset($data['active'])
    ? filter_var($data['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
    : true;
if ($active === null) {
    $active = true;
}

try {
    if ($method === 'POST') {
        if ($alias === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Alias is required']);
            return;
        }
        if ($tagId <= 0 || !TagAlias::tagExists($tagId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Please select a valid canonical tag']);
            return;
        }

        $id = TagAlias::create($tagId, $alias, $matchType, $active);
        Tag::clearMatchCaches();
        Log::write('Created tag alias ' . $alias . ' for tag ' . $tagId);
        echo json_encode(['id' => $id]);
    } elseif ($method === 'PUT') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0 || $alias === '') {
            http_response_code(400);
            echo json_encode(['error' => 'ID and alias are required']);
            return;
        }
        if ($tagId <= 0 || !TagAlias::tagExists($tagId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Please select a valid canonical tag']);
            return;
        }

        TagAlias::update($id, $tagId, $alias, $matchType, $active);
        Tag::clearMatchCaches();
        Log::write('Updated tag alias ' . $id);
        echo json_encode(['status' => 'ok']);
    } elseif ($method === 'DELETE') {
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID required']);
            return;
        }

        TagAlias::delete($id);
        Tag::clearMatchCaches();
        Log::write('Deleted tag alias ' . $id);
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(405);
    }
} catch (PDOException $e) {
    if ((int)$e->getCode() === 23000) {
        http_response_code(409);
        echo json_encode(['error' => 'That alias already exists. Please choose a different alias.']);
        return;
    }
    http_response_code(500);
    Log::write('Tag alias error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('Tag alias error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Server error']);
}
?>
