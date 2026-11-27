<?php
declare(strict_types=1);
/** Export only the original uploaded XLSX. Never write a marketplace price. */
function tariff_apply_columns(array $headers): ?array
{
    $h=array_map(static fn($s)=>analysis_header((string)$s),$headers);$out=[];
    foreach(['base'=>'komisyonaesasfiyat','tsf'=>'gunceltsf','promo'=>'gunceluygulananpromosyonlar','new'=>'yenitsffiyatguncelle','choice'=>'tarifesecimi'] as $k=>$v){
        $ix=array_keys($h,$v,true);if(count($ix)!==1)return null;$out[$k]=$ix[0];
    }
    return $out;
}
/** Prices at known boundaries, not a claim of the global optimum.
 * Active promotions require a separate explicit acknowledgement. */
function tariff_candidate(array $source,array $tier): ?array
{
    $base=$source['base']??null;$tsf=$source['tsf']??null;$target=$tier['price']??null;
    if(!is_numeric($base)||!is_numeric($tsf)||!is_numeric($target)||$base<=0||$tsf<=0||$base>$tsf||$target<=0||$target>100000000)return null;
    $promo=trim((string)($source['promo']??''))!==''||abs($base-$tsf)>0.005;
    // The observed effective/TSF ratio is only a scenario, never a verified promotion rule.
    $ratio=$promo?(float)$base/(float)$tsf:1.0;
    if($ratio<0.001)return null;
    $c=(int)floor((float)$target*100/$ratio+0.000001);
    $lower=($tier['boundary']??null)==='lower';
    if($lower)$c=(int)ceil((float)$target*100/$ratio-0.000001);
    $effective=round($c/100*$ratio,2,PHP_ROUND_HALF_UP);
    $lo=$lower?(float)$target:($tier['min_price']??0);
    if($effective+0.000001<$lo||(!$lower&&$effective>(float)$target+0.000001)||$c<=0||$c>10000000000)return null;
    return ['tsf'=>$c/100,'effective'=>$effective,'promotion_assumption'=>$promo];
}
function tariff_period_open(string $period,int $now):bool
{
    $months=['Ocak'=>1,'Şubat'=>2,'Mart'=>3,'Nisan'=>4,'Mayıs'=>5,'Haziran'=>6,'Temmuz'=>7,'Ağustos'=>8,'Eylül'=>9,'Ekim'=>10,'Kasım'=>11,'Aralık'=>12];
    if(!preg_match('/^(\d{1,2}) ([\p{L}]+) (\d{2})[.:](\d{2})\s*-\s*(\d{1,2}) ([\p{L}]+) (\d{2})[.:](\d{2})$/uD',trim($period),$m))return false;
    $a=$months[$m[2]]??0;$b=$months[$m[6]]??0;$y=(int)date('Y',$now);
    if(!$a||!$b||$m[3]>23||$m[4]>59||$m[7]>23||$m[8]>59)return false;
    foreach([$y-1,$y,$y+1] as $yr){
        $ey=$yr+($b<$a?1:0);
        if(!checkdate($a,(int)$m[1],$yr)||!checkdate($b,(int)$m[5],$ey))continue;
        $start=mktime((int)$m[3],(int)$m[4],0,$a,(int)$m[1],$yr);$end=mktime((int)$m[7],(int)$m[8],59,$b,(int)$m[5],$ey);
        if($start<=$now+8*86400&&$end>=$now&&$end>$start&&$end-$start<=8*86400)return true;
    }
    return false;
}
function tariff_source_path(int $uid,string $hash):string
{
    if($uid<1||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new RuntimeException('Tarife dosyası kimliği geçersiz.');
    $dir=dirname(DB_PATH).'/tariff-sources';
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Tarife dosya alanı açılamadı.');
    return $dir.'/'.$uid.'-'.$hash.'.xlsx';
}
function tariff_source_save(int $uid,string $path):string
{
    $hash=hash_file('sha256',$path);if(!$hash)throw new RuntimeException('Tarife dosyası okunamadı.');
    $dest=tariff_source_path($uid,$hash);
    if(is_file($dest)&&hash_file('sha256',$dest)===$hash)return $hash;
    $tmp=tempnam(dirname($dest),'upload-');if(!$tmp)throw new RuntimeException('Tarife dosyası saklanamadı.');
    try{if(!copy($path,$tmp)||hash_file('sha256',$tmp)!==$hash||!chmod($tmp,0600)||!rename($tmp,$dest))throw new RuntimeException('Tarife dosyası saklanamadı.');}finally{if(is_file($tmp))unlink($tmp);}
    return $hash;
}
function tariff_apply_select(array $r,string $key,bool $auto,bool $promoOk,int $now): ?array
{
    if(!preg_match('/^period([34])_tier[1-4]$/D',$key,$m))return null;
    $t=$r['tiers'][$key]??null;
    if(!$t||!is_numeric($t['profit']??null)||!tariff_period_open((string)($t['period']??''),$now))return null;
    $c=$t['application']??null;
    if(!$c||($c['promotion_assumption']&&!$promoOk)||($auto&&($c['promotion_assumption']||$t['profit']<=0||($t['application_target']??null)===false)))return null;
    return ['tsf'=>$c['tsf'],'choice'=>$m[1].' Günlük Fiyat','key'=>$key,'profit'=>$t['profit']];
}
/** Patch only target cells in the original sheet; preserve validations, styling, identifiers and remaining ZIP parts. */
function tariff_export_xlsx(string $source,string $dest,array $selected):void
{
    if(!$selected)throw new RuntimeException('Uygulanabilir tarife seçilmedi.');
    $data=xlsx_read($source,true);$cols=tariff_apply_columns($data['headers']);
    $schema=analysis_period_tariff_schema($data['headers']);if(!$cols||!$schema)throw new RuntimeException('Orijinal Trendyol şablonu doğrulanamadı.');
    $seen=[];$rows=[];
    foreach($data['rows'] as $r){
        $bc=trim((string)($r[$schema['barcode']]??''));
        if(trim((string)($r[$cols['new']]??''))!=='')throw new RuntimeException('Orijinal dosyada önceden doldurulmuş yeni fiyat var. Trendyol’dan temiz dosya indirin.');
        if($bc==='')continue;
        if(isset($seen['b:'.$bc]))throw new RuntimeException('Tekrarlayan barkod; tarife aktarımı güvenli değil.');
        $seen['b:'.$bc]=true;
    }
    if(!copy($source,$dest))throw new RuntimeException('Excel hazırlanamadı.');
    $zip=new ZipArchive();if($zip->open($dest)!==true)throw new RuntimeException('Excel açılamadı.');
    try{
        $raw=$zip->getFromName('xl/worksheets/sheet1.xml');if(!is_string($raw)||stripos($raw,'<!DOCTYPE')!==false)throw new RuntimeException('Excel XML geçersiz.');
        $doc=new DOMDocument();$doc->preserveWhiteSpace=true;
        if(!@$doc->loadXML($raw,LIBXML_NONET))throw new RuntimeException('Excel XML okunamadı.');
        $xp=new DOMXPath($doc);$ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';$xp->registerNamespace('s',$ns);
        $shared=[];$ss=$zip->getFromName('xl/sharedStrings.xml');
        if(is_string($ss)){
            if(stripos($ss,'<!DOCTYPE')!==false)throw new RuntimeException('Excel metinleri geçersiz.');
            $sd=new DOMDocument();if(!@$sd->loadXML($ss,LIBXML_NONET))throw new RuntimeException('Excel metinleri okunamadı.');
            $sx=new DOMXPath($sd);$sx->registerNamespace('s',$ns);foreach($sx->query('/s:sst/s:si') as $si){$v='';foreach($sx->query('.//s:t',$si) as $t)$v.=$t->textContent;$shared[]=$v;}
        }
        $letter=static function(int $i):string{$s='';for($i++;$i>0;$i=intdiv($i-1,26))$s=chr(65+($i-1)%26).$s;return $s;};
        $read=static function(DOMElement $c)use($xp,$shared):string{
            $v=$xp->query('s:v',$c)->item(0)?->textContent??'';
            if($c->getAttribute('t')==='s')return $shared[(int)$v]??'';
            if($c->getAttribute('t')==='inlineStr'){$v='';foreach($xp->query('s:is//s:t',$c) as $t)$v.=$t->textContent;}
            return $v;
        };
        $done=[];
        foreach($xp->query('/s:worksheet/s:sheetData/s:row') as $row){
            $rn=(int)$row->getAttribute('r');if($rn<=$data['header_row'])continue;
            $newCell=$xp->query('s:c[@r="'.$letter($cols['new']).$rn.'"]',$row)->item(0);
            if($newCell&&($xp->query('s:f',$newCell)->length||trim($read($newCell))!==''))throw new RuntimeException('Orijinal dosyada doldurulmuş yeni fiyat var.');
            $bcCell=$xp->query('s:c[@r="'.$letter($schema['barcode']).$rn.'"]',$row)->item(0);
            if(!$bcCell)continue;$bc=trim($read($bcCell));$key='b:'.$bc;if(!isset($selected[$key]))continue;
            if(isset($done[$key]))throw new RuntimeException('Excel barkodu tekrar ediyor.');
            foreach(['new','choice'] as $field){
                $coord=$letter($cols[$field]).$rn;$cell=$xp->query('s:c[@r="'.$coord.'"]',$row)->item(0);
                if(!$cell)throw new RuntimeException('Orijinal şablonda düzenlenebilir hücre eksik: '.$coord);
                while($cell->firstChild)$cell->removeChild($cell->firstChild);
                if($field==='new'){$cell->setAttribute('t','n');$cell->appendChild($doc->createElementNS($ns,'v',number_format((float)$selected[$key]['tsf'],2,'.','')));}
                else{$cell->setAttribute('t','inlineStr');$is=$doc->createElementNS($ns,'is');$is->appendChild($doc->createElementNS($ns,'t',$selected[$key]['choice']));$cell->appendChild($is);}
            }
            $done[$key]=true;
        }
        if(count($done)!==count($selected)||!$zip->addFromString('xl/worksheets/sheet1.xml',$doc->saveXML()))throw new RuntimeException('Seçilen barkodlar orijinal Excel ile eşleşmedi.');
    }finally{$zip->close();}
}
