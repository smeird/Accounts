<?php
require_once __DIR__.'/../auth.php';
require_api_auth();
require_once __DIR__.'/../models/TotalAssets.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if($method==='GET')echo json_encode(TotalAssets::load(),JSON_THROW_ON_ERROR);
    elseif(in_array($method,['POST','PUT'],true)){
        if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))throw new InvalidArgumentException('Send application/json.');
        $raw=file_get_contents('php://input',false,null,0,20001);
        if(strlen($raw)>20000)throw new InvalidArgumentException('Scenario is too large.');
        $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($input['scenario']??null))throw new InvalidArgumentException('A scenario is required.');
        $source=TotalAssets::load();
        if(isset($input['source_revisions'])&&$input['source_revisions']!==[$source['pension']['revision'],$source['mortgage']['revision']])throw new RuntimeException('The pension or mortgage plan changed. Reload to use its latest assumptions.',409);
        $s=TotalAssets::validate($input['scenario']);
        $bank=array_sum(array_column($source['accounts'],'ledger_balance'));
        $result=TotalAssets::forecast($s,$source['pension']['plan'],$source['mortgage']['plan'],$bank);
        if($method==='PUT'){
            if(!is_int($input['revision']??null)||$input['revision']<0)throw new InvalidArgumentException('A valid revision is required.');
            $result['saved']=TotalAssets::save($s,$input['revision']);
        }
        echo json_encode($result,JSON_THROW_ON_ERROR);
    }else{http_response_code(405);header('Allow: GET, POST, PUT');echo json_encode(['error'=>'Method not allowed']);}
}catch(InvalidArgumentException|JsonException $e){http_response_code(400);echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){http_response_code($e->getCode()===409?409:500);echo json_encode(['error'=>$e->getCode()===409?$e->getMessage():'Total Assets could not load. Check Database Health and retry.']);}
