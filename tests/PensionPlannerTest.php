<?php
require_once __DIR__ . '/../php_backend/models/PensionPlanner.php';
$pensionPlan=PensionPlanner::defaults();
$pensionPlan=array_replace($pensionPlan,['age'=>60,'retirement_age'=>60,'end_age'=>90,'growth'=>0,'retirement_growth'=>0,'inflation'=>0]);
$pensionPlan['pots']=[['provider'=>'Test pension','value'=>360000,'monthly'=>0,'fee'=>0,'valued_on'=>date('Y-m-d')]];
$pensionPlan=PensionPlanner::validate($pensionPlan);
$pensionResult=PensionPlanner::forecast($pensionPlan);
assertEqual(1000.0,round($pensionResult['scenarios']['central']['monthly_today'],2),'Zero-return drawdown divides a £360k pot over 30 years');
assertEqual(0.0,round(array_slice($pensionResult['scenarios']['central']['rows'],-1)[0]['pot_today'],2),'Central drawdown reaches the chosen end age without borrowing');
assertEqual(32.85,round($pensionResult['scenarios']['central']['daily_today'],2),'Daily income uses the annual equivalent and average calendar year');
assertEqual(true,$pensionResult['scenarios']['lower']['monthly_today'] < 1000 && $pensionResult['scenarios']['higher']['monthly_today'] > 1000,'Growth scenarios change retirement income in the expected direction');

$contributionPlan=$pensionPlan;
$contributionPlan['age']=52;$contributionPlan['retirement_age']=62;$contributionPlan['end_age']=92;
$contributionPlan['pots'][0]['value']=100000;$contributionPlan['pots'][0]['monthly']=100;
$contributionResult=PensionPlanner::forecast($contributionPlan);
assertEqual(112000.0,round($contributionResult['scenarios']['central']['pot_future'],2),'Ten years of contributions reach the pot without adding tax relief twice');
$inflatedPlan=$contributionPlan;$inflatedPlan['inflation']=2.5;
$inflated=PensionPlanner::forecast($inflatedPlan)['scenarios']['central'];
assertEqual(112000.0,round($inflated['pot_future'],2),'Inflation does not change the nominal accumulation with zero nominal return');
assertEqual(true,$inflated['pot_today'] < $inflated['pot_future'],'Today’s money discounts the retirement pot for inflation');
$feePlan=$contributionPlan;$feePlan['pots'][0]['fee']=1;
assertEqual(true,PensionPlanner::forecast($feePlan)['scenarios']['central']['pot_future'] < 112000,'Provider fees reduce accumulation');

$bridge=$pensionPlan;$bridge['end_age']=80;$bridge['pots'][0]['value']=24000;$bridge['state_annual']=12000;$bridge['state_age']=67;
$bridgeResult=PensionPlanner::forecast($bridge)['scenarios']['central'];
assertEqual(285.71,round($bridgeResult['monthly_today'],2),'Early retirement is limited by the seven-year bridge before State Pension');
assertEqual(1000.0,round($bridgeResult['monthly_after_state_today'],2),'State Pension above bridge income remains available when it starts');
$stateOnly=$pensionPlan;$stateOnly['age']=67;$stateOnly['retirement_age']=67;$stateOnly['pots']=[];$stateOnly['state_annual']=12000;
assertEqual(1000.0,round(PensionPlanner::forecast($stateOnly)['scenarios']['central']['monthly_today'],2),'State-only income works without inventing a private pot');
$target=$contributionPlan;$target['target_monthly']=300;
assertEqual(62,PensionPlanner::forecast($target)['earliest_target_age'],'Optional spending target finds the earliest feasible whole-year age');
$oldAge=$pensionPlan;$oldAge['age_as_of']=(new DateTimeImmutable('today'))->modify('-365 days')->format('Y-m-d');
assertEqual(true,PensionPlanner::currentAge($oldAge)>60.99,'Age reference date advances age as time passes');
foreach (['negative'=>['pots'=>[['provider'=>'Test','value'=>-1,'monthly'=>0,'fee'=>0,'valued_on'=>date('Y-m-d')]]],
    'future valuation'=>['age_as_of'=>'2999-01-01'],'invalid calendar'=>['age_as_of'=>'2026-02-30'],
    'before access'=>['retirement_age'=>56],'invalid horizon'=>['end_age'=>60],'string amount'=>['state_annual'=>'100']] as $case=>$change) {
    try {PensionPlanner::validate(array_replace($pensionPlan,$change));$rejected=false;}catch(InvalidArgumentException $e){$rejected=true;}
    assertEqual(true,$rejected,'Pension validation rejects '.$case);
}
$db->exec('DELETE FROM pension_plans');
$savedPension=PensionPlanner::save($pensionPlan,0);
assertEqual(1,$savedPension['revision'],'First pension save creates a revision');
assertEqual('Test pension',PensionPlanner::load()['plan']['pots'][0]['provider'],'Saved provider data survives reload');
PensionPlanner::save($pensionPlan,1);
try {PensionPlanner::save($pensionPlan,1);$stalePension=false;}catch(RuntimeException $e){$stalePension=$e->getCode()===409;}
assertEqual(true,$stalePension,'Concurrent pension saves cannot overwrite a newer plan');
$overviewWithPension=InstantDashboard::getSnapshot();
assertEqual(360000.0,$overviewWithPension['pensions']['total'],'Financial Overview includes pension totals separately');
assertEqual(1000.0,round($overviewWithPension['pensions']['monthly_today'],2),'Financial Overview uses the saved forecast');

$phased=$pensionPlan;
$phased['spending_profile']='three_phase';
$phasedResult=PensionPlanner::forecast(PensionPlanner::validate($phased))['scenarios']['central'];
assertEqual(1250.0,round($phasedResult['monthly_today'],2),'Phased income brings spending forward from the same £360k pot');
assertEqual([1250.0,1000.0,750.0],array_map(fn($p)=>round($p['monthly_target_today'],2),$phasedResult['phases']),'Three phases use 100/80/60 purchasing-power targets');
assertEqual([60.0,70.0,80.0],array_column($phasedResult['phases'],'start_age'),'Retirement phases divide the horizon equally');
assertEqual(0.0,round(array_slice($phasedResult['rows'],-1)[0]['pot_today'],2),'Phased drawdown exhausts the pot only at the end');
$equalPhases=$phased;$equalPhases['slow_go_percent']=100;$equalPhases['no_go_percent']=100;
assertEqual(1000.0,round(PensionPlanner::forecast($equalPhases)['scenarios']['central']['monthly_today'],2),'Equal phase percentages reproduce level income');
$oldPlan=$pensionPlan;unset($oldPlan['spending_profile'],$oldPlan['slow_go_percent'],$oldPlan['no_go_percent']);
assertEqual('level',PensionPlanner::validate($oldPlan)['spending_profile'],'Old plans and backups default to level spending');
foreach (['bad profile'=>['spending_profile'=>'unknown'],'zero later spending'=>['no_go_percent'=>0],'increasing spending'=>['slow_go_percent'=>50,'no_go_percent'=>60],'string percentage'=>['slow_go_percent'=>'80']] as $case=>$change) {
    try {PensionPlanner::validate(array_replace($phased,$change));$rejected=false;}catch(InvalidArgumentException $e){$rejected=true;}
    assertEqual(true,$rejected,'Phased validation rejects '.$case);
}
$phasedState=$phased;$phasedState['state_annual']=12000;$phasedState['state_age']=80;
$phasedStateResult=PensionPlanner::forecast($phasedState)['scenarios']['central'];
assertEqual(true,min(array_column($phasedStateResult['rows'],'pot_today'))>=0,'Phased State Pension cannot fund earlier withdrawals by borrowing');
$phaseRevision=PensionPlanner::load()['revision'];PensionPlanner::save($phased,$phaseRevision);
assertEqual('three_phase',PensionPlanner::load()['plan']['spending_profile'],'Spending profile persists with the saved plan');
assertEqual(60.0,(float)PensionPlanner::load()['plan']['no_go_percent'],'Later phase percentage survives reload');
