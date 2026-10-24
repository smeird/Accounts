<?php
// Read-only collector harness. Offline by default; --live requests only the
// three allowlisted public sources and never stores data or touches the ledger.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/../php_backend/services/MortgageRateCollector.php';
$live=in_array('--live',$argv,true);$failed=false;
foreach(MortgageRateCollector::SOURCES as $key=>$source){
    try{
        $path=__DIR__.'/../tests/fixtures/mortgage_rates/'.$key.($key==='barclays'?'.json':'.html');
        $body=$live?MortgageRateCollector::download($source['fetch']):file_get_contents($path);
        $products=MortgageRateCollector::parse($key,$body);
        if(!$products)throw new RuntimeException('No recognised products');
        echo json_encode(['source'=>$key,'mode'=>$live?'live':'fixture','checked_at'=>gmdate('c'),'products'=>count($products),'sample'=>$products[0]],JSON_UNESCAPED_SLASHES).PHP_EOL;
    }catch(Throwable $e){$failed=true;fwrite(STDERR,$key.': '.$e->getMessage().PHP_EOL);}
}
exit($failed?1:0);
