<?php
// Controlled AI tagging and session-owned review, without automatic rule learning.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../services/AiTransactionTagger.php';
require_once __DIR__ . '/../AiCategoryTagger.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/Log.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    $owner = (string)($_SESSION['user_id'] ?? 'cli');
    if (($_SESSION['ai_tagging_owner'] ?? null) !== $owner) {
        $_SESSION['ai_tagging'] = [];
        $_SESSION['ai_tagging_owner'] = $owner;
    }
    $_SESSION['ai_tagging'] ??= [];
    $service = new AiTransactionTagger($_SESSION['ai_tagging']);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['reviews' => $service->reviews()]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!is_array($input)) throw new InvalidArgumentException('Send a valid JSON request.');
    $action = (string)($input['action'] ?? 'run');
    $token = (string)($input['token'] ?? '');
    if ($action !== 'run') {
        if ($action === 'dismiss') { $service->dismiss($token); $result = []; }
        elseif ($action === 'approve_classification') $result = ['processed' => $service->approveClassification($token, (int)($input['tag_id'] ?? 0))];
        elseif ($action === 'preview_rule') $result = $service->previewRule($token, (string)($input['alias'] ?? ''));
        elseif ($action === 'approve_rule') $result = ['processed' => $service->approveRule($token, !empty($input['confirm_overlap']), (string)($input['preview_token'] ?? ''))];
        else throw new InvalidArgumentException('Unknown AI tagging action.');
        Log::write('AI tagging review ' . $action . ': ' . json_encode($result));
        echo json_encode(['result' => $result, 'reviews' => $service->reviews()]);
        exit;
    }
    $apiKey = Setting::get('openai_api_token');
    if (!$apiKey) throw new InvalidArgumentException('Configure an OpenAI API token in Settings first.');
    $batch = $service->batch(isset($input['run_token']) ? (string)$input['run_token'] : null, (int)(Setting::get('ai_tag_batch_size') ?? 100));
    $local = $service->applySavedRules($batch);
    $tags = $service->catalogue();
    $prompt = ''; $content = ''; $tokens = 0; $suggestions = ['transactions' => []];
    if ($batch['groups']) {
        if (!$tags) throw new InvalidArgumentException('Create or approve reusable canonical tags in the catalogue before running AI tagging.');
        $prompt = $service->prompt($tags, $batch);
        $temperature = Setting::get('ai_temperature');
        $payload = [
            'model' => Setting::get('ai_model') ?? 'gpt-5-nano',
            'input' => [['role' => 'system', 'content' => 'Classify financial transaction evidence conservatively. Return JSON only.'], ['role' => 'user', 'content' => $prompt]],
            'temperature' => ($temperature === null || $temperature === '') ? 1.0 : (float)$temperature,
            'text' => ['format' => ['type' => 'json_object']],
        ];
        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey], CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $status !== 200) throw new RuntimeException('OpenAI tagging request failed. The current batch can be retried.');
        $response = json_decode($raw, true);
        if (!is_array($response)) throw new RuntimeException('OpenAI returned an unreadable response.');
        $content = AiCategoryTagger::extractOutputText($response);
        $suggestions = json_decode($content, true);
        if (!is_array($suggestions) || !isset($suggestions['transactions']) || !is_array($suggestions['transactions'])) throw new RuntimeException('OpenAI returned invalid tagging decisions. No AI decisions were applied.');
        $tokens = (int)($response['usage']['total_tokens'] ?? 0);
    }
    $out = $service->applyResponse($batch, $tags, $suggestions);
    $out['processed_ai'] = $out['processed'];
    $out['processed'] += $local;
    $out['processed_locally'] = $local;
    $out['tokens'] = $tokens;
    // Compatibility for older clients; review actions live in the permanent workspace.
    $out['review_required'] = array_values(array_filter($out['reviews'], fn($review) => $review['kind'] === 'classification'));
    if (Setting::get('ai_debug') === '1') $out['debug'] = ['prompt' => $prompt, 'response' => $content];
    Log::write('Smart tagging batch: ' . $out['processed'] . ' tagged, ' . $out['review_required_count'] . ' pending reviews, ' . $tokens . ' tokens.');
    echo json_encode($out);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    Log::write('Smart tagging failed: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['error' => 'Smart tagging could not complete this batch. Retry or review the server log.']);
}
