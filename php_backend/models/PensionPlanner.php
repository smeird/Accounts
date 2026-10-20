<?php
require_once __DIR__ . '/../Database.php';

// One bounded, versioned planning document for this installation, separate from the ledger.
class PensionPlanner {
    public static function defaults(?string $today = null): array {
        return ['age' => 52, 'age_as_of' => $today ?? date('Y-m-d'), 'retirement_age' => 67,
            'end_age' => 95, 'access_age' => 57, 'growth' => 5, 'retirement_growth' => 3,
            'spending_profile' => 'level', 'slow_go_percent' => 80, 'no_go_percent' => 60,
            'inflation' => 2.5, 'state_annual' => 0, 'state_age' => 67, 'target_monthly' => 0, 'pots' => []];
    }

    public static function validate(array $input, bool $checkCurrentAge = true): array {
        $out = [];
        foreach (['age'=>[18,90], 'retirement_age'=>[18,90], 'end_age'=>[60,110], 'access_age'=>[55,75],
            'growth'=>[-10,15], 'retirement_growth'=>[-10,15], 'inflation'=>[0,10],
            'state_annual'=>[0,100000], 'state_age'=>[60,80], 'target_monthly'=>[0,1000000]] as $key=>$range) {
            $out[$key] = self::number($input[$key] ?? null, $range[0], $range[1], $key);
        }
        $out['spending_profile'] = $input['spending_profile'] ?? 'level';
        if (!in_array($out['spending_profile'], ['level','three_phase'], true)) throw new InvalidArgumentException('Choose a valid spending profile.');
        $out['slow_go_percent'] = self::number($input['slow_go_percent'] ?? 80, 1, 100, 'Slow-Go percentage');
        $out['no_go_percent'] = self::number($input['no_go_percent'] ?? 60, 1, 100, 'No-Go percentage');
        if ($out['no_go_percent'] > $out['slow_go_percent']) throw new InvalidArgumentException('No-Go spending cannot exceed Slow-Go spending.');
        $out['age_as_of'] = self::date($input['age_as_of'] ?? null);
        $age = self::currentAge($out);
        if (($checkCurrentAge && $age > 90) || $out['retirement_age'] < max($checkCurrentAge ? $age : $out['age'], $out['access_age']) || $out['end_age'] <= $out['retirement_age']) {
            throw new InvalidArgumentException('Retirement must be at or after your current/access age, and before the forecast end age.');
        }
        if (!isset($input['pots']) || !is_array($input['pots']) || !array_is_list($input['pots']) || count($input['pots']) > 100) {
            throw new InvalidArgumentException('Enter at most 100 pension pots.');
        }
        $out['pots'] = [];
        foreach ($input['pots'] as $pot) {
            if (!is_array($pot) || !is_string($pot['provider'] ?? null) || trim($pot['provider']) === '' || strlen($pot['provider']) > 120) {
                throw new InvalidArgumentException('Each pot needs a provider/name of at most 120 characters.');
            }
            $out['pots'][] = ['provider'=>trim($pot['provider']), 'value'=>self::number($pot['value'] ?? null,0,1e9,'pot value'),
                'monthly'=>self::number($pot['monthly'] ?? null,0,1e6,'monthly contribution'),
                'fee'=>self::number($pot['fee'] ?? null,0,5,'annual fee'), 'valued_on'=>self::date($pot['valued_on'] ?? null)];
        }
        return $out;
    }

    private static function number($value, float $min, float $max, string $name): float {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException("Invalid $name (allowed range {$min}–{$max}).");
        }
        return (float)$value;
    }
    private static function date($value): string {
        if (!is_string($value)) throw new InvalidArgumentException('A valuation/age date is required.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value || $value > date('Y-m-d') || $value < '1900-01-01') {
            throw new InvalidArgumentException('Dates must be valid and cannot be in the future.');
        }
        return $value;
    }
    public static function currentAge(array $plan, ?DateTimeImmutable $now = null): float {
        $now = $now ?? new DateTimeImmutable('today');
        return $plan['age'] + (new DateTimeImmutable($plan['age_as_of']))->diff($now)->days / 365.2425;
    }

    public static function load(): array {
        $raw = Database::getConnection()->query('SELECT data, revision FROM pension_plans WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        return $raw ? ['plan'=>json_decode($raw['data'],true,512,JSON_THROW_ON_ERROR), 'revision'=>(int)$raw['revision']]
            : ['plan'=>self::defaults(), 'revision'=>0];
    }
    public static function save(array $plan, int $revision): array {
        $plan = self::validate($plan);
        $db = Database::getConnection();
        $data = json_encode($plan, JSON_THROW_ON_ERROR);
        if ($revision === 0) {
            $stmt = $db->prepare('INSERT INTO pension_plans (id,data,revision) VALUES (1,:data,1) ON CONFLICT(id) DO NOTHING');
            $stmt->execute(['data'=>$data]);
        } else {
            $stmt = $db->prepare('UPDATE pension_plans SET data=:data, revision=revision+1 WHERE id=1 AND revision=:revision');
            $stmt->execute(['data'=>$data,'revision'=>$revision]);
        }
        if ($stmt->rowCount() !== 1) throw new RuntimeException('This plan changed in another window. Reload before saving to avoid overwriting it.',409);
        return ['plan'=>$plan,'revision'=>$revision+1];
    }

    public static function forecast(array $plan, ?DateTimeImmutable $now = null): array {
        $plan = array_replace(self::defaults(), $plan);
        $age = self::currentAge($plan,$now);
        if ($plan['retirement_age'] < max($age,$plan['access_age']) || $plan['end_age'] <= $plan['retirement_age']) {
            throw new InvalidArgumentException('Update the retirement and end ages before forecasting.');
        }
        $scenarios = [];
        foreach (['lower'=>-2,'central'=>0,'higher'=>2] as $name=>$offset) {
            $scenarios[$name] = self::scenario($plan,$age,$offset);
        }
        $earliest = null;
        if ($plan['target_monthly'] > 0 && count($plan['pots']) > 0) {
            for ($a = (int)ceil(max($age,$plan['access_age'])); $a <= min(90,$plan['end_age']-1); $a++) {
                $candidate = $plan;
                $candidate['retirement_age'] = $a;
                if (self::scenario($candidate,$age,0,false)['monthly_today'] >= $plan['target_monthly']) { $earliest=$a; break; }
            }
        }
        return ['age_now'=>$age,'recorded_total'=>array_sum(array_column($plan['pots'],'value')),
            'contributions'=>array_sum(array_column($plan['pots'],'monthly')), 'scenarios'=>$scenarios,'earliest_target_age'=>$earliest];
    }

    private static function scenario(array $p, float $age, float $offset, bool $withRows = true): array {
        $months = (int)ceil(max(0,($p['retirement_age']-$age)*12));
        $duration = (int)ceil(($p['end_age']-$p['retirement_age'])*12);
        $inflation = pow(1+$p['inflation']/100,1/12);
        $pots = []; $rates=[];
        foreach ($p['pots'] as $pot) {
            $balance=$pot['value'];
            $rate=pow((1+($p['growth']+$offset)/100)*(1-$pot['fee']/100),1/12);
            for ($m=1;$m<=$months;$m++) $balance=$balance*$rate+$pot['monthly'];
            $pots[]=$balance/pow($inflation,$months);
            $rates[]=pow(((1+($p['retirement_growth']+$offset)/100)*(1-$pot['fee']/100))/(1+$p['inflation']/100),1/12);
        }
        $total=array_sum($pots);
        // Retire into the same portfolio; value-weighted monthly net real return.
        $rate=1.0;
        if ($total>0) { $rate=0; foreach($pots as $i=>$balance) $rate += $balance/$total*$rates[$i]; }
        $state=$p['state_annual']/12;
        $stateMonth=max(0,(int)ceil(($p['state_age']-$p['retirement_age'])*12));
        $phased = ($p['spending_profile'] ?? 'level') === 'three_phase';
        $weights = $phased ? [1, $p['slow_go_percent']/100, $p['no_go_percent']/100] : [1,1,1];
        // Equal age spans; a monthly withdrawal uses the phase at its start.
        $phaseAt = fn(int $m): int => min(2, (int)floor($m * 3 / (($p['end_age']-$p['retirement_age'])*12)));
        $canFund=function(float $income) use($total,$rate,$duration,$state,$stateMonth,$weights,$phaseAt): bool {
            $balance=$total;
            for($m=0;$m<$duration;$m++) {
                // Withdraw at the start of each month; never borrow against a later State Pension.
                $balance-=max(0,$income*$weights[$phaseAt($m)]-($m >= $stateMonth ? $state : 0));
                if($balance < -0.000001) return false;
                $balance*=$rate;
            }
            return true;
        };
        $lo=0; $hi=$total+$state+1;
        for($i=0;$i<55;$i++) { $mid=($lo+$hi)/2; if($canFund($mid)) $lo=$mid; else $hi=$mid; }
        $income=$lo; $factor=pow($inflation,$months); $rows=[]; $balance=$total;
        if ($withRows) {
            $rows[]=['age'=>$p['retirement_age'],'pot_today'=>$total,'pot_future'=>$total*$factor,'monthly_today'=>max($income,$stateMonth===0?$state:0),'monthly_future'=>max($income,$stateMonth===0?$state:0)*$factor];
            for($m=0;$m<$duration;$m++) {
                $balance=max(0,$balance-max(0,$income*$weights[$phaseAt($m)]-($m >= $stateMonth?$state:0)))*$rate;
                if(($m+1)%12===0 || $m+1===$duration || ($phased && $phaseAt($m)!==$phaseAt($m+1))) {
                    $factorAt=pow($inflation,$months+$m+1);
                    $actual=max($income*$weights[$phaseAt(min($m+1,$duration-1))],($m+1 >= $stateMonth ? $state:0));
                    $rows[]=['age'=>round($p['retirement_age']+($m+1)/12,2),'pot_today'=>$balance,'pot_future'=>$balance*$factorAt,'monthly_today'=>$actual,'monthly_future'=>$actual*$factorAt];
                }
            }
        }
        $phases = [];
        if ($phased) foreach (['Go-Go','Slow-Go','No-Go'] as $i=>$name) {
            $start = $p['retirement_age'] + ($p['end_age']-$p['retirement_age'])*$i/3;
            $end = $p['retirement_age'] + ($p['end_age']-$p['retirement_age'])*($i+1)/3;
            $availableState = $start >= $p['state_age'] ? $state : 0;
            $phases[] = ['name'=>$name,'start_age'=>$start,'end_age'=>$end,'percent'=>$weights[$i]*100,
                'monthly_target_today'=>$income*$weights[$i], 'monthly_today'=>max($income*$weights[$i],$availableState)];
        }
        return ['phases'=>$phases,'pot_today'=>$total,'pot_future'=>$total*$factor,'monthly_today'=>$income,'monthly_future'=>$income*$factor,
            'daily_today'=>$income*12/365.2425,'daily_future'=>$income*$factor*12/365.2425,
            'monthly_after_state_today'=>max($income,$state),'rows'=>$rows];
    }
}
