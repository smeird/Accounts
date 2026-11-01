<?php
putenv('DB_DSN=sqlite::memory:');
require_once __DIR__.'/../php_backend/models/TotalAssets.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$p=PensionPlanner::defaults();$p['age']=60;$p['access_age']=60;$p['retirement_age']=60;$p['end_age']=70;$p['inflation']=0;$p['growth']=0;$p['retirement_growth']=0;
$p['pots']=[['provider'=>'Test','value'=>120000,'monthly'=>0,'fee'=>0,'valued_on'=>date('Y-m-d')]];
$m=MortgagePlanner::defaults();$s=TotalAssets::defaults($p,$m);$s['target_monthly']=1000;
$f=TotalAssets::forecast($s,$p,$m,0);
check($f['projection']['funded'],'£120k funds £1k for 120 months');check(abs($f['scenarios']['central']['monthly']-1000)<.1,'Known zero-growth drawdown');
$s['target_monthly']=1001;check(!TotalAssets::forecast($s,$p,$m,0)['projection']['funded'],'Reject exhausted pot');
$p['access_age']=65;$s['target_monthly']=500;
check(!TotalAssets::forecast($s,$p,$m,0)['projection']['funded'],'Pension locked before access age');
check(TotalAssets::forecast($s,$p,$m,30000)['projection']['funded'],'Liquid bridge funds early retirement');
$s['property_value']=300000;$m['balance']=100000;$m['rate']=0;$m['remaining_months']=120;$m['monthly_payment']=1000;
$s['replacement_home']=150000;$s['moving_costs']=10000;$s['downsize']=true;
$f=TotalAssets::forecast($s,$p,$m,0);check($f['now']['equity']===200000.0,'Equity excludes mortgage');check($f['now']['downsize_cash']===40000.0,'Downsizing after all costs');check(abs($f['projection']['retirement']['liquid']-40000)<.01,'Released proceeds once only');check($f['projection']['retirement']['mortgage']===0.0,'Sale settles mortgage');check(abs($f['now']['net']-320000)<.01,'No double counted home');
$s['replacement_home']=250000;$f=TotalAssets::forecast($s,$p,$m,0);check(!$f['projection']['funded'],'Unaffordable downsize needs bridge cash');
$p['access_age']=60;$s['downsize']=false;$s['retirement_age']=61;$s['extra_pension']=100;$s['monthly_saving']=100;$s['target_monthly']=0;
$f=TotalAssets::forecast($s,$p,$m,0);check(abs($f['projection']['retirement']['pension']-121200)<.01,'Future extra pension contributions accrue');check(abs($f['projection']['retirement']['liquid']-1200)<.01,'Future savings accrue');check($f['now']['pension']===120000,'Hypothetical contributions excluded today');check(abs($f['projection']['retirement']['contributions']-1200)<.01,'Contributions reported once');
// A balloon must be paid at maturity, with no implicit refinancing.
$m['remaining_months']=1;$m['monthly_payment']=1;$s['retirement_age']=60;$s['extra_pension']=0;$s['monthly_saving']=0;$p['pots'][0]['value']=0;
check(!TotalAssets::forecast($s,$p,$m,0)['projection']['funded'],'Maturity debt is not silently forgiven');
$s['other_income']=1000;$s['income_age']=60;$m['balance']=0;$s['target_monthly']=1000;
check(TotalAssets::forecast($s,$p,$m,0)['projection']['funded'],'Separate recurring income funds target');$s['income_age']=61;check(!TotalAssets::forecast($s,$p,$m,0)['projection']['funded'],'Future income cannot fund earlier withdrawals');
foreach([['other_assets',-1],['retirement_age',111],['liquid_growth',INF]] as [$key,$value]){$bad=$s;$bad[$key]=$value;try{TotalAssets::validate($bad);throw new RuntimeException('Accepted invalid '.$key);}catch(InvalidArgumentException $e){}}
$db=Database::getConnection();$db->exec('CREATE TABLE settings (name TEXT PRIMARY KEY,value TEXT NOT NULL)');
$r=TotalAssets::save($s,0);check($r['revision']===1,'First save');TotalAssets::save($s,1);
try{TotalAssets::save($s,1);throw new RuntimeException('Stale save accepted');}catch(RuntimeException $e){check($e->getCode()===409,'Conflict protects edits');}
echo "Total Assets model checks passed\n";
