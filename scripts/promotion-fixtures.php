<?php
declare(strict_types=1);
/** Synthetic vendor-contract workbooks. No seller/account data. */
function promotion_fixture(string $kind,array $data,?string $path=null): string {
    $schemas=json_decode(file_get_contents(__DIR__.'/../docs/trendyol-promosyon-dosya-semalari.json'),true,512,JSON_THROW_ON_ERROR);
    $schema=array_values(array_filter($schemas,fn($s)=>$s['kind']===$kind))[0];
    $headers=$schema['headers'];$headers[]='Kaynak notu';
    $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="'.PROMO_XLSX_NS.'"><sheetData>';
    foreach (array_merge([array_combine($headers,$headers)],$data) as $i=>$row) {
        $xml.='<row r="'.($i+1).'">';
        foreach ($headers as $c=>$header) {
            $v=$row[$header]??'';
            if ($v==='') continue;
            $ref=promo_column($c).($i+1);
            if (is_int($v)||is_float($v)) $xml.='<c r="'.$ref.'" s="0"><v>'.json_encode($v,JSON_THROW_ON_ERROR).'</v></c>';
            else $xml.='<c r="'.$ref.'" s="0" t="inlineStr"><is><t>'.htmlspecialchars($v,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>';
        }
        $xml.='</row>';
    }
    $xml.='</sheetData><autoFilter ref="A1:'.promo_column(count($headers)-1).(count($data)+1).'"/></worksheet>';
    $path??=tempnam(sys_get_temp_dir(),'kf-promo-fixture-');$zip=new ZipArchive();$zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $zip->addFromString('xl/worksheets/offers.xml',$xml);
    $zip->addFromString('xl/workbook.xml','<workbook xmlns="'.PROMO_XLSX_NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Teklifler" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/offers.xml"/></Relationships>');
    $zip->addFromString('_rels/.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('[Content_Types].xml','<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/offers.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('docProps/custom.xml','<source>Unchanged opaque source metadata</source>');$zip->close();return $path;
}
function promotion_fixture_commission(): array {return ['BARKOD'=>'0001','ÜRÜN İSMİ'=>'Sentetik perde','1.Fiyat Alt Limit'=>700,'2.Fiyat Üst Limiti'=>699.99,'2.Fiyat Alt Limit'=>500,'3.Fiyat Üst Limiti'=>499.99,'3.Fiyat Alt Limit'=>300,'4.Fiyat Üst Limiti'=>299.99,'1.KOMİSYON'=>21,'2.KOMİSYON'=>15,'3.KOMİSYON'=>10,'4.KOMİSYON'=>5,'GÜNCEL TSF'=>888,'KOMİSYONA ESAS FİYAT'=>777,'Tarih aralığı (7 Gün)'=>'5 Kasım 08.00-12 Kasım 07.59','Kaynak notu'=>'Özgün & <değer>' ];}
function promotion_fixture_star(): array {return ['BARKOD'=>'0001','ÜRÜN İSMİ'=>'Sentetik perde','KOMİSYON TARİFESİ'=>'Var','1 YILDIZ ÜST FİYAT'=>'699,99','1 YILDIZ ALT FİYAT'=>'500,00','2 YILDIZ ÜST FİYAT'=>'499,99','2 YILDIZ ALT FİYAT'=>'300,00','3 YILDIZ ÜST FİYAT'=>'299,99','TRENDYOL SATIŞ FİYATI'=>888];}
function promotion_fixture_flash(string $day='08'): array {return ['Barkod'=>'0001','Ürün Adı'=>'Sentetik perde','Mevcut Fiyat'=>888,'Mevcut Komisyon'=>21,'24 Saat Fiyat'=>600,'24 Saat Flaş Başlangıç Tarihi'=>$day.'/11/2050 00:00','24 Saat Flaş Bitiş Tarihi'=>$day.'/11/2050 23:59','Ürün Komisyon Tarife Seçeneği'=>'Var'];}
