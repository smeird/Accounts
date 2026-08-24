<?php
// Authenticated safety controls for the controlled tag-taxonomy rebuild.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../services/TagMigrationSafetyService.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json; charset=utf-8');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    $service = new TagMigrationSafetyService();

    if ($method === 'GET') {
        $action = (string)($_GET['action'] ?? 'overview');
        if ($action === 'rollback_preview') {
            $runId = (int)($_GET['run_id'] ?? 0);
            echo json_encode($service->rollbackPreview($runId), JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($action !== 'overview') {
            throw new InvalidArgumentException('Unsupported migration request.');
        }
        $schemaReady = $service->schemaReady();
        echo json_encode([
            'status' => 'ok',
            'schema_ready' => $schemaReady,
            'schema_message' => $schemaReady
                ? 'Phase 1 safety structures are ready.'
                : 'Run Database Health and apply the Phase 1 catalogue repairs before creating a snapshot.',
            'contract' => TagMigrationSafetyService::contract(),
            'current' => $service->currentClassificationPreview(),
            'runs' => $schemaReady ? $service->listRuns() : [],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['status' => 'error', 'message' => 'Use GET to review or POST to create and restore snapshots.']);
        exit;
    }

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('A JSON migration request is required.');
    }
    $action = (string)($payload['action'] ?? '');
    $createdBy = PHP_SAPI === 'cli'
        ? 'cli'
        : 'user:' . (string)($_SESSION['user_id'] ?? 'unknown');

    if ($action === 'create_snapshot') {
        if (($payload['confirm'] ?? '') !== 'CREATE_CLASSIFICATION_SNAPSHOT') {
            throw new InvalidArgumentException('Explicit snapshot confirmation is required.');
        }
        $run = $service->createSnapshot((string)($payload['name'] ?? ''), $createdBy);
        Log::write('Created immutable tag migration snapshot #' . $run['id'] . ' with ' . $run['transaction_count'] . ' transaction classifications');
        echo json_encode([
            'status' => 'created',
            'message' => 'Classification snapshot created without changing live transactions.',
            'run' => $run,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'restore_snapshot') {
        if (($payload['confirm'] ?? '') !== 'RESTORE_CLASSIFICATIONS') {
            throw new InvalidArgumentException('Type the restore confirmation before changing live classifications.');
        }
        $runId = (int)($payload['run_id'] ?? 0);
        $result = $service->restoreSnapshot($runId);
        Log::write('Restored classifications from tag migration snapshot #' . $runId . '; changed ' . $result['restored_transactions'] . ' transactions', 'WARNING');
        $result['message'] = 'The saved tag, category, and segment assignments were restored. Later transactions were left untouched.';
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
        exit;
    }

    throw new InvalidArgumentException('Unsupported migration action.');
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    Log::write('Tag migration safety error: ' . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'The tag migration safety request could not be completed.',
        'detail' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}

