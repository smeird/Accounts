<?php
require_once __DIR__.'/PensionPlanner.php';
require_once __DIR__.'/MortgagePlanner.php';
require_once __DIR__.'/Setting.php';
require_once __DIR__.'/RetirementTax.php';

// Installation-wide scenario; settings backups already preserve this revisioned document.
class TotalAssets {
    const KEY='total_assets_plan';
    public static function defaults(array $p, array $m): array {
        return ['retirement_age'=>max(ceil(PensionPlanner::currentAge($p)),$p['retirement_age']),
            'end_age'=>90,'target_monthly'=>$p['target_monthly'],
            'property_value'=>$m['property_value'],'other_assets'=>0,'other_debts'=>0,
            'extra_pension'=>0,'monthly_saving'=>0,'other_income'=>0,'income_age'=>$p['state_age'],
            'downsize'=>false,'replacement_home'=>0,'moving_costs'=>0,'home_growth'=>0,'liquid_growth'=>0,'working_income'=>0,'working_spending'=>0,'tax_enabled'=>true,'tax_region'=>'rest_uk'];
    }
    public static function validate(array $s): array {
        $s+=['working_income'=>0,'working_spending'=>0];
        $out=['tax_enabled'=>$s['tax_enabled']??false,'tax_region'=>$s['tax_region']??'rest_uk'];
        if(!is_bool($out['tax_enabled'])||!in_array($out['tax_region'],['rest_uk','scotland'],true))throw new InvalidArgumentException('Choose a valid tax basis and region.');
        foreach(['retirement_age'=>[18,90],'end_age'=>[60,110],'target_monthly'=>[0,1e6],
            'property_value'=>[0,1e9],'other_assets'=>[0,1e9],'other_debts'=>[0,1e9],
            'extra_pension'=>[0,1e6],'monthly_saving'=>[0,1e6],'other_income'=>[0,1e6],
            'working_income'=>[0,1e6],'working_spending'=>[0,1e6],'income_age'=>[18,110],'replacement_home'=>[0,1e9],'moving_costs'=>[0,1e9],
            'home_growth'=>[-10,15],'liquid_growth'=>[-10,15]] as $k=>[$min,$max]) {
            $v=$s[$k]??null;
            if(!is_numeric($v)||is_bool($v)||!is_finite((float)$v)||$v<$min||$v>$max) throw new InvalidArgumentException('Invalid '.str_replace('_',' ',$k).'.');
            $out[$k]=(float)$v;
        }
        if(!is_bool($s['downsize']??null)) throw new InvalidArgumentException('Choose whether to downsize.');
        $out['downsize']=$s['downsize'];
        if($out['end_age']<=$out['retirement_age']) throw new InvalidArgumentException('End age must be after retirement.');
        if($out['downsize']&&$out['replacement_home']<=0) throw new InvalidArgumentException('Enter the replacement home price before including downsizing.');
        return $out;
    }
    public static function load(): array {
        $p=PensionPlanner::load();$m=MortgagePlanner::load();
        $raw=Setting::get(self::KEY);$saved=$raw?json_decode($raw,true,512,JSON_THROW_ON_ERROR):null;
        $accounts=Database::getConnection()->query('SELECT name,ledger_balance,ledger_balance_date FROM accounts WHERE closed=0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        return ['pension'=>$p,'mortgage'=>$m,'accounts'=>$accounts,
            'scenario'=>array_replace(self::defaults($p['plan'],$m['plan']),($saved['scenario']??[])+['tax_enabled'=>$saved?($saved['scenario']['tax_enabled']??false):true]),'revision'=>$saved['revision']??0];
    }
    public static function save(array $s,int $revision): array {
        $db=Database::getConnection();$s=self::validate($s);
        $old=Setting::get(self::KEY);$existing=$old?json_decode($old,true,512,JSON_THROW_ON_ERROR):null;
        if(($existing['revision']??0)!==$revision) throw new RuntimeException('This scenario changed in another window. Reload before saving.',409);
        $new=json_encode(['scenario'=>$s,'revision'=>$revision+1],JSON_THROW_ON_ERROR);
        if($old===null){$q=$db->prepare('INSERT INTO settings (name,value) VALUES (:name,:value) ON CONFLICT(name) DO NOTHING');$q->execute(['name'=>self::KEY,'value'=>$new]);}
        else {$q=$db->prepare('UPDATE settings SET value=:value WHERE name=:name AND value=:old');$q->execute(['name'=>self::KEY,'value'=>$new,'old'=>$old]);}
        if($q->rowCount()!==1) throw new RuntimeException('This scenario changed in another window. Reload before saving.',409);
        return ['scenario'=>$s,'revision'=>$revision+1];
    }
    public static function forecast(array $s,array $p,array $m,float $bank): array {
        $s=self::validate($s);$p=array_replace(PensionPlanner::defaults(),$p);$age=PensionPlanner::currentAge($p);
        if($s['retirement_age']<$age) throw new InvalidArgumentException('Retirement age must be at or after your current age.');
        $pension=array_sum(array_column($p['pots'],'value'));
        $now=['pension'=>$pension,'bank'=>$bank,'other_assets'=>$s['other_assets'],'home'=>$s['property_value'],
            'mortgage'=>$m['balance'],'other_debts'=>$s['other_debts'],
            'equity'=>$s['property_value']-$m['balance']];
        $now['net']=$pension+$bank+$s['other_assets']+$now['equity']-$s['other_debts'];
        $now['downsize_cash']=$now['equity']-$s['replacement_home']-$s['moving_costs'];

        $scenarios=[];
        foreach(['lower'=>-2,'central'=>0,'higher'=>2] as $key=>$offset){
            $lo=0;$hi=1e7;
            for($i=0;$i<30;$i++){$mid=($lo+$hi)/2;if(self::simulate($s,$p,$m,$bank,$s['retirement_age'],$mid,$offset,false)['funded'])$lo=$mid;else $hi=$mid;}
            $scenarios[$key]=['monthly'=>$lo,'funded'=>self::simulate($s,$p,$m,$bank,$s['retirement_age'],$s['target_monthly'],$offset,false)['funded']];
        }
        $illustrated=$s['target_monthly']>0?$s['target_monthly']:$scenarios['central']['monthly'];
        $central=self::simulate($s,$p,$m,$bank,$s['retirement_age'],$illustrated,0,true);
        $earliest=null;
        if($s['target_monthly']>0) for($a=(int)ceil($age);$a<=min(90,$s['end_age']-1);$a++){
            if(self::simulate($s,$p,$m,$bank,$a,$s['target_monthly'],0,false)['funded']){$earliest=$a;break;}
        }
        $stress=[];
        foreach(['early_crash','lost_decade','inflation'] as $shock){
            $test=$s;$test['_shock']=$shock;$sp=$p;if($shock==='inflation')$sp['inflation']+=2;
            $income=self::sustainable($test,$sp,$m,$bank,$s['retirement_age']);
            $path=self::simulate($test,$sp,$m,$bank,$s['retirement_age'],$illustrated,0,true);
            $stress[$shock]=['monthly'=>$income,'funded'=>$path['funded'],'first_shortfall_age'=>$path['first_shortfall_age']];
        }
        $comparisons=[];
        $ages=array_unique([60,65,67,70,$s['retirement_age']]);sort($ages);
        foreach($ages as $a){
            if($a<$age||$a>=$s['end_age'])continue;
            $income=self::sustainable($s,$p,$m,$bank,$a);
            $path=self::simulate($s,$p,$m,$bank,$a,$s['target_monthly']?:$income,0,true);
            $comparisons[]=['age'=>$a,'monthly'=>$income,'daily'=>$income*12/365.2425,'mortgage'=>$path['retirement']['mortgage'],'funded'=>$s['target_monthly']>0?$path['funded']:null,'first_shortfall_age'=>$path['first_shortfall_age']];
        }
        return ['stress'=>$stress,'comparisons'=>$comparisons,'tax_enabled'=>$s['tax_enabled'],'now'=>$now,'age_now'=>$age,'projection'=>$central,'scenarios'=>$scenarios,'earliest_age'=>$earliest,'illustrated_monthly'=>$illustrated];
    }
    private static function sustainable(array $s,array $p,array $m,float $bank,float $age): float {
        $lo=0;$hi=1e7;
        for($i=0;$i<30;$i++){$mid=($lo+$hi)/2;if(self::simulate($s,$p,$m,$bank,$age,$mid,0,false)['funded'])$lo=$mid;else $hi=$mid;}
        return $lo;
    }
    private static function simulate(array $s,array $p,array $m,float $bank,float $retire,float $target,float $offset,bool $rows): array {
        $p['pots'][]=['value'=>0,'monthly'=>$s['extra_pension'],'fee'=>0];
        $age=PensionPlanner::currentAge($p);$retireMonth=(int)ceil(($retire-$age)*12);$months=(int)ceil(($s['end_age']-$age)*12);
        $inf=pow(1+$p['inflation']/100,1/12);$liquid=$bank+$s['other_assets']-$s['other_debts'];
        $pots=array_column($p['pots'],'value');$debt=$m['balance'];$home=$s['property_value'];$replacement=$s['replacement_home'];$cost=$s['moving_costs'];
        $payment=$m['monthly_payment']?:MortgagePlanner::payment($debt,$m['rate'],$m['remaining_months']);$rate=$m['rate'];
        // Saved overpayments are not assumed: only regular repayments apply here.
        $funded=true;$failed=null;$history=[];$atRetirement=null;$futureContributions=0;$mortgagePaidAge=$debt<=0?$age:null;
        for($month=0;$month<=$months;$month++){
            if(!$funded&&!$rows)return ['funded'=>false];
            $atAge=$age+$month/12;$factor=pow($inf,$month);
            if($month===$retireMonth){
                if(($s['_shock']??'')==='early_crash')foreach($pots as &$pot)$pot*=.75;unset($pot);
                $release=$s['downsize']?$home-$debt-$replacement-$cost:0;
                if($s['downsize']){$liquid+=$release;$home=$replacement;$debt=0;$mortgagePaidAge??=$atAge;}
                $atRetirement=['pension'=>array_sum($pots)/$factor,'liquid'=>$liquid/$factor,'home'=>$home/$factor,'mortgage'=>$debt/$factor,'released'=>$release/$factor,'contributions'=>$futureContributions];
            }
            $record=$rows&&($month%12===0||$month===$retireMonth||$month===$months);
            $row=['age'=>round(min($atAge,$s['end_age']),2),'pension'=>array_sum($pots)/$factor,'liquid'=>$liquid/$factor,'equity'=>($home-$debt)/$factor,'mortgage'=>$debt/$factor,'net'=>(array_sum($pots)+$liquid+$home-$debt)/$factor];
            $flow=array_fill_keys(['earnings','state_income','other_income','pension_draw','cash_draw','spending','mortgage_payment','pension_contribution','cash_saving','funding_gap','income_tax'],0.0);
            if($month===$months){if($record)$history[]=array_merge($row,$flow);break;}
            $next=$month+1;
            if($m['rate_change_month']>0&&$next===$m['rate_change_month']){$rate=$m['future_rate'];if($m['recalculate_on_change'])$payment=MortgagePlanner::payment($debt,$rate,max(1,$m['remaining_months']-$next+1));}
            if($month>=$retireMonth){
                $phase=($p['spending_profile']==='three_phase')?min(2,(int)floor(($atAge-$retire)*3/($s['end_age']-$retire))):0;
                $weight=[1,$p['slow_go_percent']/100,$p['no_go_percent']/100][$phase];
                $income=($atAge>=$p['state_age']?$p['state_annual']/12:0)+($atAge>=$s['income_age']?$s['other_income']:0);

                // Mortgage payments are additional to the living-cost target, until repayment.
                $mortgageDue=min($debt*(1+$rate/1200),$payment);
                if($next===$m['remaining_months'])$mortgageDue=$debt*(1+$rate/1200);
                $desired=$target*$weight*$factor+$mortgageDue+max(0,-$liquid);$liquid=max(0,$liquid);
                $grossIncome=$income*$factor;
                // Constant real 2026/27 bands; all pension/other income taxable, capital withdrawals untaxed.
                $taxFor=fn(float $draw):float=>$s['tax_enabled']?RetirementTax::annual(($grossIncome+$draw)/$factor*12,$s['tax_region'])/12*$factor:0;
                $fromCash=min($liquid,max(0,$desired+$taxFor(0)-$grossIncome));$liquid-=$fromCash;
                $need=max(0,$desired-$grossIncome-$fromCash);
                if($s['tax_enabled']&&$taxFor($need)>0&&$grossIncome+$fromCash-$taxFor(0)<$desired){
                    $lo=0;$hi=max(1,($need+$taxFor(0))*3);
                    for($t=0;$t<36;$t++){$mid=($lo+$hi)/2;if($grossIncome+$fromCash+$mid-$taxFor($mid)>=$desired)$hi=$mid;else $lo=$mid;}
                    $need=$hi;
                }
                $beforePension=array_sum($pots);
                if($atAge>=$p['access_age'])foreach($pots as &$pot){$take=min($pot,$need);$pot-=$take;$need-=$take;}unset($pot);
                $draw=$beforePension-array_sum($pots);$tax=$taxFor($draw);
                $need=max(0,$desired+$tax-$grossIncome-$fromCash-$draw);
                $flow['state_income']=$atAge>=$p['state_age']?$p['state_annual']/12:0;
                $flow['other_income']=$atAge>=$s['income_age']?$s['other_income']:0;
                $flow['spending']=$target*$weight;$flow['mortgage_payment']=$mortgageDue/$factor;
                $flow['income_tax']=$tax/$factor;
                $flow['cash_draw']=$fromCash/$factor;$flow['pension_draw']=$draw/$factor;$flow['funding_gap']=$need/$factor;
                if($need>0.01){$funded=false;$failed??=round($atAge,2);$liquid-=$need;}
            }
            if($month<$retireMonth){$flow['earnings']=$s['working_income'];$flow['spending']=$s['working_spending'];$flow['pension_contribution']=array_sum(array_column($p['pots'],'monthly'))/$factor;$flow['cash_saving']=$s['monthly_saving']/$factor;}
            if($debt>0){
                $due=$next===$m['remaining_months']?$debt*(1+$rate/1200):min($debt*(1+$rate/1200),$payment);
                if($month<$retireMonth&&$next===$m['remaining_months'])$liquid-=max(0,$due-$payment);
                if($month<$retireMonth)$flow['mortgage_payment']=$due/$factor;
                $debt=max(0,$debt*(1+$rate/1200)-$due);
                if($debt<=0)$mortgagePaidAge??=$age+$next/12;
                if($month<$retireMonth&&$next===$m['remaining_months']&&$liquid<0){
                    if($atAge>=$p['access_age'])foreach($pots as &$pot){$take=min($pot,-$liquid);$pot-=$take;$liquid+=$take;}unset($pot);
                    if($liquid<-.01){$funded=false;$failed??=round($atAge,2);}
                }
            }
            if($record)$history[]=array_merge($row,$flow);
            foreach($pots as $i=>&$pot){$entry=$p['pots'][$i];$growth=$month<$retireMonth?$p['growth']:$p['retirement_growth'];if(($s['_shock']??'')==='lost_decade'&&$month>=$retireMonth&&$month<$retireMonth+120)$growth=min(0,$growth);$pot*=pow((1+($growth+$offset)/100)*(1-$entry['fee']/100),1/12);
                if($month<$retireMonth){$pot+=$entry['monthly'];$futureContributions+=$entry['monthly'];}}
            unset($pot);
            $liquid*=pow(1+($s['liquid_growth']+$offset)/100,1/12);
            if($month<$retireMonth)$liquid+=$s['monthly_saving'];
            $home*=pow(1+$s['home_growth']/100,1/12);$replacement*=pow(1+$s['home_growth']/100,1/12);$cost*=$inf;
        }
        return ['funded'=>$funded,'first_shortfall_age'=>$failed,'retirement'=>$atRetirement,'mortgage_paid_age'=>$mortgagePaidAge!==null?round($mortgagePaidAge,2):null,'rows'=>$history];
    }
}
