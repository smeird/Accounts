<?php
// Authenticated Phase 2 taxonomy discovery. AI output is staged for review and
// never writes to live transaction classifications or the active tag catalogue.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../services/TagTaxonomyDiscoveryService.php';
require_once __DIR__ . '/../TagTaxonomyDiscoveryAi.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json; charset=utf-8');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    $service = new TagTaxonomyDiscoveryService();
    if ($method === 'GET') {
        if (!$service->schemaReady()) {
            echo json_encode([
                'status' => 'ok',
                'discovery' => [
                    'schema_ready' => false,
                    'schema_message' => 'Run Database Health and apply the Phase 2 catalogue repairs before preparing discovery.',
                    'runs' => [],
                    'selected_run' => null,
                    'categories' => [],
                    'metrics' => [],
                    'proposals' => [],
                ],
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
        $runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : null;
        echo json_encode(['status' => 'ok', 'discovery' => $service->overview($runId)], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['status' => 'error', 'message' => 'Use GET to review or POST to stage taxonomy work.']);
        exit;
    }

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) throw new InvalidArgumentException('A JSON discovery request is required.');
    $action = (string)($payload['action'] ?? '');
    $runId = (int)($payload['run_id'] ?? 0);
    $actor = 'user:' . (string)($_SESSION['user_id'] ?? 'unknown');

    if ($action === 'prepare') {
        if (($payload['confirm'] ?? '') !== 'PREPARE_TAXONOMY_DISCOVERY') {
            throw new InvalidArgumentException('Explicit discovery preparation confirmation is required.');
        }
        $view = $service->prepare($runId);
        Log::write('Prepared review-only taxonomy discovery for snapshot #' . $runId . ' with ' . $view['metrics']['patterns'] . ' patterns');
        echo json_encode(['status' => 'prepared', 'message' => 'Transaction patterns are staged. Live classifications were not changed.', 'discovery' => $view], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'analyse_batch') {
        $apiKey = Setting::get('openai_api_token');
        if (!$apiKey) throw new InvalidArgumentException('Configure an OpenAI API token before analysing a discovery batch.');
        $limit = max(1, min(60, (int)($payload['limit'] ?? 30)));
        $patterns = $service->pendingPatterns($runId, $limit);
        if (empty($patterns)) {
            echo json_encode(['status' => 'complete', 'message' => 'Every staged pattern already has a proposal.', 'tokens' => 0, 'discovery' => $service->overview($runId)], JSON_UNESCAPED_SLASHES);
            exit;
        }
        $overview = $service->overview($runId);
        $approved = $service->stagedCanonicalContext($runId);
        $rejectedNames = $service->rejectedNames($runId);
        $prompt = TagTaxonomyDiscoveryAi::buildPrompt($patterns, $overview['categories'], $approved, $rejectedNames);
        $temperature = Setting::get('ai_temperature');
        if ($temperature === null || $temperature === '') $temperature = 1;
        $request = [
            'model' => Setting::get('ai_model') ?? 'gpt-5-nano',
            'input' => [
                ['role' => 'system', 'content' => 'You design compact financial tag taxonomies. Return JSON only.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => (float)$temperature,
            'text' => ['format' => ['type' => 'json_object']],
        ];
        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS => json_encode($request),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
        ]);
        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $statusCode !== 200) {
            Log::write('Taxonomy discovery AI error: HTTP ' . $statusCode . ' ' . ($curlError ?: ($raw ?: 'no response')), 'ERROR');
            throw new RuntimeException('OpenAI could not analyse this taxonomy batch.');
        }
        $response = json_decode((string)$raw, true);
        if (!is_array($response)) throw new RuntimeException('OpenAI returned an invalid discovery response.');
        $content = TagTaxonomyDiscoveryAi::extractOutputText($response);
        $suggestions = json_decode($content, true);
        if (!is_array($suggestions)) {
            Log::write('Taxonomy discovery AI invalid JSON: ' . $content, 'ERROR');
            throw new RuntimeException('OpenAI returned invalid taxonomy suggestions.');
        }
        $patternIds = array_map(function($row) { return (int)$row['id']; }, $patterns);
        $categoryIds = array_map(function($row) { return (int)$row['id']; }, $overview['categories']);
        $validated = TagTaxonomyDiscoveryAi::validate($suggestions, $patternIds, $categoryIds, $rejectedNames);
        if (empty($validated['accepted'])) {
            throw new RuntimeException('No safe taxonomy proposals were returned for this batch.');
        }
        $view = $service->applyAiAssignments($runId, $validated['accepted']);
        $tokens = (int)($response['usage']['total_tokens'] ?? 0);
        Log::write('Staged ' . count($validated['accepted']) . ' taxonomy pattern proposals for snapshot #' . $runId . " using $tokens tokens; no live classifications changed");
        $output = [
            'status' => 'analysed',
            'message' => count($validated['accepted']) . ' pattern proposals were staged for review.',
            'accepted' => count($validated['accepted']),
            'rejected' => count($validated['rejected']),
            'tokens' => $tokens,
            'discovery' => $view,
        ];
        if (Setting::get('ai_debug') === '1') {
            $output['debug'] = ['prompt' => $prompt, 'response' => $content, 'rejected' => $validated['rejected']];
        }
        echo json_encode($output, JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'review_proposal') {
        $proposalId = (int)($payload['proposal_id'] ?? 0);
        $view = $service->reviewProposal($runId, $proposalId, $payload, $actor);
        Log::write('Reviewed staged taxonomy proposal #' . $proposalId . ' for snapshot #' . $runId . '; live classifications unchanged');
        echo json_encode(['status' => 'reviewed', 'message' => 'The staged canonical tag was updated.', 'discovery' => $view], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'mark_ready') {
        $deferRemaining = ($payload['defer_remaining'] ?? false) === true;
        $expectedConfirmation = $deferRemaining ? 'MARK_TAXONOMY_READY_WITH_DEFERRED' : 'MARK_TAXONOMY_READY';
        if (($payload['confirm'] ?? '') !== $expectedConfirmation) {
            throw new InvalidArgumentException('Explicit readiness confirmation is required.');
        }
        $view = $service->markReady($runId, $deferRemaining);
        $deferredPatterns = (int)($view['metrics']['deferred_patterns'] ?? 0);
        Log::write('Marked staged taxonomy ready for snapshot #' . $runId . ' with ' . $deferredPatterns . ' patterns deferred; no live classifications changed');
        $message = $deferredPatterns > 0
            ? 'The reviewed taxonomy is ready and the unresolved remainder was safely deferred.'
            : 'The reviewed taxonomy is ready for a later cutover phase.';
        echo json_encode(['status' => 'ready', 'message' => $message, 'discovery' => $view], JSON_UNESCAPED_SLASHES);
        exit;
    }

    throw new InvalidArgumentException('Unsupported taxonomy discovery action.');
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    Log::write('Taxonomy discovery error: ' . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
?>
