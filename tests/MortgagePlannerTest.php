<?php
require_once __DIR__ . '/../php_backend/models/MortgagePlanner.php';
$mortgage=MortgagePlanner::defaults();
$mortgage=array_replace($mortgage,['balance'=>12000,'rate'=>0,'remaining_months'=>12,'monthly_payment'=>1000]);
$mortgage=MortgagePlanner::validate($mortgage);
$plain=MortgagePlanner::forecast($mortgage);
assertEqual(12,$plain['normal']['payoff_months'],'Zero-interest mortgage clears in twelve £1k payments');
assertEqual(0.0,$plain['normal']['total_interest'],'Zero-interest mortgage accrues no interest');
assertEqual(12000.0,$plain['normal']['total_paid'],'Repayment totals include the outstanding capital');
assertEqual(1000.0,$plain['required_payment'],'Calculated payment handles zero interest without division by zero');
$extra=$mortgage;$extra['extra_monthly']=1000;
$extraResult=MortgagePlanner::forecast($extra);
assertEqual(6,$extraResult['overpay']['payoff_months'],'Monthly overpayments shorten the payoff period');
assertEqual(6,$extraResult['months_saved'],'Time saved compares actual payoff months');
$lump=$mortgage;$lump['lump_sum']=6000;
assertEqual(6,MortgagePlanner::forecast($lump)['overpay']['payoff_months'],'Immediate lump sum reduces capital before future payments');
$lump['lump_sum']=12000;
$cleared=MortgagePlanner::forecast($lump)['overpay'];
assertEqual(0,$cleared['payoff_months'],'A full-balance lump sum clears the mortgage immediately');
assertEqual(0.0,$cleared['initial_payment'],'An immediately cleared mortgage has no later monthly payment');
assertEqual(12000.0,$cleared['total_paid'],'Total paid includes the immediate lump sum');
$smallMortgage=$mortgage;$smallMortgage['balance']=500;
assertEqual(500.0,MortgagePlanner::forecast($smallMortgage)['normal']['initial_payment'],'Final-only payment is capped at the remaining debt');
$oneMonth=$mortgage;$oneMonth['balance']=1000;$oneMonth['rate']=12;$oneMonth['remaining_months']=1;$oneMonth['monthly_payment']=0;
$oneResult=MortgagePlanner::forecast($oneMonth)['normal'];
assertEqual(1010.0,$oneResult['total_paid'],'Monthly interest is added before the payment');
assertEqual(10.0,$oneResult['first_interest'],'First month interest uses annual rate divided by twelve');
assertEqual(1000.0,$oneResult['first_principal'],'Principal excludes interest charged');
$typical=$mortgage;$typical['balance']=200000;$typical['rate']=5;$typical['remaining_months']=300;$typical['monthly_payment']=0;
$typicalResult=MortgagePlanner::forecast($typical)['normal'];
assertEqual(1169.18,MortgagePlanner::payment(200000,5,300),'Mortgage payment matches the standard annuity formula');
assertEqual(300,$typicalResult['payoff_months'],'Calculated payments clear at the contractual term with final penny adjustment');
assertEqual(833.33,$typicalResult['first_interest'],'Mortgage interest is rounded to pennies each month');
$typical['extra_monthly']=200;$typical['lump_sum']=10000;
$savings=MortgagePlanner::forecast($typical);
assertEqual(true,$savings['interest_saved']>0 && $savings['months_saved']>0,'Combined overpayments save interest and time');
assertEqual(200000.0,round($savings['overpay']['total_paid']-$savings['overpay']['total_interest'],2),'Total paid less interest accounts for all capital including lump sum');
$reset=$mortgage;$reset['rate_change_month']=7;$reset['future_rate']=12;
$resetResult=MortgagePlanner::forecast($reset)['normal'];
assertEqual(MortgagePlanner::payment(6000,12,6),$resetResult['payment_after_change'],'Rate reset uses the remaining balance and contractual months');
assertEqual(12,$resetResult['payoff_months'],'Recalculating after a rate reset preserves the contractual term');
$reset['recalculate_on_change']=false;
$held=MortgagePlanner::forecast($reset)['normal'];
assertEqual(1000.0,$held['payment_after_change'],'Fixed-payment rate scenario does not silently increase payments');
assertEqual(true,$held['balance_at_term']>0 && $held['payoff_months']>12,'Higher rates can leave a term-end shortfall when payments stay fixed');
$reset['recalculate_on_change']=true;$reset['rate_change_month']=1;
assertEqual(MortgagePlanner::payment(12000,12,12),MortgagePlanner::forecast($reset)['normal']['initial_payment'],'A rate change on payment one affects the initial payment');
$insufficient=$mortgage;$insufficient['rate']=12;$insufficient['monthly_payment']=1;
$insufficientResult=MortgagePlanner::forecast($insufficient);
assertEqual(null,$insufficientResult['normal']['payoff_date'],'A growing loan is never described as paid off at term end');
assertEqual(true,$insufficientResult['normal']['growing_balance'],'Payments below interest explicitly flag growing debt');
assertEqual(null,$insufficientResult['interest_saved'],'Unrepaid illustrations do not invent lifetime interest savings');
$huge=$insufficient;$huge['balance']=1e9;$huge['rate']=25;$huge['remaining_months']=600;
$hugeResult=MortgagePlanner::forecast($huge)['normal'];
assertEqual(null,$hugeResult['balance_at_term'],'An illustration stopped before maturity has no invented maturity balance');
assertEqual(null,$hugeResult['interest_to_term'],'An illustration stopped early has no invented term-end interest');
assertEqual('2026-02-28',MortgagePlanner::monthDate('2026-01-31',1),'Month-end payment dates clamp to February');
assertEqual('2026-03-31',MortgagePlanner::monthDate('2026-01-31',2),'Payment dates retain the original anchor day after February');
foreach (['negative balance'=>['balance'=>-1],'oversized lump sum'=>['lump_sum'=>12001],
    'fractional term'=>['remaining_months'=>12.5],'late rate change'=>['rate_change_month'=>13],
    'future date'=>['balance_date'=>'2999-01-01'],'invalid date'=>['balance_date'=>'2026-02-30'],
    'string payment'=>['monthly_payment'=>'1000'],'invalid mode'=>['recalculate_on_change'=>'yes']] as $name=>$change) {
    try {MortgagePlanner::validate(array_replace($mortgage,$change));$rejected=false;}catch(InvalidArgumentException $e){$rejected=true;}
    assertEqual(true,$rejected,'Mortgage validation rejects '.$name);
}
$db->exec('DELETE FROM mortgage_plans');
$savedMortgage=MortgagePlanner::save($mortgage,0);
assertEqual(1,$savedMortgage['revision'],'Mortgage save starts a revision');
assertEqual(12000.0,(float)MortgagePlanner::load()['plan']['balance'],'Mortgage balance survives save and reload');
MortgagePlanner::save($mortgage,1);
try {MortgagePlanner::save($mortgage,1);$staleMortgage=false;}catch(RuntimeException $e){$staleMortgage=$e->getCode()===409;}
assertEqual(true,$staleMortgage,'Concurrent mortgage saves cannot overwrite a newer revision');
$beforeMortgageBalance=InstantDashboard::getSnapshot()['headline']['balance'];
$overviewWithMortgage=InstantDashboard::getSnapshot();
assertEqual(12000.0,$overviewWithMortgage['mortgage']['balance'],'Financial Overview includes saved mortgage debt separately');
assertEqual($beforeMortgageBalance,$overviewWithMortgage['headline']['balance'],'Mortgage debt does not alter bank balance totals');
