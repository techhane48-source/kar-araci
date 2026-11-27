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
$tests['unknown promotion ratio refused']=$c===null;
$c=tariff_candidate(['base'=>100.0,'tsf'=>100.0,'promo'=>''],$target);
$tests['no promotion target same']=$c['tsf']===72.35&&!$c['promotion_assumption'];
$now=strtotime('2026-11-27 12:00:00');
$tests['period open']=tariff_period_open('26 Kasım 08.00-29 Kasım 07.59',$now);
$tests['future period within week']=tariff_period_open('29 Kasım 08.00-3 Aralık 07.59',$now);
$tests['expired period refused']=!tariff_period_open('19 Kasım 08.00-22 Kasım 07.59',$now);
$rr=['tiers'=>['period3_tier3'=>['profit'=>20,'period'=>'26 Kasım 08.00-29 Kasım 07.59','application'=>['tsf'=>90,'promotion_assumption'=>true]]]];
$tests['auto skips promotion']=tariff_apply_select($rr,'period3_tier3',true,true,$now)===null;
$tests['manual requires promotion acknowledgement']=tariff_apply_select($rr,'period3_tier3',false,false,$now)===null;
$tests['manual acknowledgement cannot establish unknown funding']=tariff_apply_select($rr,'period3_tier3',false,true,$now)===null;
$rr['tiers']['period3_tier3']['application']['promotion_assumption']=false;
$tests['manual clean period selection']=tariff_apply_select($rr,'period3_tier3',false,false,$now)['choice']==='3 Günlük Fiyat';
$nt=[['min'=>8701,'max'=>9500,'rate'=>19],['min'=>7301,'max'=>8700,'rate'=>17],['max'=>7300,'rate'=>11]];
$pt=[['max'=>7000,'rate'=>9]];
$D=static fn(int $seller,int $platform=0):array=>[['seller'=>$seller,'platform'=>$platform]];
$cases=[
[7000,[],[],10000,20,7000,9],
[7000,$D(2000),[],8000,17,5000,9],
[7000,$D(1000,1000),[],9000,19,6000,9],
[7000,[],$D(2000),10000,20,7000,9],
[null,[],$D(2000),10000,20,8000,17],
[7000,[],$D(1000,1000),10000,20,7000,9],
[null,[],$D(1000,1000),10000,20,9000,19],
[7000,$D(1000),$D(2000),9000,19,6000,9],
[null,$D(1000),$D(2000),9000,19,7000,9],
[7000,$D(500,500),$D(1000,1000),9500,19,6500,9],
[null,$D(500,500),$D(1000,1000),9500,19,8500,17]
];
foreach($cases as $i=>[$sp,$general,$only,$nb,$nr,$pb,$pr]){
    $q=campaign_plus_quote(10000,$sp,7000,$general,$general,$only,$nt,$pt,20.0);
    $tests['Akademi Plus scenario '.($i+1)]=$q!==null&&$q['normal']['basis']===$nb&&$q['normal']['rate']===(float)$nr&&$q['plus']['basis']===$pb&&$q['plus']['rate']===(float)$pr;
}
$q=campaign_plus_quote(10000,null,7000,$D(1000,1000),[],[],$nt,$pt,20.0);
$tests['customer and seller basis distinct']=$q['normal']['customer']===8000&&$q['normal']['basis']===9000&&$q['normal']['commission']===1710;
$tests['Plus price cap enforced']=campaign_plus_quote(4800,4900,4900,[],[],[],$nt,$pt,20.0)===null;
$tests['no unsupported tie break']=campaign_plus_quote(10000,7000,7000,[],[],$D(2000,1000),$nt,$pt,20.0)===null;
$tests['flash full day and invalid slot']=campaign_slot('28/11/2026 00:00','28/11/2026 23:59',24)!==null&&campaign_slot('28/11/2026 04:00','28/11/2026 07:00',3)===null;
$plusHeaders=['Ürün İsmi','Ürün Id','Barkod','Güncel TSF','Komisyona Esas Fiyat','Güncel Komisyon','Plus Fiyat Üst Limiti','Tarih Aralığı (3 Gün)','Plus Komisyon Teklifi','Tarih Aralığı (4 Gün)','Plus Komisyon Teklifi','Plus Komisyona Esas Fiyatı','Müşterinin Gördüğü Güncel Fiyat','Plus Müşterinin Gördüğü Fiyat','Güncel Uygulanan Promosyonlar','Stok'];
$ps=analysis_schema($plusHeaders,'plus');$pr=analysis_row($ps,['Synthetic','SYN-ID','001','200','200','20','150','26 Kasım 08.00-29 Kasım 07.59','9','29 Kasım 08.00-3 Aralık 07.59','15','','200','','','10'],$products,'plus');
$tests['Plus two periods different rates']=$pr['tiers']['period3_plus']['rate']===9.0&&$pr['tiers']['period4_plus']['rate']===15.0&&$pr['tiers']['period3_plus']['profit']!==null;
$fr=analysis_schema(['Barkod','Ürün Adı','Ürün Id','Mevcut Fiyat','Müşterinin Gördüğü Fiyat','Plus Müşterinin Gördüğü Fiyat','Güncel Uygulanan Promosyonlar','Stok','Ürün Komisyon Tarife Seçeneği','Kampanyalı Ürün','Mevcut Komisyon','24 Saat Fiyat','3 Saat Fiyat','24 Saat Flaş Başlangıç Tarihi','24 Saat Flaş Bitiş Tarihi','3 Saat Flaş Başlangıç Tarihi','3 Saat Flaş Bitiş Tarihi'],'flash');
$frow=['001','Synthetic','SYN-ID','200','200','','','5','Var','-','20','140','','28/11/2026 00:00','28/11/2026 23:59','',''];$frow2=$frow;$frow2[13]='29/11/2026 00:00';$frow2[14]='29/11/2026 23:59';
$st=analysis_save(db(),1,'promotion','flash',$fr,[$frow,$frow2],$products);
$tests['flash duplicate barcode different offers']=$st['rows']===2&&$st['profitable']===0;
$star=analysis_schema(['Ürün İsmi','Barkod','1 YILDIZ ÜST FİYAT','1 YILDIZ ALT FİYAT','2 YILDIZ ÜST FİYAT','2 YILDIZ ALT FİYAT','3 YILDIZ ÜST FİYAT','TRENDYOL SATIŞ FİYATI','Müşterinin Gördüğü Güncel Fiyat','Plus Müşterinin Gördüğü Fiyat','Güncel Uygulanan Promosyonlar','Stok','KOMİSYON TARİFESİ','EXTERNAL ID'],'etiket');
$sr=analysis_row($star,['Synthetic','001','180','160.01','160','140.01','140','200','200','','','5','Var','SYN-ID'],$products,'etiket');
$tests['star bounds and unknown commission']=$sr['tiers']['star1']['price']===180.0&&$sr['tiers']['star2']['min_price']===140.01&&$sr['tiers']['star3']['profit']===null;
db()->exec("DELETE FROM analysis_rows WHERE module='flash'");
$at=strtotime('2026-11-27 12:00:00+03:00');
$tests['period year anchored to upload']=campaign_period_window('30 Aralık 08.00-2 Ocak 07.59',strtotime('2026-12-29 12:00:00+03:00'))===[strtotime('2026-12-30 08:00:00+03:00'),strtotime('2027-01-02 07:59:59+03:00')];
$normal=analysis_row($dual,['Synthetic','001','500.01','500','400.01','400','300.01','300','26 Kasım 08.00-29 Kasım 07.59','21','13.1','8.4','6.1','29 Kasım 08.00-3 Aralık 07.59','21','17.6','15.8','13.6'],$products,'komisyon');
$normal['campaign_meta']=['tsf'=>'200','basis'=>'200','customer'=>'200','plus_customer'=>'','promo'=>'','current_rate'=>'20','choice'=>'3 Günlük Fiyat'];
$ctx=['komisyon'=>['row'=>$normal,'at'=>$at]];
$quotes=campaign_link_quotes($pr,'plus',$ctx,$products['001'],$at,$at);
$tests['same barcode and period separate normal Plus profit']=isset($quotes['period3_plus'],$quotes['period4_plus'])&&$quotes['period3_plus']['normal_rate']===6.1&&$quotes['period3_plus']['plus_rate']===9.0&&$quotes['period4_plus']['plus_rate']===15.0;
$modified=$pr;$modified['campaign_meta']['promo']='Unknown campaign';
$tests['unknown promotion blocks linked quote']=campaign_link_quotes($modified,'plus',$ctx,$products['001'],$at,$at)===[];
$tests['stale uploaded record blocks quote']=campaign_link_quotes($pr,'plus',$ctx,$products['001'],$at-86400,$at)===[];
$fr1=analysis_row($fr,$frow,$products,'flash');$fr2=analysis_row($fr,$frow2,$products,'flash');
$tests['flash slot must fit one full period']=isset(campaign_link_quotes($fr1,'flash',$ctx,$products['001'],$at,$at)['flash24'])&&campaign_link_quotes($fr2,'flash',$ctx,$products['001'],$at,$at)===[];
$normal['tiers']['period3_tier3']['min_price']=299.0;
$tests['overlapping tariff rejected']=campaign_normal_tiers($normal,3)===null;
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
$pc=campaign_export_columns(['Plus Fiyat Seçimi','Tarife Seçimi','3 Gün Tarih Aralığı','4 Gün Tarih Aralığı','İptal'],'plus');
$quote=['conditional'=>true,'plus_profit'=>20.0,'plus_price'=>150.0,'period'=>'26 Kasım 08.00-29 Kasım 07.59'];
$plan=campaign_export_plan('plus','period3_plus',$quote,['','','3 Günlük Fiyat (26 Kasım 08.00-29 Kasım 07.59)','',''],$pc);
$tests['Plus exact original date choice']=$plan===[0=>150.0,1=>'3 Günlük Fiyat (26 Kasım 08.00-29 Kasım 07.59)'];
$tests['prefilled selection rejected']=campaign_export_plan('plus','period3_plus',$quote,['100','','3 Günlük Fiyat (26 Kasım 08.00-29 Kasım 07.59)','',''],$pc)===null;
$fc=campaign_export_columns(['Güncellenecek Fiyat','Senin Belirlediğin Flaş Fiyatı'],'flash');
$tests['flash exact selection']=campaign_export_plan('flash','flash24',['conditional'=>true,'normal_profit'=>10.0],['',''],$fc)===[0=>'24 Saat'];
$ec=campaign_export_columns(['YENİ TSF (FİYAT GÜNCELLE)','Tarife Sonuna Kadar Uygula'],'etiket');
$tests['star draft does not lock price']=campaign_export_plan('etiket','star2',['conditional'=>true,'normal_profit'=>10.0,'normal_price'=>150.0],['','Evet'],$ec)===[0=>150.0,1=>'Hayır'];
$patch=$dir.'/campaign-patch.xlsx';campaign_patch_xlsx($orig,$patch,[3=>[21=>350.0,22=>'4 Günlük Fiyat']]);$pd=xlsx_read($patch,true);
$tests['exact row coordinates and untouched row']=$pd['row_numbers']===[2,3]&&$pd['rows'][0][21]===''&&$pd['rows'][1][21]==='350.00'&&$pd['rows'][1][22]==='4 Günlük Fiyat';
$tests['target prevents draft']=campaign_export_plan('plus','period3_plus',$quote+['target_met'=>false],['','','3 Günlük Fiyat (26 Kasım 08.00-29 Kasım 07.59)','',''],$pc)===null;
$qs=['star1'=>['conditional'=>true,'normal_profit'=>50.0,'period'=>'A','target_met'=>true],'star2'=>['conditional'=>true,'normal_profit'=>60.0,'period'=>'A','target_met'=>true],'star3'=>['conditional'=>true,'normal_profit'=>80.0,'period'=>'A','target_met'=>false]];
$tests['rank only eligible candidates within one period']=campaign_rank_quotes('etiket',$qs)===['star2'=>true];
$tests['no ranking different audiences or slots']=campaign_rank_quotes('flash',$qs)===[]&&campaign_rank_quotes('plus',$qs)===[];
$qs['star2']['period']='B';$tests['do not compare different periods']=campaign_rank_quotes('etiket',$qs)===[];
$blocked=campaign_link_quotes($pr,'plus',$ctx,$products['001'],$at,$at,['kind'=>'profit','value'=>999999]);
$tests['linked quote target applied']=isset($blocked['period3_plus'])&&$blocked['period3_plus']['target_met']===false;
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
