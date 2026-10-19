<?php
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../models/MortgagePlanner.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method==='GET') {
        $result=MortgagePlanner::load();
        echo json_encode($result,JSON_THROW_ON_ERROR);
    } elseif ($method==='POST' || $method==='PUT') {
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''),'application/json')) throw new InvalidArgumentException('Send application/json.');
        $raw=file_get_contents('php://input',false,null,0,200001);
        if (strlen($raw)>200000) throw new InvalidArgumentException('Plan is too large.');
        $input=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($input) || !is_array($input['plan'] ?? null)) throw new InvalidArgumentException('A plan is required.');
        $plan=MortgagePlanner::validate($input['plan']);
        if ($method==='PUT') {
            if (!is_int($input['revision'] ?? null) || $input['revision']<0) throw new InvalidArgumentException('A valid revision is required.');
            echo json_encode(MortgagePlanner::save($plan,$input['revision']),JSON_THROW_ON_ERROR);
        } else echo json_encode(MortgagePlanner::forecast($plan),JSON_THROW_ON_ERROR);
    } else { http_response_code(405); header('Allow: GET, POST, PUT'); echo json_encode(['error'=>'Method not allowed']); }
} catch (InvalidArgumentException|JsonException $e) {
    http_response_code(400); echo json_encode(['error'=>$e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code($e->getCode()===409?409:500);
    echo json_encode(['error'=>$e->getCode()===409?$e->getMessage():'Mortgages could not load. Check Database Health and try again.']);
}
