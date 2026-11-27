<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/karfiyat-analysis-'.bin2hex(random_bytes(8));mkdir($dir,0700);
define('DB_PATH',$dir.'/test.sqlite');
register_shutdown_function(static function()use($dir){foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);});
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/analysis_import.php';
$tests=[];
$schema=analysis_schema(['Barkod','Mevcut Satış Fiyatı','1. Fiyat Aralığı','1. Komisyon Oranı'],'komisyon');
$products=['001'=>['name'=>'Perde','cost'=>100,'price'=>200,'rate'=>19,'kdv'=>20,'cargo'=>10,'service_fee'=>13.19,'marketplace'=>'trendyol']];
$r=analysis_row($schema,['001','500','200,00','19%'],$products,'komisyon');
$tests['explicit tier commission']=$r['tiers']['tier1']['rate']===19.0&&$r['tiers']['tier1']['profit']!==null;
$tests['baseline same price same rate']=abs($r['tiers']['tier1']['profit_delta'])<1e-8&&$r['baseline_price']===200.0;
$tests['file commission source']=$r['tiers']['tier1']['rate_source']==='file';
$tests['baseline not included']=count($schema['prices'])===1;
$r=analysis_row($schema,['001','500','100 - 200','19%'],$products,'komisyon');
$tests['range scenario not auto selected']=$r['tiers']['tier1']['price']===200.0&&$r['best']===null;
$tests['range endpoints separate']=$r['tiers']['tier1']['min_price']===100.0&&$r['tiers']['tier1']['min_endpoint_profit']===product_profit($products['001'],100,19)['profit'];
$bad=$products;$bad['001']['cargo']=-20;$r=analysis_row($schema,['001','500','200','19'],$bad,'komisyon');
$tests['invalid cargo not profit']=$r['tiers']['tier1']['profit']===null&&$r['baseline_profit']===null;
$r=analysis_row($schema,['001','500','200',''],$products,'komisyon');
$tests['missing rate not guessed']=$r['tiers']['tier1']['profit']===null;
$r=analysis_row($schema,['999','500','200','19'],$products,'komisyon');
$tests['unmatched barcode not guessed']=!$r['matched']&&$r['best']===null;
$dual=analysis_schema(['ÜRÜN İSMİ','BARKOD','1.Fiyat Alt Limit','2.Fiyat Üst Limiti','2.Fiyat Alt Limit','3.Fiyat Üst Limiti','3.Fiyat Alt Limit','4.Fiyat Üst Limiti','Tarih aralığı (3 Gün)','1.KOMİSYON','2.KOMİSYON','3.KOMİSYON','4.KOMİSYON','Tarih aralığı (4 Gün)','1.KOMİSYON','2.KOMİSYON','3.KOMİSYON','4.KOMİSYON'],'komisyon');
$r=analysis_row($dual,['Synthetic','001','500.01','500','400.01','400','300.01','300','A','21','13.1','8.4','6.1','B','21','17.6','15.8','13.6'],$products,'komisyon');
$tests['two period tariff eight distinct tiers']=count($r['tiers'])===8&&$r['tiers']['period3_tier2']['rate']===13.1&&$r['tiers']['period4_tier2']['rate']===17.6;
$tests['period label and shared price boundaries']=$r['tiers']['period3_tier2']['period']==='A'&&$r['tiers']['period4_tier2']['period']==='B'&&$r['tiers']['period4_tier2']['price']===500.0&&$r['tiers']['period4_tier2']['min_price']===400.01;
$tests['open limits illustrative only']=$r['tiers']['period3_tier1']['price']===500.01&&$r['tiers']['period3_tier1']['min_price']===null&&$r['tiers']['period4_tier4']['price']===300.0&&$r['best']===null;
$source=['base'=>80.0,'tsf'=>100.0,'promo'=>'Synthetic discount'];
$target=['price'=>72.35,'boundary'=>'bounded','min_price'=>64.0];
$c=tariff_candidate($source,$target);
$tests['promotion TSF differs from target']=$c['tsf']===90.43&&$c['effective']===72.34&&$c['promotion_assumption'];
$c=tariff_candidate(['base'=>100.0,'tsf'=>100.0,'promo'=>''],$target);
$tests['no promotion target same']=$c['tsf']===72.35&&!$c['promotion_assumption'];
$now=strtotime('2026-11-27 12:00:00');
$tests['period open']=tariff_period_open('26 Kasım 08.00-29 Kasım 07.59',$now);
$tests['future period within week']=tariff_period_open('29 Kasım 08.00-3 Aralık 07.59',$now);
$tests['expired period refused']=!tariff_period_open('19 Kasım 08.00-22 Kasım 07.59',$now);
$rr=['tiers'=>['period3_tier3'=>['profit'=>20,'period'=>'26 Kasım 08.00-29 Kasım 07.59','application'=>['tsf'=>90,'promotion_assumption'=>true]]]];
$tests['auto skips promotion']=tariff_apply_select($rr,'period3_tier3',true,true,$now)===null;
$tests['manual requires promotion acknowledgement']=tariff_apply_select($rr,'period3_tier3',false,false,$now)===null;
$tests['manual period selection']=tariff_apply_select($rr,'period3_tier3',false,true,$now)['choice']==='3 Günlük Fiyat';
// A synthetic workbook with original sheet metadata, style IDs and two rows.
$headers=['ÜRÜN İSMİ','BARKOD','1.Fiyat Alt Limit','2.Fiyat Üst Limiti','2.Fiyat Alt Limit','3.Fiyat Üst Limiti','3.Fiyat Alt Limit','4.Fiyat Üst Limiti','Tarih aralığı (3 Gün)','1.KOMİSYON','2.KOMİSYON','3.KOMİSYON','4.KOMİSYON','Tarih aralığı (4 Gün)','1.KOMİSYON','2.KOMİSYON','3.KOMİSYON','4.KOMİSYON','KOMİSYONA ESAS FİYAT','GÜNCEL TSF','Güncel Uygulanan Promosyonlar','YENİ TSF (FİYAT GÜNCELLE)','Tarife Seçimi','FULL EXTERNAL ID'];
$vals=['Synthetic','001','500.01','500','400.01','400','300.01','300','26 Kasım 08.00-29 Kasım 07.59','21','13.1','8.4','6.1','29 Kasım 08.00-3 Aralık 07.59','21','17.6','15.8','13.6','500','500','','','7 Günlük Fiyat','SYNTHETIC-EXTERNAL-ID'];
$xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
foreach([$headers,$vals,array_replace($vals,[1=>'002'])] as $i=>$v){
    $rn=$i+1;$xml.='<row r="'.$rn.'">';
    foreach($v as $j=>$val){$ref=chr(65+$j).$rn;$xml.='<c r="'.$ref.'" s="2" t="inlineStr"><is><t>'.htmlspecialchars($val,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>';}
    $xml.='</row>';
}
$xml.='</sheetData><dataValidations count="1"><dataValidation type="list" sqref="W2:W3"><formula1>"3 Günlük Fiyat,4 Günlük Fiyat"</formula1></dataValidation></dataValidations></worksheet>';
$orig=$dir.'/original.xlsx';$out=$dir.'/output.xlsx';$z=new ZipArchive();$z->open($orig,ZipArchive::CREATE|ZipArchive::OVERWRITE);$z->addFromString('xl/worksheets/sheet1.xml',$xml);$z->addFromString('docProps/custom.xml','synthetic untouched');$z->close();
tariff_export_xlsx($orig,$out,['b:001'=>['tsf'=>400.0,'choice'=>'3 Günlük Fiyat']]);
$after=xlsx_read($out,true);$z=new ZipArchive();$z->open($out);$patched=$z->getFromName('xl/worksheets/sheet1.xml');
$tests['original workbook updates selected row only']=$after['rows'][0][21]==='400.00'&&$after['rows'][0][22]==='3 Günlük Fiyat'&&$after['rows'][1][21]===''&&$after['rows'][1][22]==='7 Günlük Fiyat';
$tests['external ids styles and validation preserved']=$after['rows'][0][23]==='SYNTHETIC-EXTERNAL-ID'&&str_contains($patched,'dataValidations')&&str_contains($patched,'r="V2" s="2"')&&$z->getFromName('docProps/custom.xml')==='synthetic untouched';$z->close();
$bulk=analysis_schema(['Barkod','Fiyat'],'bulk');
$rows=(static function(){for($i=0;$i<20000;$i++)yield ['001','200'];})();
$stats=analysis_save(db(),1,'bulk','bulk',$bulk,$rows,$products);
$tests['20k rows stored']=$stats['rows']===20000&&(int)db()->query("SELECT COUNT(*) FROM analysis_rows")->fetchColumn()===20000;
try{analysis_save(db(),1,'bulk','bulk',$bulk,[['','']],$products);$tests['failed import atomic']=false;}
catch(RuntimeException $e){$tests['failed import atomic']=(int)db()->query("SELECT COUNT(*) FROM analysis_rows")->fetchColumn()===20000;}
analysis_save(db(),2,'bulk','bulk',$bulk,[['001','200']],$products);
$tests['account isolation']=(int)db()->query("SELECT COUNT(*) FROM analysis_rows WHERE user_id=2")->fetchColumn()===1;
foreach($tests as $n=>$ok)echo ($ok?'PASS':'FAIL')." $n\n";
exit(in_array(false,$tests,true)?1:0);
