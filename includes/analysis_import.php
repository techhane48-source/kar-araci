<?php
declare(strict_types=1);
require_once __DIR__.'/profit.php';
require_once __DIR__.'/profit_targets.php';
require_once __DIR__.'/xlsx.php';
require_once __DIR__.'/tariff_apply.php';

const ANALYSIS_MAX_ROWS=20000;
const ANALYSIS_MAX_COLS=128;
const ANALYSIS_MAX_TIERS=12;

function analysis_header(string $s): string
{
    $s=strtr(trim($s),['Ç'=>'c','ç'=>'c','Ğ'=>'g','ğ'=>'g','İ'=>'i','I'=>'i','ı'=>'i','Ö'=>'o','ö'=>'o','Ş'=>'s','ş'=>'s','Ü'=>'u','ü'=>'u']);
    return preg_replace('/[^a-z0-9]/','',strtolower($s));
}

/** No guessed prices: an explicit bounded interval is only an illustrative upper endpoint. */
function analysis_price(string $raw): ?array
{
    $v=parse_money_input($raw);
    if($v!==null&&$v>0&&$v<=100000000)return ['price'=>$v,'range'=>false];
    if(preg_match('/^\s*(.+?)\s*(?:-|–|—)\s*(.+?)\s*$/u',$raw,$m)){
        $lo=parse_money_input($m[1]);$hi=parse_money_input($m[2]);
        if($lo!==null&&$hi!==null&&$lo>=0&&$hi>0&&$lo<=$hi&&$hi<=100000000)return ['price'=>$hi,'range'=>true,'min'=>$lo];
    }
    return null;
}
function analysis_rate(string $raw): ?float
{
    $v=parse_money_input(str_replace('%','',trim($raw)));
    return $v!==null&&$v>=0&&$v<=100?$v:null;
}

/** Trendyol's two-period tariff: exact recognized headers, distinct rate sets.
 * Both periods share the price thresholds. Open ends are illustrative boundary prices only. */
function analysis_period_tariff_schema(array $headers): ?array
{
    $h=array_map(static fn($v)=>analysis_header((string)$v),$headers);
    $dates=[];foreach($h as $i=>$v)if(in_array($v,['tariharaligi3gun','tariharaligi4gun'],true)){
        if(isset($dates[$v]))throw new RuntimeException('Tekrarlanan tarife dönem başlığı.');
        $dates[$v]=$i;
    }
    if(!$dates)return null;
    $first=$dates['tariharaligi3gun']??null;$second=$dates['tariharaligi4gun']??null;
    if($first===null||$second===null||$first>=$second)throw new RuntimeException('Tarife dönemleri eksik veya sırası geçersiz.');
    $unique=static function(string $v)use($h):int{
        $indices=array_keys($h,$v,true);if(count($indices)!==1)throw new RuntimeException('Tarife kolonu eksik veya tekrar ediyor: '.$v);return $indices[0];
    };
    $barcode=$unique('barkod');$name=array_search('urunismi',$h,true);
    $lower=[];$upper=[];
    for($i=1;$i<=4;$i++){
        if($i<4)$lower[$i]=$unique($i.'fiyataltlimit');
        if($i>1)$upper[$i]=$unique($i.'fiyatustlimiti');
    }
    $prices=[];$rates=[];
    foreach([3=>$first,4=>$second] as $days=>$dateIndex)for($i=1;$i<=4;$i++){
        $indices=array_keys($h,$i.'komisyon',true);
        if(count($indices)!==2||$indices[0]<=$first||$indices[0]>=$second||$indices[1]<=$second)throw new RuntimeException('Tarife dönem komisyonları eksik veya konumu geçersiz.');
        $key='period'.$days.'_tier'.$i;$rates[$key]=$indices[$days===3?0:1];
        $prices[$key]=['index'=>$upper[$i]??$lower[$i],'lower_index'=>$lower[$i]??null,'upper_index'=>$upper[$i]??null,'period_index'=>$dateIndex,'label'=>$days.' Gün / '.$i.'. Kademe'];
    }
    return ['barcode'=>$barcode,'name'=>$name===false?null:$name,'prices'=>$prices,'rates'=>$rates,'period_tariff'=>true,'apply_columns'=>tariff_apply_columns($headers)];
}
function analysis_period_price(array $def,array $row): ?array
{
    $lo=$def['lower_index']===null?null:parse_money_input((string)($row[$def['lower_index']]??''));
    $hi=$def['upper_index']===null?null:parse_money_input((string)($row[$def['upper_index']]??''));
    if(($def['lower_index']!==null&&($lo===null||$lo<=0||$lo>100000000))||($def['upper_index']!==null&&($hi===null||$hi<=0||$hi>100000000))||($lo!==null&&$hi!==null&&$lo>$hi))return null;
    return ['price'=>$hi??$lo,'range'=>true,'min'=>$lo,'boundary'=>$hi===null?'lower':($lo===null?'upper':'bounded')];
}
/** Explicit known column aliases, not arbitrary fields containing 'price'. */
function analysis_schema(array $headers,string $module): array
{
    if(count($headers)>ANALYSIS_MAX_COLS)throw new RuntimeException('Kolon sınırı aşıldı.');
    if(in_array($module,['komisyon','plus'],true)){
        $period=analysis_period_tariff_schema($headers);if($period!==null)return $period;
    }
    $barcode=null;$name=null;$prices=[];$rates=[];
    $aliases=['fiyat'=>'base','price'=>'base','satisfiyati'=>'base','satisfiyat'=>'base',
       'superavantajfiyat'=>'super','superavantajfiyataraligi'=>'super',
       'cokavantajfiyat'=>'cok','cokavantajfiyataraligi'=>'cok',
       'avantajfiyat'=>'avantaj','avantajfiyataraligi'=>'avantaj',
       'kampanyamaksimumfiyati'=>'campaign','kampanyamaximumfiyati'=>'campaign',
       'kampanyafiyati'=>'campaign','indirimlifiyat'=>'discount',
       'trendyolplusfiyataraligi'=>'plus','trendyolplusfiyati'=>'plus','plusfiyati'=>'plus',
       '24saatlikfiyat'=>'flash24','3saatlikfiyat'=>'flash3','24saatlik'=>'flash24','3saatlik'=>'flash3'];
    $rateAliases=['komisyon'=>'base','komisyonorani'=>'base','commission'=>'base','commissionrate'=>'base',
       'superavantajkomisyon'=>'super','superavantajkomisyonorani'=>'super',
       'cokavantajkomisyon'=>'cok','cokavantajkomisyonorani'=>'cok','avantajkomisyon'=>'avantaj',
       'avantajkomisyonorani'=>'avantaj','kampanyakomisyonorani'=>'campaign','trendyolpluskomisyonorani'=>'plus',
       'pluskomisyonorani'=>'plus','24saatlikkomisyonorani'=>'flash24','3saatlikkomisyonorani'=>'flash3'];
    foreach($headers as $i=>$raw){
        $h=analysis_header((string)$raw);
        if(in_array($h,['barkod','barcode','barkodno','sku'],true)){
            if($barcode!==null)throw new RuntimeException('Birden fazla barkod kolonu.');
            $barcode=$i;continue;
        }
        if(in_array($h,['urunadi','urunismi','urun','name'],true))$name=$i;
        $key=$aliases[$h]??null;
        if(preg_match('/^(\d+)(?:fiyat|fiyataraligi|price)$/D',$h,$m))$key='tier'.$m[1];
        if($key!==null){
            if(isset($prices[$key]))throw new RuntimeException('Tekrarlanan fiyat kolonu: '.$raw);
            $prices[$key]=['index'=>$i,'label'=>(string)$raw];continue;
        }
        $key=$rateAliases[$h]??null;
        if(preg_match('/^(\d+)(?:komisyon|komisyonorani|commission|commissionrate)$/D',$h,$m))$key='tier'.$m[1];
        if($key!==null){if(isset($rates[$key]))throw new RuntimeException('Tekrarlanan komisyon kolonu.');$rates[$key]=$i;}
    }
    if($module!=='bulk'&&count($prices)>1)unset($prices['base']);
    if($barcode===null||!$prices||count($prices)>ANALYSIS_MAX_TIERS)throw new RuntimeException('Barkod ve tanınan fiyat kolonları gerekli; en fazla 12 kademe.');
    return ['barcode'=>$barcode,'name'=>$name,'prices'=>$prices,'rates'=>$rates];
}
function analysis_read_upload(array $upload): array
{
    if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($upload['size']??0)>20*1024*1024)
        throw new RuntimeException('Dosya yüklenemedi veya 20 MB sınırı aşıldı.');
    $path=(string)$upload['tmp_name'];$ext=strtolower(pathinfo((string)$upload['name'],PATHINFO_EXTENSION));
    if($ext==='xlsx'){
        $data=xlsx_read($path,true);
        if(count($data['rows'])>ANALYSIS_MAX_ROWS)throw new RuntimeException('En fazla 20.000 satır yüklenebilir.');
        return [$data['headers'],$data['rows']];
    }
    if($ext!=='csv')throw new RuntimeException('Yalnızca CSV veya XLSX desteklenir.');
    $f=fopen($path,'r');if(!$f)throw new RuntimeException('CSV açılamadı.');
    try{
        $first=fgets($f);rewind($f);
        $delim=substr_count((string)$first,';')>substr_count((string)$first,',')?';':',';
        $header=fgetcsv($f,null,$delim,'"','');
        if(!$header)throw new RuntimeException('CSV başlığı yok.');
        $header[0]=preg_replace('/^\xEF\xBB\xBF/','',(string)$header[0]);
    }catch(Throwable $e){fclose($f);throw $e;}
    $rows=(static function()use($f,$delim){
        try{$count=0;while(($row=fgetcsv($f,null,$delim,'"',''))!==false){
            if(++$count>ANALYSIS_MAX_ROWS)throw new RuntimeException('En fazla 20.000 satır yüklenebilir.');
            yield $row;
        }}finally{fclose($f);}
    })();
    return [$header,$rows];
}

function analysis_row(array $schema,array $row,array $products,string $module,array $rules=[]): array
{
    $barcode=trim((string)($row[$schema['barcode']]??''));
    $p=$products[$barcode]??null;
    $name=$p['name']??($schema['name']===null?'':(string)($row[$schema['name']]??''));
    $cost=$p&&is_numeric($p['cost']??null)&&(float)$p['cost']>0?(float)$p['cost']:null;
    $source=null;if(!empty($schema['apply_columns'])){$c=$schema['apply_columns'];$source=['base'=>parse_money_input((string)($row[$c['base']]??'')),'tsf'=>parse_money_input((string)($row[$c['tsf']]??'')),'promo'=>(string)($row[$c['promo']]??'')];}
    $best=null;$tiers=[];$rule=$p?target_for_product($rules,$p):null;$meets=null;
    $baseline=null;$baselinePrice=null;$baselineRate=null;
    if($cost!==null&&is_numeric($p['price']??null)&&(float)$p['price']>0&&is_numeric($p['rate']??null)){
        try{$baseline=product_profit($p);$baselinePrice=(float)$p['price'];$baselineRate=(float)$p['rate'];}catch(InvalidArgumentException $e){}
    }
    foreach($schema['prices'] as $key=>$def){
        $period=isset($def['period_index'])?trim((string)($row[$def['period_index']]??'')):null;
        $price=isset($def['period_index'])?($period!==''?analysis_period_price($def,$row):null):analysis_price((string)($row[$def['index']]??''));
        $explicit=isset($schema['rates'][$key]);
        $rate=$explicit?analysis_rate((string)($row[$schema['rates'][$key]]??'')):($module==='bulk'&&$p? (float)$p['rate']:null);
        $t=['price'=>$price['price']??null,'range'=>$price['range']??false,'rate'=>$rate,'profit'=>null,'margin'=>null,'reason'=>'','target_met'=>null,'min_price'=>($price['boundary']??null)==='lower'?null:($price['min']??null),'period'=>$period,'boundary'=>$price['boundary']??null,'min_endpoint_profit'=>null,'profit_delta'=>null,'rate_source'=>$explicit?'file':($module==='bulk'&&$p?'catalog':null)];
        if($cost===null)$t['reason']=$p?'Maliyet eksik':'Barkod eşleşmedi';
        elseif($price===null)$t['reason']='Fiyat veya kapalı aralık okunamadı';
        elseif($rate===null||$rate<0||$rate>100)$t['reason']='Kademe komisyonu eksik';
        else{
            try{$calc=product_profit($p,$price['price'],$rate);$t['profit']=$calc['profit'];$t['margin']=$calc['margin'];
                if($baseline!==null)$t['profit_delta']=$calc['profit']-$baseline['profit'];
                if($price['range']&&($t['min_price']??0)>0)$t['min_endpoint_profit']=product_profit($p,$price['min'],$rate)['profit'];
                // A range endpoint or unverified campaign is not an automatically eligible selection.
                $t['reason']=$price['range']?'Aralık uçları: tahmini senaryo; uygunluk doğrulanmadı':'Tahmini hesap; kampanya uygunluğu doğrulanmadı';
                if(isset($def['period_index']))$t['reason']=match($t['boundary']){'lower'=>'Alt limit ve üzeri: yalnız alt limit senaryosu','upper'=>'Üst limit ve altı: yalnız üst limit senaryosu',default=>'Alt ve üst limit senaryoları'}.'; tarife uygunluğu doğrulanmadı';
                if($module==='bulk')$t['reason']=$price['range']?'Aralık uçları: tahmini senaryo':'Tahmini hesap';
                if($rule&&!$price['range']&&($module==='bulk'||in_array($module,['komisyon','plus'],true))){
                    $t['target_met']=target_pass($rule,$calc,$cost,$price['price']);
                    $meets=($meets===true)||$t['target_met'];
                }
                if(!$price['range']&&($module==='bulk'||in_array($module,['komisyon','plus'],true))&&($best===null||$calc['profit']>$best))$best=$calc['profit'];
            }catch(InvalidArgumentException $e){$t['reason']='Ürün hesap ayarları geçersiz';}
        }
        if($source!==null&&$t['profit']!==null){
            $candidate=tariff_candidate($source,$t);
            if($candidate!==null){
                try{$calc=product_profit($p,$candidate['effective'],$rate);$t['application']=$candidate;$t['profit']=$calc['profit'];$t['margin']=$calc['margin'];$t['profit_delta']=$baseline!==null?$calc['profit']-$baseline['profit']:null;$t['application_target']=$rule?target_pass($rule,$calc,$cost,$candidate['effective']):null;}catch(InvalidArgumentException $e){}
            }
        }
        $tiers[$key]=$t;
    }
    return ['barcode'=>$barcode,'name'=>$name,'cost'=>$cost,'matched'=>$p!==null,'tiers'=>$tiers,'best'=>$best,'rule'=>$rule?target_label($rule):null,'meets_target'=>$meets,'baseline_price'=>$baselinePrice,'baseline_rate'=>$baselineRate,'baseline_profit'=>$baseline['profit']??null];
}

/** Store one analysis per module/account. A bad upload preserves the prior analysis. */
function analysis_save(PDO $db,int $uid,string $kind,string $module,array $schema,iterable $rows,array $products,array $rules=[],?string $sourceHash=null): array
{
    $db->beginTransaction();
    try{
        $args=[$uid,$kind,$module];
        $db->prepare("DELETE FROM analysis_rows WHERE user_id=? AND kind=? AND module=?")->execute($args);
        $insert=$db->prepare("INSERT INTO analysis_rows(user_id,kind,module,row_no,payload_json,best_profit,meets_target) VALUES(?,?,?,?,?,?,?)");
        $stats=['rows'=>0,'matched'=>0,'profitable'=>0,'unmatched'=>0,'missing_cost'=>0,'meets_target'=>0];$seen=0;
        foreach($rows as $row){
            if(++$seen>ANALYSIS_MAX_ROWS||count($row)>ANALYSIS_MAX_COLS)throw new RuntimeException('Satır veya kolon sınırı aşıldı.');
            if(!array_filter($row,static fn($v)=>trim((string)$v)!==''))continue;
            $r=analysis_row($schema,$row,$products,$module,$rules);
            if($r['barcode']==='')continue;
            $stats['rows']++;$stats[$r['matched']?'matched':'unmatched']++;
            if($r['matched']&&$r['cost']===null)$stats['missing_cost']++;
            if($r['best']!==null&&$r['best']>0)$stats['profitable']++;
            if($r['meets_target']===true)$stats['meets_target']++;
            $insert->execute(array_merge($args,[$stats['rows'],json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$r['best'],$r['meets_target']===null?null:(int)$r['meets_target']]));
        }
        if(!$stats['rows'])throw new RuntimeException('Geçerli barkod satırı bulunamadı.');
        if($sourceHash!==null)$stats['tariff_source']=$sourceHash;
        $db->prepare("INSERT INTO analysis_jobs(user_id,kind,module,headers_json,stats_json,created_at) VALUES(?,?,?,?,?,?)
            ON CONFLICT(user_id,kind,module) DO UPDATE SET headers_json=excluded.headers_json,stats_json=excluded.stats_json,created_at=excluded.created_at")
            ->execute(array_merge($args,[json_encode($schema['prices'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),json_encode($stats,JSON_THROW_ON_ERROR),time()]));
        $db->commit();return $stats;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
