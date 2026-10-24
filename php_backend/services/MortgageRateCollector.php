<?php
require_once __DIR__ . '/../Database.php';

// Public, read-only lender sources. No credentials, customer details or paid feed.
class MortgageRateCollector {
    public const SOURCES=[
        'natwest'=>['name'=>'NatWest','url'=>'https://www.natwest.com/mortgages/mortgage-rates.html','fetch'=>'https://www.natwest.com/mortgages/mortgage-rates.html','scope'=>'Public residential range; confirm remortgage eligibility'],
        'hsbc'=>['name'=>'HSBC','url'=>'https://www.hsbc.co.uk/mortgages/move-your-mortgage/rates/','fetch'=>'https://www.hsbc.co.uk/mortgages/move-your-mortgage/rates/','scope'=>'Remortgage to HSBC'],
        'barclays'=>['name'=>'Barclays','url'=>'https://www.barclays.co.uk/mortgages/remortgage/rates/','fetch'=>'https://www.barclays.co.uk/content/dam/json-files/mortgages/allproducts.json','scope'=>'Published residential remortgage products'],
    ];
    public function __construct(private PDO $db, private mixed $fetcher=null) {}

    public function status(): array {
        $sources=[];
        foreach (self::SOURCES as $key=>$source) {
            $state=$this->read($key);$last=$state['last_success']??null;
            $sources[]=array_merge(['id'=>$key,'name'=>$source['name'],'url'=>$source['url'],'scope'=>$source['scope'],
                'last_attempt'=>null,'last_success'=>null,'error'=>null,'products'=>[]],$state,
                ['stale'=>!$last || time()-strtotime($last)>172800,'due'=>!isset($state['last_attempt']) || time()-strtotime($state['last_attempt'])>86400]);
        }
        return ['sources'=>$sources];
    }
    private function read(string $key): array {
        $s=$this->db->prepare('SELECT value FROM settings WHERE name=:name');$s->execute(['name'=>'mortgage_rates_'.$key]);
        $value=$s->fetchColumn();return $value?json_decode($value,true,512,JSON_THROW_ON_ERROR):[];
    }
    public function refresh(string $key): array {
        if (!isset(self::SOURCES[$key])) throw new InvalidArgumentException('Choose a supported lender.');
        $pgsql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql';$lock=false;
        if ($pgsql) {
            $s=$this->db->prepare('SELECT pg_try_advisory_lock(hashtext(:name))');$s->execute(['name'=>'mortgage_rates_'.$key]);
            $lock=in_array($s->fetchColumn(),[true,1,'1','t'],true);
            if (!$lock) throw new RuntimeException('This lender is already being checked. Try again shortly.',409);
        }
        try {
            $state=$this->read($key);
            if (isset($state['last_attempt']) && time()-strtotime($state['last_attempt'])<900) return $this->status();
            $state['last_attempt']=gmdate('c');
            try {
                $body=$this->fetcher?($this->fetcher)(self::SOURCES[$key]['fetch']):self::download(self::SOURCES[$key]['fetch']);
                $products=self::parse($key,$body);
                if (!$products) throw new RuntimeException('No recognised fixed-rate products found. The lender page may have changed; use manual comparisons.');
                $state['products']=$products;$state['last_success']=gmdate('c');$state['error']=null;
                $state['content_hash']=hash('sha256',$body);
                $state['published_at']=$key==='barclays'?(json_decode($body,true)['createdOn']??null):null;
            } catch (Throwable $e) {
                $state['error']=$e instanceof RuntimeException?$e->getMessage():'The lender response could not be read. Use manual comparisons.';
            }
            $s=$this->db->prepare('INSERT INTO settings (name,value) VALUES (:name,:value) ON CONFLICT(name) DO UPDATE SET value=excluded.value');
            $s->execute(['name'=>'mortgage_rates_'.$key,'value'=>json_encode($state,JSON_THROW_ON_ERROR)]);
            return $this->status();
        } finally {
            if ($lock) {$s=$this->db->prepare('SELECT pg_advisory_unlock(hashtext(:name))');$s->execute(['name'=>'mortgage_rates_'.$key]);}
        }
    }
    public static function download(string $url): string {
        if (!in_array($url,array_column(self::SOURCES,'fetch'),true)) throw new InvalidArgumentException('Unsupported lender URL.');
        $body='';$tooLarge=false;$curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
            CURLOPT_USERAGENT=>'AccountsPersonalMortgagePlanner/1.0',CURLOPT_ENCODING=>'',
            CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$body,&$tooLarge){if(strlen($body)+strlen($chunk)>4000000){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);unset($curl);
        if ($tooLarge) throw new RuntimeException('Lender response exceeded the safe size limit.');
        if ($ok===false || $status!==200) throw new RuntimeException('Lender could not be checked'.($status?' (HTTP '.$status.')':'').'. Last successful rates have been retained.');
        return $body;
    }
    private static function text(DOMNode $node): string {return trim(preg_replace('/\s+/u',' ',str_replace("\xc2\xa0",' ',$node->textContent)));}
    private static function number(string $value, bool $percent=false): ?float {
        $pattern=$percent?'/([0-9]+(?:\.[0-9]+)?)\s*%/u':'/£\s*([0-9,]+(?:\.[0-9]+)?)/u';
        return preg_match($pattern,$value,$m)?(float)str_replace(',','',$m[1]):null;
    }
    public static function parse(string $key,string $body): array {
        if (!isset(self::SOURCES[$key]) || strlen($body)>4000000) throw new InvalidArgumentException('Invalid lender response.');
        $rows=[];
        if ($key==='barclays') {
            $data=json_decode($body,true,32,JSON_THROW_ON_ERROR);
            foreach ($data['mortgages']??[] as $p) {
                if (($p['mortgageType']??'')!=='FIXED' || ($p['eligibility']['remortgage']??false)!==true || ($p['eligibility']['buyToLet']??true)!==false || ($p['isApplicationFeePercentage']??true)!==false) continue;
                if (!preg_match('/\b(\d+)\s*Year\b/i',$p['mortgageName']??'',$m)) continue;
                if (($p['initialRateValidUntil']??'')<date('Y-m-d')) continue;
                $restricted=[];foreach($p['productTypes']??[] as $type=>$enabled)if($enabled)$restricted[]=$type;
                $rows[]=['name'=>$p['mortgageName'],'rate'=>$p['initialRate']??null,'fee'=>$p['applicationFee']??null,
                    'max_ltv'=>$p['maxLtv']??null,'min_ltv'=>$p['minLtv']??null,'min_loan'=>$p['minLoanAmount']??null,'max_loan'=>$p['maxLoanAmount']??null,
                    'fixed_months'=>(int)$m[1]*12,'cashback'=>is_numeric($p['cashBackAmount']??null)?(float)$p['cashBackAmount']:0,
                    'incentives'=>($p['freeLegal']??false)?'Standard legal fees':'','restrictions'=>implode(', ',$restricted),'fixed_until'=>substr($p['initialRateValidUntil'],0,10)];
            }
        } else {
            $dom=new DOMDocument();$previous=libxml_use_internal_errors(true);
            try {$dom->loadHTML('<?xml encoding="UTF-8">'.$body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
            $x=new DOMXPath($dom);
            if ($key==='hsbc') {
                foreach ($x->query('//table[contains(concat(" ",normalize-space(@class)," ")," desktop ")]') as $table) {
                    $caption=$table->getElementsByTagName('caption')->item(0);$ltv=$caption?self::number(self::text($caption),true):null;
                    $headers=[];
                    foreach($x->query('.//tr[1]/*',$table) as $th)$headers[]=strtolower(self::text($th));
                    $rateCol=array_search('initial interest rate',$headers,true);$feeCol=array_search('booking fee',$headers,true);
                    $maxCol=null;$benefitCol=null;
                    foreach($headers as $i=>$h){if(str_starts_with($h,'maximum loan amount'))$maxCol=$i;if(in_array($h,['cashback','incentives'],true))$benefitCol=$i;}
                    if ($rateCol===false || $feeCol===false || $ltv===null) continue;
                    foreach($x->query('.//tr[position()>1]',$table) as $row){
                        $cells=[];foreach($x->query('./th|./td',$row) as $cell)$cells[]=self::text($cell);
                        if(!preg_match('/^(\d+)\s+Year\s+Fixed\b/i',$cells[0]??'',$m))continue;
                        if(!isset($cells[$rateCol],$cells[$feeCol]))continue;
                        $benefits=$benefitCol!==null?($cells[$benefitCol]??''):'';
                        $rows[]=['name'=>$cells[0],'rate'=>self::number($cells[$rateCol],true),'fee'=>self::number($cells[$feeCol]),'fixed_months'=>(int)$m[1]*12,
                            'max_ltv'=>$ltv,'max_loan'=>$maxCol!==null?self::number($cells[$maxCol]??''):null,'min_ltv'=>0,'min_loan'=>null,
                            'cashback'=>preg_match('/cashback/i',$benefits)?(self::number($benefits)??0):0,'incentives'=>$benefits,
                            'restrictions'=>stripos($cells[0],'Premier')!==false?'Premier customer conditions':'Confirm lender criteria','fixed_until'=>null];
                    }
                }
            } else {
                foreach($x->query('//div[contains(concat(" ",normalize-space(@class)," ")," mpc ")]') as $card){
                    // Hidden variants/tooltips must not turn purchase/green offers into standard deals.
                    foreach(iterator_to_array($x->query('.//*[contains(concat(" ",normalize-space(@class)," ")," d-none ")]',$card)) as $hidden)$hidden->parentNode?->removeChild($hidden);
                    $find=static function(string $class)use($x,$card):string{$n=$x->query('.//*[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]',$card)->item(0);return $n?self::text($n):'';};
                    $name=$find('mpc__main__content__interestrate__body__title__text');
                    if(!preg_match('/(\d+)\s+year\s+fixed\s+rate/i',$name,$m))continue;
                    $text=self::text($card);
                    $minimum=preg_match('/Min\.\s*£\s*([0-9,]+)/i',$text,$min)?(float)str_replace(',','',$min[1]):null;
                    $rows[]=['name'=>$name,'rate'=>self::number($find('mpc__main__content__interestrate__body__rate__value'),true),
                        'fee'=>self::number($find('mpc__main__content__hero__details__productfee__value')),'max_ltv'=>self::number($find('mpc__main__content__hero__details__ltv__value'),true),
                        'fixed_months'=>(int)$m[1]*12,'min_ltv'=>0,'min_loan'=>$minimum,'max_loan'=>null,'cashback'=>0,'incentives'=>'',
                        'restrictions'=>'Purpose and incentives unverified; check lender'.(stripos($text,'Green mortgage')!==false?'; green conditions':'').(stripos($text,'First time buyer exclusive')!==false?'; first-time buyer':'').(stripos($text,'Online only')!==false?'; online only':''),'fixed_until'=>null];
                }
            }
        }
        $products=[];
        foreach($rows as $row){
            foreach(['rate','fee','max_ltv','fixed_months'] as $field){if(!isset($row[$field]) || !is_numeric($row[$field]) || !is_finite((float)$row[$field]))continue 2;$row[$field]=(float)$row[$field];}
            if($row['rate']<=0||$row['rate']>25||$row['fee']<0||$row['fee']>100000||$row['max_ltv']<=0||$row['max_ltv']>100||$row['fixed_months']<12||$row['fixed_months']>120)continue;
            $row['name']=mb_substr($row['name'],0,160);$row['id']=substr(hash('sha256',json_encode($row)),0,20);$products[$row['id']]=$row;
            if(count($products)>500)throw new RuntimeException('Unexpected number of lender products; previous rates retained.');
        }
        return array_values($products);
    }
}
