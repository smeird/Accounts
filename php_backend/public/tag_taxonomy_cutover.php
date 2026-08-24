<?php
// Authenticated Phase 3 taxonomy cutover and audited rollback.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../services/TagTaxonomyCutoverService.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json; charset=utf-8');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    $service = new TagTaxonomyCutoverService();
    if ($method === 'GET') {
        if (!$service->schemaReady()) {
            echo json_encode([
                'status' => 'ok',
                'cutover' => [
                    'schema_ready' => false,
                    'schema_message' => 'Run Database Health and apply the Phase 3 catalogue repairs before cutover.',
                    'runs' => [],
                    'selected_run' => null,
                ],
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
        $runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : null;
        echo json_encode(['status' => 'ok', 'cutover' => $service->overview($runId)], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['status' => 'error', 'message' => 'Use GET to preview or POST to apply a cutover action.']);
        exit;
    }

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) throw new InvalidArgumentException('A JSON cutover request is required.');
    $runId = (int)($payload['run_id'] ?? 0);
    $action = (string)($payload['action'] ?? '');
    $actor = 'user:' . (string)($_SESSION['user_id'] ?? 'unknown');

    if ($action === 'apply') {
        if (($payload['confirm'] ?? '') !== 'APPLY_REVIEWED_TAXONOMY') {
            throw new InvalidArgumentException('Type APPLY_REVIEWED_TAXONOMY to confirm the atomic cutover.');
        }
        $result = $service->apply($runId, $actor);
        Log::write('Applied and reconciled reviewed tag taxonomy for snapshot #' . $runId);
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($action === 'rollback') {
        if (($payload['confirm'] ?? '') !== 'ROLLBACK_TAXONOMY_CUTOVER') {
            throw new InvalidArgumentException('Type ROLLBACK_TAXONOMY_CUTOVER to confirm rollback.');
        }
        $result = $service->rollback($runId, $actor);
        Log::write('Rolled back tag taxonomy cutover for snapshot #' . $runId);
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($action === 'cleanup_legacy') {
        if (($payload['confirm'] ?? '') !== 'CLEAN_LEGACY_TAXONOMY') {
            throw new InvalidArgumentException('Type CLEAN_LEGACY_TAXONOMY to confirm catalogue cleanup.');
        }
        $result = $service->cleanupLegacy($runId, $actor);
        Log::write('Retired the noncanonical legacy tag catalogue for taxonomy snapshot #' . $runId);
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
        exit;
    }
    throw new InvalidArgumentException('Unsupported taxonomy cutover action.');
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    Log::write('Taxonomy cutover error: ' . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
?>
