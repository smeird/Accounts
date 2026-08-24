<?php
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../services/TaggingWorkspaceService.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');
$service = new TaggingWorkspaceService();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        echo json_encode($service->snapshot($limit));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($data['action'] ?? '');
    if ($action === 'create_tag') {
        $result = $service->createTag(
            (string)($data['name'] ?? ''),
            isset($data['description']) ? (string)$data['description'] : null,
            isset($data['category_id']) && $data['category_id'] !== '' ? (int)$data['category_id'] : null
        );
    } elseif ($action === 'update_tag') {
        $result = $service->updateTag(
            (int)($data['id'] ?? 0),
            (string)($data['name'] ?? ''),
            isset($data['description']) ? (string)$data['description'] : null,
            isset($data['category_id']) && $data['category_id'] !== '' ? (int)$data['category_id'] : null
        );
    } elseif ($action === 'retire_tag') {
        $result = $service->retireTag((int)($data['id'] ?? 0));
    } elseif ($action === 'merge_tag') {
        $result = $service->mergeTag((int)($data['source_id'] ?? 0), (int)($data['target_id'] ?? 0));
    } elseif ($action === 'resolve_inbox') {
        $result = $service->resolveInbox(
            (string)($data['alias'] ?? ''),
            (int)($data['tag_id'] ?? 0),
            (string)($data['direction'] ?? 'any'),
            !empty($data['confirm_overlap'])
        );
    } else {
        throw new InvalidArgumentException('Unknown tagging action.');
    }

    Log::write('Tagging workspace action ' . $action . ': ' . json_encode($result));
    echo json_encode(['status' => 'ok', 'result' => $result]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    Log::write('Tagging workspace error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'The tagging change could not be completed safely.']);
}
?>
