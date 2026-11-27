<?php
declare(strict_types=1);
/** Exact template fields only. Refuse prefilled actions instead of resubmitting them. */
function campaign_export_columns(array $headers,string $module):array{
    $h=array_map(static fn($v)=>analysis_header((string)$v),$headers);
    $keys=match($module){
        'plus'=>['price'=>'plusfiyatsecimi','choice'=>'tarifesecimi','choice3'=>'3guntariharaligi','choice4'=>'4guntariharaligi','cancel'=>'iptal'],
        'etiket'=>['price'=>'yenitsffiyatguncelle','choice'=>'tarifesonunakadaruygula'],
        'flash'=>['choice'=>'guncellenecekfiyat','custom'=>'seninbelirlediginflasfiyati'],
        default=>throw new RuntimeException('Bu modül için şablon desteklenmiyor.')};
    $out=[];foreach($keys as $k=>$v){$ix=array_keys($h,$v,true);if(count($ix)!==1)throw new RuntimeException('Excel seçim alanı eksik: '.$v);$out[$k]=$ix[0];}return $out;
}
function campaign_export_clean(array $raw,array $cols,string $module):bool{
    foreach(match($module){'plus'=>['price','choice','cancel'],'etiket'=>['price'],'flash'=>['choice','custom']} as $k)if(trim((string)($raw[$cols[$k]]??''))!=='')return false;
    return true;
}
function campaign_export_plan(string $module,string $key,array $quote,array $raw,array $cols):?array{
    if(empty($quote['conditional'])||($quote['target_met']??null)===false||!campaign_export_clean($raw,$cols,$module))return null;
    if($module==='plus'){
        if(!preg_match('/^period([34])_plus$/D',$key,$m)||!is_numeric($quote['plus_profit']??null)||$quote['plus_profit']<=0)return null;
        $label=trim((string)($raw[$cols['choice'.$m[1]]]??''));
        if($label!==$m[1].' Günlük Fiyat ('.$quote['period'].')')return null;
        $price=$quote['plus_price']??0;if(!is_numeric($price)||$price<=0)return null;
        return [$cols['price']=>(float)$price,$cols['choice']=>$label];
    }
    if(!is_numeric($quote['normal_profit']??null)||$quote['normal_profit']<=0)return null;
    if($module==='flash'&&in_array($key,['flash24','flash3'],true))return [$cols['choice']=>$key==='flash24'?'24 Saat':'3 Saat'];
    if($module==='etiket'&&preg_match('/^star[1-3]$/D',$key)&&is_numeric($quote['normal_price']??null)&&$quote['normal_price']>0)return [$cols['price']=>(float)$quote['normal_price'],$cols['choice']=>'Hayır'];
    return null;
}
/** Original sheet coordinates from XLSX reader, hash-bound by caller.
 * Only specified cells are changed. Existing computed columns and validations stay intact. */
function campaign_patch_xlsx(string $source,string $dest,array $patches):void{
    if(!$patches||!copy($source,$dest))throw new RuntimeException('Excel hazırlanamadı.');
    $z=new ZipArchive();if($z->open($dest)!==true)throw new RuntimeException('Excel açılamadı.');
    try{
        $stat=$z->statName('xl/worksheets/sheet1.xml');if(!$stat||$stat['size']>32*1024*1024)throw new RuntimeException('Excel sayfası geçersiz.');
        $xml=$z->getFromName('xl/worksheets/sheet1.xml');if(!is_string($xml)||stripos($xml,'<!DOCTYPE')!==false)throw new RuntimeException('Excel XML geçersiz.');
        $doc=new DOMDocument();if(!@$doc->loadXML($xml,LIBXML_NONET))throw new RuntimeException('Excel XML okunamadı.');
        $xp=new DOMXPath($doc);$ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';$xp->registerNamespace('s',$ns);
        $letter=static function(int $i):string{$s='';for($i++;$i>0;$i=intdiv($i-1,26))$s=chr(65+($i-1)%26).$s;return $s;};
        foreach($patches as $rn=>$fields){
            if(!is_int($rn)||$rn<2)throw new RuntimeException('Excel satırı geçersiz.');
            foreach($fields as $idx=>$value){
                if(!is_int($idx)||$idx<0||$idx>=128)throw new RuntimeException('Excel kolonu geçersiz.');
                $coord=$letter($idx).$rn;$list=$xp->query('/s:worksheet/s:sheetData/s:row[@r="'.$rn.'"]/s:c[@r="'.$coord.'"]');
                if($list->length!==1||$xp->query('s:f',$list->item(0))->length)throw new RuntimeException('Düzenlenebilir Excel hücresi bulunamadı: '.$coord);
                $cell=$list->item(0);while($cell->firstChild)$cell->removeChild($cell->firstChild);
                if(is_float($value)&&is_finite($value)&&$value>0){$cell->setAttribute('t','n');$cell->appendChild($doc->createElementNS($ns,'v',number_format($value,2,'.','')));}
                elseif(is_string($value)&&$value!==''){$cell->setAttribute('t','inlineStr');$is=$doc->createElementNS($ns,'is');$t=$doc->createElementNS($ns,'t');$t->appendChild($doc->createTextNode($value));$is->appendChild($t);$cell->appendChild($is);}
                else throw new RuntimeException('Geçersiz Excel değeri.');
            }
        }
        if(!$z->addFromString('xl/worksheets/sheet1.xml',$doc->saveXML()))throw new RuntimeException('Excel kaydedilemedi.');
    }finally{$z->close();}
}
function campaign_quote_token(array $quote,string $source,int $row,string $key):string{
    return hash('sha256',json_encode([$quote,$source,$row,$key],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}
