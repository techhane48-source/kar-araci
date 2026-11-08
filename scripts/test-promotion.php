<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/promotion.php';
require_once __DIR__.'/promotion-fixtures.php';
$failed=0;$files=[];
function verify(bool $ok,string $label):void {global $failed;echo ($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)$failed++;}
function rejects(callable $f,string $label):void {try{$f();verify(false,$label);}catch(RuntimeException $e){verify(true,$label);}}
function fixture(string $kind,array $rows):string {global $files;return $files[]=promotion_fixture($kind,$rows);}
try {
    $range=promo_range('2050-11-05T08:00','2050-11-12T07:59');$now=promo_time('2050-11-08T12:00');
    $products=['0001'=>['name'=>'Örnek <perde> &','cost'=>100,'kdv'=>0,'rate'=>80,'cargo'=>0,'service_fee'=>0]];
    $source=fixture('commission',[promotion_fixture_commission()]);$book=promo_workbook($source);$tariff=promo_tariff($book,$range);$a=promo_analyze($book,'komisyon',$products,$range,$tariff,$now);
    verify($book['header_row']===1&&$book['sheet']==='xl/worksheets/offers.xml'&&$a['rows'][2]['barcode']==='0001','OOXML relationships, uppercase BARKOD and leading zeros');
    verify(count($a['rows'][2]['offers'])===4,'Only four tariff tiers; unrelated price columns excluded');
    verify($a['rows'][2]['offers'][1]['rate']===21.0&&$a['rows'][2]['offers'][2]['rate']===15.0&&abs($a['rows'][2]['offers'][1]['profit']-453)<.001,'Tier commission overrides product rate and produces expected profit');
    $zero=promotion_fixture_commission();$zero['4.KOMİSYON']=0;$zb=promo_workbook(fixture('commission',[$zero]));$za=promo_analyze($zb,'komisyon',$products,$range,promo_tariff($zb,$range),$now);
    verify($za['rows'][2]['offers'][4]['rate']===0.0,'Zero commission is preserved');
    rejects(fn()=>promo_schema($book,'flash'),'Wrong workbook/module rejected');
    rejects(fn()=>promo_tariff($book,promo_range('2050-11-05T00:00','2050-11-12T23:59')),'Vendor tariff hour boundaries checked against supplied dates');
    $sb=promo_workbook(fixture('star',[promotion_fixture_star()]));$sa=promo_analyze($sb,'etiket',$products,$range,$tariff,$now);
    verify(count($sa['rows'][2]['offers'])===3&&$sa['rows'][2]['offers'][1]['rate']===15.0,'Star upper prices and overlapping commission tariff');
    $unknown=promo_analyze($sb,'etiket',$products,$range,[],$now);
    verify(!$unknown['rows'][2]['offers']&&str_contains($unknown['rows'][2]['error'],'komisyon'),'Missing required commission tariff does not invent a profit');
    $missing=promo_analyze($sb,'etiket',['0001'=>['cost'=>0]],$range,$tariff,$now);
    verify(!$missing['rows'][2]['offers']&&str_contains($missing['rows'][2]['error'],'maliyet'),'Missing costs remain unknown and unselectable');
    $fb=promo_workbook(fixture('flash',[promotion_fixture_flash('08'),promotion_fixture_flash('15'),promotion_fixture_flash('12')]));$fa=promo_analyze($fb,'flash',$products,null,$tariff,$now);
    verify(count($fa['rows'])===3&&$fa['rows'][2]['offers'][24]['rate']===15.0&&$fa['rows'][3]['offers'][24]['rate']===21.0,'Repeated barcode offers retain dates; outside tariff uses base commission');
    verify(!$fa['rows'][4]['offers']&&str_contains($fa['rows'][4]['error'],'kısmen'),'Partial-hour tariff overlap is explicitly blocked');
    $short=promotion_fixture_flash('15');$short['3 Saat Fiyat']=450;$short['3 Saat Flaş Başlangıç Tarihi']='15/11/2050 12:00';$short['3 Saat Flaş Bitiş Tarihi']='15/11/2050 14:59';$shortBook=promo_workbook(fixture('flash',[$short]));$shortAnalysis=promo_analyze($shortBook,'flash',$products,null,$tariff,$now);
    verify(isset($shortAnalysis['rows'][2]['offers'][3]),'Three-hour offers supported when actual price and times exist');
    $expired=promo_analyze($fb,'flash',$products,null,$tariff,promo_time('2050-11-20T00:00'));verify(!$expired['rows'][2]['offers'],'Expired offers cannot be selected');
    rejects(fn()=>promo_export_changes($fa,[2=>'999']),'Forged offer key rejected');
    $out=$files[]=tempnam(sys_get_temp_dir(),'kf-promo-out-');promo_export_workbook($fb,promo_export_changes($fa,[2=>'24']),$out);$round=promo_workbook($out);$fm=promo_schema($round,'flash');
    verify($round['headers']===$fb['headers']&&count($round['records'])===3&&promo_cell($round['records'][0],$fm,'Güncellenecek Fiyat')==='24 Saat'&&promo_cell($round['records'][1],$fm,'Güncellenecek Fiyat')==='','Flash selection writes a label to one source row only');
    $za=new ZipArchive();$za->open($fb['path']);$zo=new ZipArchive();$zo->open($out);$same=true;
    for($i=0;$i<$za->numFiles;$i++){$n=$za->getNameIndex($i);if($n!==$fb['sheet']&&$za->getFromName($n)!==$zo->getFromName($n))$same=false;}$za->close();$zo->close();verify($same,'All non-sheet archive parts preserved byte-for-byte');
    $co=$files[]=tempnam(sys_get_temp_dir(),'kf-promo-commission-out-');promo_export_workbook($book,promo_export_changes($a,[2=>'2']),$co);$cb=promo_workbook($co);$cm=promo_schema($cb,'komisyon');
    verify(abs((float)promo_cell($cb['records'][0],$cm,'YENİ TSF (FİYAT GÜNCELLE)')-699.99)<.001&&promo_cell($cb['records'][0],$cm,'Kaynak notu')==='Özgün & <değer>','Numeric export preserves unrelated Unicode/XML text');
    $xml=promo_xml((function()use($co){$z=new ZipArchive();$z->open($co);$v=$z->getFromName('xl/worksheets/offers.xml');$z->close();return $v;})());$refs=[];foreach($xml->getElementsByTagNameNS(PROMO_XLSX_NS,'row')->item(1)->getElementsByTagNameNS(PROMO_XLSX_NS,'c') as $c)$refs[]=promo_column_index($c->getAttribute('r'));$sorted=$refs;sort($sorted);verify($refs===$sorted,'Inserted selection cells retain ascending OOXML column order');
    foreach(['-1','NaN','1.2.3','21foo'] as $bad)rejects(fn()=>promo_number($bad),'Invalid number rejected: '.$bad);
    verify(promo_number('1.234,56')===1234.56&&promo_number('1234.56')===1234.56,'Turkish and XLSX numeric formats');
    rejects(fn()=>promo_number('101',true),'Invalid commission percentage rejected');
    rejects(fn()=>promo_number('21%%',true),'Repeated percent marker rejected');
    rejects(fn()=>promo_number('299.999'),'Sub-cent price cannot round into a different commission tier on export');
    rejects(fn()=>promo_time('31/02/2050 00:00'),'Invalid calendar date rejected');
    $evil=fixture('commission',[promotion_fixture_commission()]);$z=new ZipArchive();$z->open($evil);$z->addFromString('../outside.txt','no');$z->close();rejects(fn()=>promo_workbook($evil),'ZIP traversal members rejected without extraction');
    $bad=fixture('commission',[promotion_fixture_commission()]);$z=new ZipArchive();$z->open($bad);$v=$z->getFromName('xl/worksheets/offers.xml');$z->addFromString('xl/worksheets/offers.xml',str_replace('</worksheet>','',$v));$z->close();rejects(fn()=>promo_workbook($bad),'Truncated worksheet rejected');
    rejects(fn()=>promo_xml('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>'),'External entities rejected');
    $formula=fixture('commission',[promotion_fixture_commission()]);$z=new ZipArchive();$z->open($formula);$v=$z->getFromName('xl/worksheets/offers.xml');$z->addFromString('xl/worksheets/offers.xml',str_replace('<v>21</v>','<f>20+1</f><v>21</v>',$v));$z->close();$fbook=promo_workbook($formula);$ft=promo_tariff($fbook,$range);verify($ft['0001']['error']!==null,'Cached formula result not trusted as a fixed commission');
} finally {foreach($files as $f)if(is_file($f))unlink($f);}
exit($failed?1:0);
