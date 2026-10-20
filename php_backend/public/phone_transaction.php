<?php
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../services/PhoneClassification.php';
require_once __DIR__ . '/../models/Log.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(PhoneClassification::get((int)($_GET['id'] ?? 0)), JSON_THROW_ON_ERROR);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
        $nullableId = static function ($value): ?int {
            if ($value === null) return null;
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new InvalidArgumentException('Invalid classification.');
            return $id;
        };
        $row = PhoneClassification::save((int)($data['transaction_id'] ?? 0), (int)($data['tag_id'] ?? 0),
            (string)($data['revision'] ?? ''), $nullableId($data['category_id'] ?? null), $nullableId($data['segment_id'] ?? null));
        Log::write('Phone classification saved for transaction ' . $row['id']);
        echo json_encode($row, JSON_THROW_ON_ERROR);
    } else {
        http_response_code(405);
        header('Allow: GET, POST');
    }
} catch (DomainException $error) {
    http_response_code(409);
    echo json_encode(['error' => $error->getMessage()]);
} catch (InvalidArgumentException | JsonException $error) {
    http_response_code(400);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    Log::write('Phone classification error: ' . $error->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['error' => 'Classification could not be saved. Your selection has been kept.']);
}
