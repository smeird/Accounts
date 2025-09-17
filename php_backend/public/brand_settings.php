<?php
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/Setting.php';
header('Content-Type: application/json');

echo json_encode(Setting::getBrand());
