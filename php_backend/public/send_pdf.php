<?php
// Receives a PDF report upload and emails it to a configured address.
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Log.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['report'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'No PDF uploaded']);
        exit;
    }

    $file = $_FILES['report'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Upload failed']);
        exit;
    }

    $uploadDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $uploadedName = isset($file['name']) ? basename($file['name']) : '';
    $sanitized = preg_replace('/[^A-Za-z0-9_.-]/', '_', $uploadedName);
    $filename = $sanitized ?: 'report_' . date('Ymd_His') . '.pdf';
    $path = $uploadDir . '/' . $filename;
    move_uploaded_file($file['tmp_name'], $path);

    // Build a basic email with the PDF attached. Adjust address as needed.
    $to = getenv('REPORT_EMAIL') ?: 'admin@example.com';
    $subject = 'Transaction Report PDF';
    $message = 'Attached is the generated report.';
    $boundary = md5(uniqid());
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

    $body = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=\"UTF-8\"\r\n\r\n";
    $body .= $message . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: application/pdf; name=\"$filename\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "Content-Disposition: attachment; filename=\"$filename\"\r\n\r\n";
    $body .= chunk_split(base64_encode(file_get_contents($path))) . "\r\n";
    $body .= "--$boundary--";

    @mail($to, $subject, $body, $headers);
    Log::write("PDF report received: $filename");

    echo json_encode(['status' => 'ok']);
} catch (Exception $e) {
    http_response_code(500);
    Log::write('send_pdf error: ' . $e->getMessage(), 'ERROR');
    echo json_encode(['status' => 'error', 'message' => 'Server error']);
}
