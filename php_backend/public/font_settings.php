<?php
require_once __DIR__ . '/../models/Setting.php';
header('Content-Type: application/json');

echo json_encode(array_merge(Setting::getFonts(), Setting::getBrand()));
?>
