<?php
// Handles OFX/QFX uploads and returns a structured import summary.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../services/OfxImportService.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['status' => 'error', 'message' => 'Use POST to upload statement files.']);
    exit;
}

if (!isset($_FILES['ofx_files']) || !is_array($_FILES['ofx_files'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Choose at least one OFX or QFX file.']);
    exit;
}

$files = $_FILES['ofx_files'];
$names = isset($files['name']) ? (array)$files['name'] : [];
$temporaryNames = isset($files['tmp_name']) ? (array)$files['tmp_name'] : [];
$errors = isset($files['error']) ? (array)$files['error'] : [];
$results = [];
$service = new OfxImportService();
$uploadErrorMessages = [
    UPLOAD_ERR_INI_SIZE => 'The file is larger than the server upload limit.',
    UPLOAD_ERR_FORM_SIZE => 'The file is larger than the permitted form limit.',
    UPLOAD_ERR_PARTIAL => 'The file upload was interrupted before it completed.',
    UPLOAD_ERR_NO_FILE => 'No file was supplied.',
    UPLOAD_ERR_NO_TMP_DIR => 'The server upload folder is unavailable.',
    UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded file.',
    UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload.',
];

if (!$names) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Choose at least one OFX or QFX file.']);
    exit;
}
if (count($names) > 20) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Import no more than 20 statement files at once.']);
    exit;
}

foreach ($names as $index => $rawName) {
    $filename = basename((string)$rawName) ?: 'statement.ofx';
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $error = (int)($errors[$index] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        $message = $uploadErrorMessages[$error] ?? 'The file could not be uploaded.';
        $results[] = OfxImportService::uploadErrorResult($filename, $message);
        Log::write("OFX upload error for $filename: $message", 'ERROR');
        continue;
    }
    if (!in_array($extension, ['ofx', 'qfx'], true)) {
        $results[] = OfxImportService::uploadErrorResult($filename, 'Only OFX and QFX statement files are supported.');
        continue;
    }

    $temporaryName = (string)($temporaryNames[$index] ?? '');
    $contents = $temporaryName !== '' ? file_get_contents($temporaryName) : false;
    if ($contents === false) {
        $results[] = OfxImportService::uploadErrorResult($filename, 'The uploaded file could not be read.');
        Log::write("OFX upload read error for $filename", 'ERROR');
        continue;
    }
    $results[] = $service->importContent($filename, $contents);
}

$response = OfxImportService::summarise($results);
if ($response['status'] === 'error') {
    http_response_code(422);
}
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
