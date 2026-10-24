<?php
require_once __DIR__.'/../php_backend/services/MortgageRenewal.php';
require_once __DIR__.'/../php_backend/services/MortgageRateCollector.php';
$renew=array_replace(MortgagePlanner::defaults(),['balance'=>12000,'rate'=>0,'balance_date'=>'2026-01-31','remaining_months'=>12,'monthly_payment'=>1000,'deal_start'=>'2025-02-28','deal_end'=>'2026-07-30','property_value'=>20000,'review_months'=>6,
    'comparison_deals'=>[['name'=>'Test renewal','rate'=>12,'fixed_months'=>6,'fee'=>100,'cashback'=>20,'add_fee'=>false]]]);
$renew=MortgagePlanner::validate($renew);$r=MortgageRenewal::forecast($renew,'2026-02-01');
assertEqual(5,$r['elapsed_payments'],'Renewal only counts anchored payment dates on or before deal expiry');
assertEqual(7000.0,$r['balance'],'Renewal projects the outstanding balance before selecting a new rate');
assertEqual(7,$r['remaining_months'],'Renewal uses the contractual term remaining at expiry');
assertEqual(35.0,$r['ltv'],'Renewal LTV uses projected debt and user-supplied property value');
assertEqual('2026-01-30',$r['review_date'],'Personal review date clamps to a valid date');
assertEqual(true,$r['review_due'],'Renewal review reminder becomes due by its configured date');
assertEqual(MortgagePlanner::payment(7000,12,7),$r['comparisons'][0]['normal']['payment'],'Renewal re-amortises the projected balance, not today’s balance');
$renew['deal_end']='2026-07-31';$renew['extra_monthly']=500;$renew['lump_sum']=1000;
$r=MortgageRenewal::forecast($renew,'2026-02-01');
assertEqual(6,$r['elapsed_payments'],'A payment on the fixed expiry date uses the old rate');
assertEqual(2000.0,$r['balance_with_overpayments'],'Renewal includes immediate and monthly pre-renewal overpayments');
$feeDeal=['name'=>'Fee test','rate'=>0,'fixed_months'=>12,'fee'=>120,'cashback'=>20,'add_fee'=>false];
$c=MortgageRenewal::compare(1200,12,$feeDeal,100);
assertEqual(100.0,$c['payment'],'Upfront fee does not increase monthly repayments');
assertEqual(1300.0,$c['cash_paid'],'Comparison cash paid includes upfront fees and deducts cashback');
assertEqual(100.0,$c['financing_cost'],'Comparison financing cost excludes repaid principal');
$feeDeal['add_fee']=true;$c=MortgageRenewal::compare(1200,12,$feeDeal,100);
assertEqual(110.0,$c['payment'],'Financed product fee increases repayment principal');
assertEqual(100.0,$c['financing_cost'],'Financed fee is counted once in financing cost');
$c=MortgageRenewal::compare(0,12,$feeDeal,100);
assertEqual(0.0,$c['payment'],'Cleared mortgage never borrows a fee to open a needless new loan');
assertEqual(0.0,$c['cash_paid'],'Cleared mortgage never receives hypothetical cashback');
$short=$renew;$short['remaining_months']=2;
assertEqual(0,MortgageRenewal::forecast($short,'2026-08-01')['remaining_months'],'Renewal after contractual maturity does not invent a new term');
$legacy=MortgagePlanner::defaults();unset($legacy['deal_start'],$legacy['deal_end'],$legacy['property_value'],$legacy['review_months'],$legacy['comparison_deals']);
assertEqual([],MortgagePlanner::validate($legacy)['comparison_deals'],'Legacy saved plans and backups gain safe renewal defaults');
foreach(['reversed dates'=>['deal_start'=>'2026-08-01','deal_end'=>'2026-07-31'],'bad end'=>['deal_end'=>'2026-02-30'],'fractional reminder'=>['review_months'=>1.5],'bad comparison'=>['comparison_deals'=>[['name'=>'bad','rate'=>-1]]],'too many deals'=>['comparison_deals'=>array_fill(0,13,$feeDeal)]] as $label=>$change){
    try{MortgagePlanner::validate(array_replace($renew,$change));$bad=false;}catch(InvalidArgumentException $e){$bad=true;}assertEqual(true,$bad,'Renewal validation rejects '.$label);
}
$fixtures=__DIR__.'/fixtures/mortgage_rates/';
foreach(['natwest'=>'html','hsbc'=>'html','barclays'=>'json'] as $key=>$ext){
    $products=MortgageRateCollector::parse($key,file_get_contents($fixtures.$key.'.'.$ext));
    assertEqual(1,count($products),'Collector '.$key.' accepts one complete fixed-rate fixture and excludes other products');
    assertEqual(75.0,$products[0]['max_ltv'],'Collector '.$key.' retains LTV rather than confusing it with interest');
    if($key==='natwest')assertEqual(false,str_contains($products[0]['restrictions'],'first-time buyer'),'Hidden NatWest variants do not leak into product conditions');
    if($key==='hsbc'){assertEqual(4.5,$products[0]['rate'],'HSBC collector uses interest rate rather than APRC or reversion rate');assertEqual(300.0,$products[0]['cashback'],'HSBC collector preserves the specific cashback variant');}
}
assertEqual([],MortgageRateCollector::parse('hsbc','<html>Access denied</html>'),'A challenge or changed markup cannot become a successful rate collection');
$db->exec("DELETE FROM settings WHERE name LIKE 'mortgage_rates_%'");
$collector=new MortgageRateCollector($db,fn($url)=>file_get_contents($fixtures.'hsbc.html'));
$collector->refresh('hsbc');$status=$collector->status()['sources'][1];
assertEqual(1,count($status['products']),'A successful collector stores parsed products');
assertEqual(true,$status['last_success']!==null,'Collector records an actual successful update timestamp');
$old=$status['last_success'];
$s=$db->prepare('UPDATE settings SET value=:value WHERE name=:name');$status['last_attempt']='2000-01-01T00:00:00Z';$s->execute(['name'=>'mortgage_rates_hsbc','value'=>json_encode($status)]);
$failed=new MortgageRateCollector($db,fn($url)=>'<html>Page changed</html>');$failed->refresh('hsbc');$status=$failed->status()['sources'][1];
assertEqual($old,$status['last_success'],'Failed collection never advances last successful update');
assertEqual(1,count($status['products']),'Failed collection retains the last successful products');
assertEqual(true,$status['error']!==null,'Failed collection reports a visible source error');
$attempt=$status['last_attempt'];$failed->refresh('hsbc');assertEqual($attempt,$failed->status()['sources'][1]['last_attempt'],'Per-lender cooldown prevents repeated refresh requests');
assertEqual(3,count($failed->status()['sources']),'All three lenders report independent status');
try{MortgageRateCollector::download('http://127.0.0.1/admin');$blocked=false;}catch(InvalidArgumentException $e){$blocked=true;}
assertEqual(true,$blocked,'Collector cannot request arbitrary or internal URLs');
