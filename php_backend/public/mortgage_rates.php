<?php
require_once __DIR__ . '/../auth.php';
require_api_auth();
require_once __DIR__ . '/../services/MortgageRateCollector.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    $collector=new MortgageRateCollector(Database::getConnection());
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if($method==='GET')echo json_encode($collector->status(),JSON_THROW_ON_ERROR);
    elseif($method==='POST'){
        if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json') || ($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site')throw new InvalidArgumentException('Send a same-origin JSON request.');
        $raw=file_get_contents('php://input',false,null,0,4097);if(strlen($raw)>4096)throw new InvalidArgumentException('Request is too large.');
        $input=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        if(!is_string($input['source']??null))throw new InvalidArgumentException('Choose a supported lender.');
        session_write_close();
        echo json_encode($collector->refresh($input['source']),JSON_THROW_ON_ERROR);
    }else{http_response_code(405);header('Allow: GET, POST');echo json_encode(['error'=>'Method not allowed']);}
}catch(InvalidArgumentException|JsonException $e){http_response_code(400);echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){http_response_code($e->getCode()===409?409:500);echo json_encode(['error'=>$e->getCode()===409?$e->getMessage():'Lender rates could not load. Manual comparisons remain available.']);}
