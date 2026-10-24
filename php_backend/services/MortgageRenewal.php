<?php
require_once __DIR__ . '/../models/MortgagePlanner.php';

// Separate renewal comparisons: never changes the saved plan or ledger.
class MortgageRenewal {
    public static function validateFields(array $input): array {
        $out=['deal_start'=>'','deal_end'=>'','property_value'=>0.0,'review_months'=>6,'comparison_deals'=>[]];
        foreach (['deal_start','deal_end'] as $key) {
            $value=$input[$key]??'';
            $date=is_string($value)&&$value!==''?DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
            if ($value!=='' && (!$date || $date->format('Y-m-d')!==$value || $value<'1900-01-01' || $value>'2200-12-31')) throw new InvalidArgumentException('Enter a valid fixed-deal date.');
            $out[$key]=$value;
        }
        if ($out['deal_start'] && (!$out['deal_end'] || $out['deal_start']>=$out['deal_end'])) throw new InvalidArgumentException('The fixed deal must end after its start date.');
        foreach (['property_value'=>[0,1e9],'review_months'=>[0,24]] as $key=>$range) {
            $value=$input[$key]??$out[$key];
            if ((!is_int($value)&&!is_float($value)) || !is_finite((float)$value) || $value<$range[0] || $value>$range[1] || ($key==='review_months'&&floor($value)!=$value)) throw new InvalidArgumentException('Invalid renewal '.str_replace('_',' ',$key).'.');
            $out[$key]=$key==='review_months'?(int)$value:round($value,2);
        }
        $deals=$input['comparison_deals']??[];
        if (!is_array($deals) || !array_is_list($deals) || count($deals)>12) throw new InvalidArgumentException('Save up to twelve comparison deals.');
        foreach ($deals as $deal) $out['comparison_deals'][]=self::validateDeal($deal);
        return $out;
    }

    public static function validateDeal(mixed $deal): array {
        if (!is_array($deal) || !is_string($deal['name']??null) || trim($deal['name'])==='' || strlen($deal['name'])>160) throw new InvalidArgumentException('Give each comparison deal a name of at most 160 characters.');
        $out=['name'=>trim($deal['name'])];
        foreach (['rate'=>[0,25],'fee'=>[0,100000],'cashback'=>[0,100000],'fixed_months'=>[1,120]] as $key=>$range) {
            $v=$deal[$key]??null;
            if ((!is_int($v)&&!is_float($v)) || !is_finite((float)$v) || $v<$range[0] || $v>$range[1] || ($key==='fixed_months'&&floor($v)!=$v)) throw new InvalidArgumentException('Invalid comparison '.str_replace('_',' ',$key).'.');
            $out[$key]=$key==='fixed_months'?(int)$v:round($v,2);
        }
        if (!is_bool($deal['add_fee']??null)) throw new InvalidArgumentException('Choose whether to add the product fee to borrowing.');
        $out['add_fee']=$deal['add_fee'];
        return $out;
    }

    public static function forecast(array $plan, ?string $today=null): array {
        $today=$today??date('Y-m-d');$end=$plan['deal_end']??'';
        if (!$end) return ['configured'=>false];
        // Use actual payment dates anchored to the balance date. A payment on
        // the deal-end date remains on the old rate; the next starts the new deal.
        $target=max($end,$plan['balance_date']);$months=0;
        while ($months<$plan['remaining_months'] && MortgagePlanner::monthDate($plan['balance_date'],$months+1)<=$target) $months++;
        $current=$plan['monthly_payment']>0?$plan['monthly_payment']:MortgagePlanner::payment($plan['balance'],$plan['rate'],$plan['remaining_months']);
        $balances=[];
        foreach ([false,true] as $overpay) {
            $balance=round($plan['balance']-($overpay?$plan['lump_sum']:0),2);
            for ($m=1;$m<=$months && $balance>0;$m++) {
                $interest=round($balance*$plan['rate']/1200,2);
                $due=$current+($overpay?$plan['extra_monthly']:0);
                if($plan['monthly_payment']==0 && $m===$plan['remaining_months'])$due=max($due,$balance+$interest);
                $balance=round(max(0,$balance+$interest-$due),2);
            }
            $balances[]=$balance;
        }
        $remaining=$plan['remaining_months']-$months;
        $days=(int)(new DateTimeImmutable($today))->diff(new DateTimeImmutable($end))->format('%r%a');
        $review=(new DateTimeImmutable($end))->modify('first day of this month')->modify('-'.($plan['review_months']??6).' months');
        $review=$review->setDate((int)$review->format('Y'),(int)$review->format('m'),min((int)substr($end,8,2),(int)$review->format('t')))->format('Y-m-d');
        $value=$plan['property_value']??0;
        $result=['configured'=>true,'deal_end'=>$end,'days_remaining'=>$days,'review_date'=>$review,'review_due'=>$today>=$review,
            'projection_date'=>$target,'elapsed_payments'=>$months,'remaining_months'=>$remaining,'balance'=>$balances[0],'balance_with_overpayments'=>$balances[1],
            'ltv'=>$value>0?round($balances[0]/$value*100,4):null,'ltv_with_overpayments'=>$value>0?round($balances[1]/$value*100,4):null,
            'current_payment'=>$current,'comparisons'=>[]];
        if ($remaining>0) foreach ($plan['comparison_deals']??[] as $deal) {
            $result['comparisons'][]=array_merge($deal,['normal'=>self::compare($balances[0],$remaining,$deal,$current),
                'overpay'=>self::compare($balances[1],$remaining,$deal,$current)]);
        }
        return $result;
    }

    public static function compare(float $balance, int $remaining, array $deal, float $current): array {
        $fee=$balance>0?$deal['fee']:0;$cashback=$balance>0?$deal['cashback']:0;
        $opening=$balance+($deal['add_fee']?$fee:0);
        $payment=MortgagePlanner::payment($opening,$deal['rate'],$remaining);
        $interest=0.0;$paid=0.0;$closing=$opening;$months=min($remaining,$deal['fixed_months']);
        for ($m=1;$m<=$months && $closing>0;$m++) {
            $charge=round($closing*$deal['rate']/1200,2);$interest+=$charge;
            $instalment=min($closing+$charge,$payment);
            if ($m===$remaining) $instalment=$closing+$charge;
            $paid+=$instalment;$closing=round(max(0,$closing+$charge-$instalment),2);
        }
        return ['payment'=>$payment,'monthly_change'=>round($payment-$current,2),'months_compared'=>$months,
            'cash_paid'=>round($paid+($deal['add_fee']?0:$fee)-$cashback,2),'interest'=>round($interest,2),
            'balance_after_fix'=>$closing,'financing_cost'=>round($paid+($deal['add_fee']?0:$fee)+$closing-$balance-$cashback,2)];
    }
}
