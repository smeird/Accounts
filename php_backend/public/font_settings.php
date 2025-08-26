<?php
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/Log.php';
header('Content-Type: application/json');

echo json_encode(array_merge(Setting::getFonts(), Setting::getBrand()));
?>
