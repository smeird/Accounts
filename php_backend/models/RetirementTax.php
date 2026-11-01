<?php
// Annualised non-savings income estimate, 2026/27 bands verified 1 November 2026.
// https://www.gov.uk/income-tax-rates and /scottish-income-tax
class RetirementTax {
    public static function annual(float $income,string $region='rest_uk'): float {
        $allowance=max(0,12570-max(0,$income-100000)/2);
        $taxable=max(0,$income-$allowance);$tax=0;$previous=0;
        $bands=$region==='scotland'?[[3967,.19],[16956,.20],[31092,.21],[62430,.42],[125140,.45],[INF,.48]]:[[37700,.20],[125140,.40],[INF,.45]];
        foreach($bands as [$limit,$rate]){$tax+=max(0,min($taxable,$limit)-$previous)*$rate;$previous=$limit;}
        return $tax;
    }
}
