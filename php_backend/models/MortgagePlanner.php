<?php
require_once __DIR__ . '/../Database.php';

// Deterministic repayment illustrations; independent of account and ledger balances.
class MortgagePlanner {
    public static function defaults(): array {
        return ['lender'=>'My mortgage','balance'=>0,'balance_date'=>date('Y-m-d'),'monthly_payment'=>0,
            'remaining_months'=>240,'rate'=>4.5,'extra_monthly'=>0,'lump_sum'=>0,
            'rate_change_month'=>0,'future_rate'=>4.5,'recalculate_on_change'=>true,
            'deal_start'=>'','deal_end'=>'','property_value'=>0.0,'review_months'=>6,'comparison_deals'=>[]];
    }

    public static function validate(array $input): array {
        $out=[];
        if (!is_string($input['lender'] ?? null) || trim($input['lender'])==='' || strlen($input['lender'])>120) {
            throw new InvalidArgumentException('Enter a mortgage name of at most 120 characters.');
        }
        $out['lender']=trim($input['lender']);
        foreach (['balance'=>[0,1e9],'monthly_payment'=>[0,1e6],'remaining_months'=>[1,600],
            'rate'=>[0,25],'extra_monthly'=>[0,1e6],'lump_sum'=>[0,1e9],
            'rate_change_month'=>[0,600],'future_rate'=>[0,25]] as $key=>$range) {
            $value=$input[$key] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value<$range[0] || $value>$range[1]) {
                throw new InvalidArgumentException('Invalid '.str_replace('_',' ',$key).'.');
            }
            if (in_array($key,['remaining_months','rate_change_month'],true) && floor($value)!=$value) {
                throw new InvalidArgumentException('Terms and rate-change months must be whole months.');
            }
            $out[$key]=in_array($key,['remaining_months','rate_change_month'],true)?(int)$value:round((float)$value,2);
        }
        if ($out['lump_sum']>$out['balance']) throw new InvalidArgumentException('A one-off overpayment cannot exceed the outstanding balance.');
        if ($out['rate_change_month']>$out['remaining_months']) throw new InvalidArgumentException('The rate change must fall within the remaining mortgage term, or be zero to disable it.');
        if (!is_bool($input['recalculate_on_change'] ?? null)) throw new InvalidArgumentException('Choose how payments change when the rate changes.');
        $out['recalculate_on_change']=$input['recalculate_on_change'];
        $date=$input['balance_date'] ?? null;
        $parsed=is_string($date)?DateTimeImmutable::createFromFormat('!Y-m-d',$date):false;
        if (!$parsed || $parsed->format('Y-m-d')!==$date || $date>date('Y-m-d') || $date<'1900-01-01') {
            throw new InvalidArgumentException('Enter a valid balance date that is not in the future.');
        }
        $out['balance_date']=$date;
        require_once __DIR__ . '/../services/MortgageRenewal.php';
        return array_merge($out,MortgageRenewal::validateFields($input));
    }

    public static function load(): array {
        $row=Database::getConnection()->query('SELECT data,revision FROM mortgage_plans WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        return $row?['plan'=>array_replace(self::defaults(),json_decode($row['data'],true,512,JSON_THROW_ON_ERROR)),'revision'=>(int)$row['revision']]
            :['plan'=>self::defaults(),'revision'=>0];
    }

    public static function save(array $plan, int $revision): array {
        $plan=self::validate($plan);$db=Database::getConnection();$json=json_encode($plan,JSON_THROW_ON_ERROR);
        if ($revision===0) {
            $stmt=$db->prepare('INSERT INTO mortgage_plans (id,data,revision) VALUES (1,:data,1) ON CONFLICT(id) DO NOTHING');
            $stmt->execute(['data'=>$json]);
        } else {
            $stmt=$db->prepare('UPDATE mortgage_plans SET data=:data,revision=revision+1 WHERE id=1 AND revision=:revision');
            $stmt->execute(['data'=>$json,'revision'=>$revision]);
        }
        if ($stmt->rowCount()!==1) throw new RuntimeException('This mortgage changed in another window. Reload before saving to avoid overwriting it.',409);
        return ['plan'=>$plan,'revision'=>$revision+1];
    }

    public static function payment(float $balance, float $annualRate, int $months): float {
        if ($balance<=0) return 0;
        $rate=$annualRate/1200;
        return round($rate>0?$balance*$rate/(1-pow(1+$rate,-$months)):$balance/$months,2);
    }

    public static function forecast(array $plan): array {
        require_once __DIR__ . '/../services/MortgageRenewal.php';
        $normal=self::simulate($plan,false);$overpay=self::simulate($plan,true);
        $bothPaid=$normal['payoff_months']!==null && $overpay['payoff_months']!==null;
        return ['normal'=>$normal,'overpay'=>$overpay,'renewal'=>MortgageRenewal::forecast($plan),
            'required_payment'=>self::payment($plan['balance'],$plan['rate'],$plan['remaining_months']),
            'contract_end'=>self::monthDate($plan['balance_date'],$plan['remaining_months']),
            'months_saved'=>$bothPaid?$normal['payoff_months']-$overpay['payoff_months']:null,
            'interest_saved'=>$bothPaid?round($normal['total_interest']-$overpay['total_interest'],2):null];
    }

    public static function monthDate(string $start, int $months): string {
        $date=new DateTimeImmutable($start);
        $month=$date->modify('first day of this month')->modify('+'.$months.' months');
        return $month->setDate((int)$month->format('Y'),(int)$month->format('m'),min((int)$date->format('d'),(int)$month->format('t')))->format('Y-m-d');
    }

    private static function simulate(array $p, bool $overpay): array {
        $balance=round($p['balance']-($overpay?$p['lump_sum']:0),2);
        $payment=$p['monthly_payment']>0?$p['monthly_payment']:self::payment($p['balance'],$p['rate'],$p['remaining_months']);
        $automatic=$p['monthly_payment']==0;
        $extra=$overpay?$p['extra_monthly']:0;
        $rate=$p['rate'];$interestTotal=0.0;$paidTotal=$overpay?$p['lump_sum']:0.0;
        $payoff=$balance<=0?0:null;$balanceAtTerm=$balance<=0?0.0:null;$interestAtTerm=$balance<=0?0.0:null;$paidAtTerm=$balance<=0?$paidTotal:null;
        $changedPayment=null;$growing=false;$firstInterest=null;$firstPrincipal=null;$firstPaid=0.0;
        $rows=[['month'=>0,'date'=>$p['balance_date'],'balance'=>$balance,'interest'=>0.0,'paid'=>$paidTotal,'payment'=>0.0]];
        for ($m=1;$m<=1200 && $balance>0;$m++) {
            if ($p['rate_change_month']>0 && $m===$p['rate_change_month']) {
                $rate=$p['future_rate'];
                if ($p['recalculate_on_change']) {
                    $payment=self::payment($balance,$rate,max(1,$p['remaining_months']-$m+1));
                    $automatic=true;
                }
                $changedPayment=$payment;
            }
            $interest=round($balance*$rate/1200,2);
            $due=$payment+$extra;
            // Calculated contractual payments have a final adjusted instalment for penny rounding.
            if ($automatic && $m===$p['remaining_months']) $due=max($due,$balance+$interest);
            $paid=round(min($balance+$interest,$due),2);
            $principal=round($paid-$interest,2);
            if ($m===1) {$firstInterest=$interest;$firstPrincipal=$principal;$firstPaid=$paid;}
            if ($principal<0) $growing=true;
            $balance=round(max(0,$balance-$principal),2);$interestTotal+=$interest;$paidTotal+=$paid;
            if ($m===$p['remaining_months']) {$balanceAtTerm=$balance;$interestAtTerm=$interestTotal;$paidAtTerm=$paidTotal;}
            if ($balance<=0) $payoff=$m;
            if ($m%12===0 || $m===$p['remaining_months'] || $balance<=0 || $m===1200 || $balance>1e12) {
                $rows[]=['month'=>$m,'date'=>self::monthDate($p['balance_date'],$m),'balance'=>$balance,
                    'interest'=>round($interestTotal,2),'paid'=>round($paidTotal,2),'payment'=>$payment+$extra];
            }
            if ($balance>1e12) break;
        }
        if ($payoff!==null && $payoff<=$p['remaining_months']) {$balanceAtTerm=0.0;$interestAtTerm=$interestTotal;$paidAtTerm=$paidTotal;}
        return ['payoff_months'=>$payoff,'payoff_date'=>$payoff!==null?self::monthDate($p['balance_date'],$payoff):null,
            'total_interest'=>$payoff!==null?round($interestTotal,2):null,'total_paid'=>$payoff!==null?round($paidTotal,2):null,
            'balance_at_term'=>$balanceAtTerm,'interest_to_term'=>$interestAtTerm!==null?round($interestAtTerm,2):null,'paid_to_term'=>$paidAtTerm!==null?round($paidAtTerm,2):null,
            'initial_payment'=>$firstPaid,'payment_after_change'=>$changedPayment!==null?round($changedPayment+$extra,2):null,
            'first_interest'=>$firstInterest??0.0,'first_principal'=>$firstPrincipal??0.0,'growing_balance'=>$growing,'rows'=>$rows];
    }
}
