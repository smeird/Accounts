<?php
require_once __DIR__.'/../php_backend/models/TotalAssets.php';
function near($a,$b,$label){if(abs($a-$b)>.02)throw new RuntimeException($label.': '.$a.' != '.$b);}
near(RetirementTax::annual(12570),0,'Allowance');near(RetirementTax::annual(50270),7540,'Basic band');near(RetirementTax::annual(100000),27432,'Higher band');near(RetirementTax::annual(125140),42516,'Taper exhausted');near(RetirementTax::annual(150000),53703,'Additional band');
near(RetirementTax::annual(16537,'scotland'),3967*.19,'Scottish starter');near(RetirementTax::annual(29526,'scotland'),3967*.19+12989*.2,'Scottish basic');
$p=PensionPlanner::defaults();$p['age']=60;$p['access_age']=60;$p['retirement_age']=60;$p['inflation']=0;$p['pots']=[['provider'=>'Test','value'=>600000,'monthly'=>200,'fee'=>0,'valued_on'=>date('Y-m-d')]];
$m=MortgagePlanner::defaults();$s=TotalAssets::defaults($p,$m);$s['target_monthly']=2000;
$net=TotalAssets::forecast($s,$p,$m,0);$s['tax_enabled']=false;$gross=TotalAssets::forecast($s,$p,$m,0);
if($net['scenarios']['central']['monthly'] >= $gross['scenarios']['central']['monthly'])throw new RuntimeException('Net capacity must be below gross');
$r=$net['projection']['rows'][0];near($r['pension_draw']-$r['income_tax'],2000,'Gross-up funds spendable target');
foreach(['early_crash','lost_decade','inflation'] as $k)if($net['stress'][$k]['monthly']>$net['scenarios']['central']['monthly']+.02)throw new RuntimeException('Stress unexpectedly improves capacity');
if(array_column($net['comparisons'],'age')!==[60,65,67,70])throw new RuntimeException('Age comparisons');
$s['retirement_age']=65;$f=TotalAssets::forecast($s,$p,$m,0);near($f['scenarios']['central']['monthly'],$gross['comparisons'][1]['monthly'],'Comparison uses same simulation');
$old=$s;unset($old['tax_enabled'],$old['tax_region']);if(TotalAssets::validate($old)['tax_enabled']!==false)throw new RuntimeException('Legacy basis changed');
echo "Retirement tax, stress and comparison checks passed\n";
