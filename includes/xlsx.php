<?php
// includes/xlsx.php — minimal OOXML reader/writer (no PhpSpreadsheet).
// Melontik parity: ürettiğimiz .xlsx Melontik'in "Ürün Ayarları" Excel'iyle birebir yapıda olmalı.

function xlsx_read(string $path): array {
    // returns ['headers' => [...], 'rows' => [[...], ...]] using first sheet
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('xlsx açılamadı');
    $work = sys_get_temp_dir() . '/xlsx_' . uniqid();
    mkdir($work);
    $zip->extractTo($work);
    $zip->close();

    // shared strings
    $shared = [];
    $ss = $work . '/xl/sharedStrings.xml';
    if (is_file($ss)) {
        $xml = simplexml_load_file($ss);
        foreach ($xml->si as $si) {
            $t = '';
            if (isset($si->t)) $t = (string)$si->t;
            else foreach ($si->r as $r) $t .= (string)$r->t;
            $shared[] = $t;
        }
    }

    function colIndex($ref) {
        preg_match('/^([A-Z]+)\d+$/', $ref, $m);
        $col = 0;
        for ($i = 0; $i < strlen($m[1]); $i++) $col = $col * 26 + (ord($m[1][$i]) - 64);
        return $col;
    }

    $sheetXml = simplexml_load_file($work . '/xl/worksheets/sheet1.xml');
    $rows = [];
    foreach ($sheetXml->sheetData->row as $row) {
        $r = (int)$row['r'];
        $cells = []; $maxCol = 0;
        foreach ($row->c as $c) {
            $col = colIndex((string)$c['r']);
            $t = (string)$c['t'];
            if ($t === 's') { $v = $shared[(int)$c->v] ?? ''; }
            elseif ($t === 'inlineStr') { $v = (string)$c->is->t; }
            elseif (isset($c->v)) { $v = (string)$c->v; }
            else { $v = ''; }
            $cells[$col] = $v;
            if ($col > $maxCol) $maxCol = $col;
        }
        $arr = [];
        for ($i = 1; $i <= $maxCol; $i++) $arr[] = $cells[$i] ?? '';
        $rows[$r] = $arr;
    }

    // find header row: first row containing 'Barkod'
    $headerRow = null;
    foreach ($rows as $r => $arr) {
        foreach ($arr as $v) {
            if (is_string($v) && trim($v) === 'Barkod') { $headerRow = $r; break 2; }
        }
    }
    if ($headerRow === null) $headerRow = 4;

    $headers = $rows[$headerRow];
    $data = [];
    foreach ($rows as $r => $arr) {
        if ($r <= $headerRow) continue;
        // skip fully empty
        $allEmpty = true;
        foreach ($arr as $v) if (trim((string)$v) !== '') { $allEmpty = false; break; }
        if ($allEmpty) continue;
        $data[] = $arr;
    }

    // cleanup
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($work);

    return ['header_row' => $headerRow, 'headers' => $headers, 'rows' => $data];
}

function xlsx_write_maliyet(array $products, string $path): void {
    // Melontik birebir yapı
    $headers = ['Barkod','Model Kodu','Stok kodu','Kategori İsmi','Ürün Adı','Trendyol  Satış Fiyatı','Stok','Ürün Maliyeti ( KDV Dahil)','Maliyet KDV Oranı','Para Birimi','Ürün Desisi','Teslimat Tipi','Ekstra Maliyet (%)','Ekstra Maliyet (TL)'];

    $tmp = sys_get_temp_dir() . '/xlsxw_' . uniqid();
    mkdir($tmp);
    mkdir($tmp . '/xl');
    mkdir($tmp . '/xl/worksheets');
    mkdir($tmp . '/xl/_rels');
    mkdir($tmp . '/_rels');
    mkdir($tmp . '/docProps');
    mkdir($tmp . '/xl/theme');

    // shared strings builder
    $shared = [];
    $sharedIdx = [];
    $shstr = function ($s) use (&$shared, &$sharedIdx) {
        $s = (string)$s;
        if (!isset($sharedIdx[$s])) { $sharedIdx[$s] = count($shared); $shared[] = $s; }
        return $sharedIdx[$s];
    };

    // ---- sheet1.xml ----
    // merged cells A1:F3, G1:G3, H1:AH3
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $sheet .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" mc:Ignorable="x14ac" xmlns:x14ac="http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac">';
    $sheet .= '<dimension ref="A1:N' . (4 + count($products)) . '"/>';
    $sheet .= '<sheetViews><sheetView tabSelected="1" topLeftCell="A1" zoomScale="125" zoomScaleNormal="125" workbookViewId="0"><selection activeCell="A1" sqref="A1"/></sheetView></sheetViews>';
    $sheet .= '<sheetFormatPr defaultColWidth="8.85546875" defaultRowHeight="15" x14ac:dyDescent="0.25"/>';
    $sheet .= '<cols>';
    $sheet .= '<col min="1" max="5" width="33.28515625" customWidth="1"/>';
    $sheet .= '<col min="6" max="6" width="39.42578125" customWidth="1"/>';
    $sheet .= '<col min="7" max="7" width="21.140625" customWidth="1"/>';
    $sheet .= '<col min="8" max="8" width="31" customWidth="1"/>';
    $sheet .= '<col min="9" max="9" width="21.42578125" customWidth="1"/>';
    $sheet .= '<col min="10" max="12" width="15" customWidth="1"/>';
    $sheet .= '<col min="13" max="13" width="20.7109375" bestFit="1" customWidth="1"/>';
    $sheet .= '<col min="14" max="14" width="21.140625" bestFit="1" customWidth="1"/>';
    $sheet .= '</cols>';
    $sheet .= '<sheetData>';

    // row 1: banner merged A1:F3, G1:G3, H1:AH3 (34 cols)
    $banner = 'Sadece sarı ile işaretli alanlar değiştirilebilir alanlardır, diğer alanlarda değişiklik yapmamalısınız. Ürün desi bilgileri tahmini değer olarak hesaplanmaktadır, eğer  farklı olduğunu düşündüğünüz bir desi varsa güncelleme yapabilirsiniz.                                                   Girilen değer kargo paketinin değil ürünün desi bilgisi olmalıdır.  Maliyet bilgisi eksik ürünlerden  gelen siparişlerin karlılığı hesaplanamaz. Bu nedenle maliyetlerinizi eksiksiz şekilde sisteme yüklediğinizden emin olun. ';
    $siBanner = $shstr($banner);
    $sheet .= '<row r="1" spans="1:34" s="1" customFormat="1" ht="15" customHeight="1" x14ac:dyDescent="0.25">';
    $sheet .= '<c r="A1" s="4" t="s"><v>' . $siBanner . '</v></c>';
    for ($col = 2; $col <= 6; $col++) {
        $L = chr(64 + $col);
        $sheet .= '<c r="' . $L . '1" s="5"/>';
    }
    $sheet .= '<c r="G1" s="4"/>';
    $sheet .= '<c r="H1" s="6"/>';
    for ($col = 9; $col <= 34; $col++) {
        $L = $col <= 26 ? chr(64 + $col) : 'A' . chr(64 + ($col - 26));
        $sheet .= '<c r="' . $L . '1" s="5"/>';
    }
    $sheet .= '</row>';
    // rows 2,3 empty styled (34 cols)
    for ($rr = 2; $rr <= 3; $rr++) {
        $sheet .= '<row r="' . $rr . '" spans="1:34" s="1" customFormat="1" ht="15.75" customHeight="1" x14ac:dyDescent="0.25">';
        $sheet .= '<c r="A' . $rr . '" s="5"/>';
        for ($col = 2; $col <= 34; $col++) {
            $L = $col <= 26 ? chr(64 + $col) : 'A' . chr(64 + ($col - 26));
            $sheet .= '<c r="' . $L . $rr . '" s="5"/>';
        }
        $sheet .= '</row>';
    }

    // row 4 header
    $sheet .= '<row r="4" spans="1:34" ht="18.75" customHeight="1" x14ac:dyDescent="0.25">';
    foreach ($headers as $i => $h) {
        $col = $i + 1;
        $L = $col <= 26 ? chr(64+$col) : 'A' . chr(64 + ($col-26));
        $si = $shstr($h);
        // s=2 for first 7 cols (F7CAAC), s=3 for last 7 (FFFF00)
        $style = ($col <= 7) ? 2 : 3;
        $sheet .= '<c r="' . $L . '4" s="' . $style . '" t="s"><v>' . $si . '</v></c>';
    }
    $sheet .= '</row>';

    // data rows
    $r = 5;
    foreach ($products as $p) {
        $desi = (float)($p['kesilen_desi'] ?? 0);
        if ($desi <= 0) $desi = desi_from_dims((float)($p['en'] ?? 0), (float)($p['boy'] ?? 0), (float)($p['yukseklik'] ?? 0));
        $cargoType = (!empty($p['cargo_type']) && $p['cargo_type'] !== 'seller') ? $p['cargo_type'] : 'standart';
        $vals = [
            (string)($p['barcode'] ?? ''),
            (string)($p['model_kodu'] ?? ''),
            '', // stok kodu
            (string)($p['category'] ?? ''),
            (string)($p['name'] ?? ''),
            (float)($p['price'] ?? 0),
            (int)($p['quantity'] ?? 0),
            (float)($p['cost'] ?? 0) > 0 ? (float)$p['cost'] : null,
            (float)($p['kdv'] ?? 0) > 0 ? (float)$p['kdv'] : 20,
            'TRY',
            $desi > 0 ? (float)round($desi, 2) : null,
            $cargoType,
            0,
            (float)($p['extra_fee'] ?? 0),
        ];
        $sheet .= '<row r="' . $r . '" spans="1:14" ht="13.5" customHeight="1" x14ac:dyDescent="0.25">';
        foreach ($vals as $i => $v) {
            $col = $i + 1;
            $L = $col <= 26 ? chr(64+$col) : 'A' . chr(64 + ($col-26));
            if ($v === null || $v === '') {
                $sheet .= '<c r="' . $L . $r . '"/>';
                continue;
            }
            if (is_string($v)) {
                $si = $shstr($v);
                $sheet .= '<c r="' . $L . $r . '" t="s"><v>' . $si . '</v></c>';
            } elseif (is_int($v)) {
                $sheet .= '<c r="' . $L . $r . '"><v>' . $v . '</v></c>';
            } else {
                // float
                $sheet .= '<c r="' . $L . $r . '"><v>' . rtrim(rtrim(sprintf('%.10g', $v), '0'), '.') . '</v></c>';
            }
        }
        $sheet .= '</row>';
        $r++;
    }
    $sheet .= '</sheetData>';
    // mergeCells
    $sheet .= '<mergeCells count="3"><mergeCell ref="A1:F3"/><mergeCell ref="G1:G3"/><mergeCell ref="H1:AH3"/></mergeCells>';
    // dataValidations
    $lastDataRow = 4 + count($products);
    $sheet .= '<dataValidations count="3">';
    $sheet .= '<dataValidation type="list" operator="equal" showErrorMessage="1" prompt="Maliyet para birimi" sqref="J1:J3 J5:J' . $lastDataRow . '"><formula1>"TRY,USD"</formula1><formula2>0</formula2></dataValidation>';
    $sheet .= '<dataValidation operator="equal" showDropDown="1" showErrorMessage="1" prompt="Maliyet para birimi" sqref="J4"><formula1>0</formula1><formula2>0</formula2></dataValidation>';
    $sheet .= '<dataValidation type="list" showInputMessage="1" showErrorMessage="1" sqref="L1:L3 L5:L1048576"><formula1>"standart,hızlı teslimat,bugün kargoda"</formula1></dataValidation>';
    $sheet .= '</dataValidations>';
    // pageMargins + pageSetup
    $sheet .= '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.511811023622047" footer="0.511811023622047"/>';
    $sheet .= '<pageSetup paperSize="9" orientation="portrait" horizontalDpi="300" verticalDpi="300"/>';
    $sheet .= '</worksheet>';

    file_put_contents($tmp . '/xl/worksheets/sheet1.xml', $sheet);

    // ---- sharedStrings.xml ----
    $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $ssXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">';
    foreach ($shared as $str) {
        $ssXml .= '<si><t xml:space="preserve">' . htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
    }
    $ssXml .= '</sst>';
    file_put_contents($tmp . '/xl/sharedStrings.xml', $ssXml);

    // ---- styles.xml ----
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $styles .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" mc:Ignorable="x14ac" xmlns:x14ac="http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac">';
    $styles .= '<fonts count="6" x14ac:knownFonts="1">';
    $styles .= '<font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/><family val="2"/><charset val="1"/></font>';
    $styles .= '<font><b/><sz val="12"/><color rgb="FFFF0000"/><name val="Calibri"/><family val="2"/><charset val="1"/></font>';
    $styles .= '<font><b/><sz val="11"/><color rgb="FFFF0000"/><name val="Calibri"/><family val="2"/><charset val="1"/></font>';
    $styles .= '<font><sz val="11"/><color rgb="FFFF0000"/><name val="Calibri"/><family val="2"/><charset val="1"/></font>';
    $styles .= '<font><b/><sz val="14"/><color rgb="FF000000"/><name val="Calibri"/><family val="2"/><charset val="1"/></font>';
    $styles .= '<font><sz val="11"/><color indexed="8"/><name val="Calibri"/><family val="2"/></font>';
    $styles .= '</fonts>';
    $styles .= '<fills count="4">';
    $styles .= '<fill><patternFill patternType="none"/></fill>';
    $styles .= '<fill><patternFill patternType="gray125"/></fill>';
    $styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FFF7CAAC"/><bgColor rgb="FFC0C0C0"/></patternFill></fill>';
    $styles .= '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFF00"/><bgColor rgb="FFFFFF00"/></patternFill></fill>';
    $styles .= '</fills>';
    $styles .= '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>';
    $styles .= '<cellStyleXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="5" fillId="0" borderId="0"/></cellStyleXfs>';
    $styles .= '<cellXfs count="7">';
    $styles .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
    $styles .= '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment wrapText="1" shrinkToFit="1"/></xf>';
    $styles .= '<xf numFmtId="0" fontId="4" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>';
    $styles .= '<xf numFmtId="0" fontId="4" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>';
    $styles .= '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1" shrinkToFit="1"/></xf>';
    $styles .= '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment wrapText="1" shrinkToFit="1"/></xf>';
    $styles .= '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1" shrinkToFit="1"/></xf>';
    $styles .= '</cellXfs>';
    $styles .= '<cellStyles count="2"><cellStyle name="Normal" xfId="0" builtinId="0"/><cellStyle name="Normal 2" xfId="1"/></cellStyles>';
    $styles .= '<dxfs count="0"/>';
    $styles .= '<tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>';
    $styles .= '<extLst><ext uri="{EB79DEF2-80B8-43e5-95BD-54CBDDF9020C}" xmlns:x14="http://schemas.microsoft.com/office/spreadsheetml/2009/9/main"><x14:slicerStyles defaultSlicerStyle="SlicerStyleLight1"/></ext><ext uri="{9260A510-F301-46a8-8635-F512D64BE5F5}" xmlns:x15="http://schemas.microsoft.com/office/spreadsheetml/2010/11/main"><x15:timelineStyles defaultTimelineStyle="TimeSlicerStyleLight1"/></ext></extLst>';
    $styles .= '</styleSheet>';
    file_put_contents($tmp . '/xl/styles.xml', $styles);

    // ---- workbook.xml ----
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $wb .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" mc:Ignorable="x15" xmlns:x15="http://schemas.microsoft.com/office/spreadsheetml/2010/11/main">';
    $wb .= '<fileVersion appName="xl" lastEdited="6" lowestEdited="6" rupBuild="14420"/>';
    $wb .= '<workbookPr/>';
    $wb .= '<bookViews><workbookView xWindow="765" yWindow="765" windowWidth="22500" windowHeight="14760" tabRatio="500"/></bookViews>';
    $wb .= '<sheets><sheet name="Ürünler" sheetId="1" r:id="rId1"/></sheets>';
    $wb .= '<calcPr calcId="162913"/>';
    $wb .= '</workbook>';
    file_put_contents($tmp . '/xl/workbook.xml', $wb);

    // ---- rels ----
    file_put_contents($tmp . '/xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
    file_put_contents($tmp . '/_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');

    // ---- docProps ----
    file_put_contents($tmp . '/docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Microsoft Excel</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop><HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Çalışma Sayfaları</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs><TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Ürünler</vt:lpstr></vt:vector></TitlesOfParts><LinksUpToDate>false</LinksUpToDate><SharedDoc>false</SharedDoc><HyperlinksChanged>false</HyperlinksChanged><AppVersion>16.0300</AppVersion></Properties>');
    file_put_contents($tmp . '/docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>Apache POI</dc:creator><cp:lastModifiedBy>Berke Biçer</cp:lastModifiedBy><cp:revision>3</cp:revision><dcterms:created xsi:type="dcterms:W3CDTF">2024-03-01T10:52:52Z</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">2026-10-06T12:31:25Z</dcterms:modified><dc:language>tr-TR</dc:language></cp:coreProperties>');

    // theme (copy minimal? Melontik has theme1.xml; Excel can open without theme but safer to include a default)
    // We'll include an empty-ish theme referencing default. Actually Excel requires theme for some features but opens fine. Include a minimal theme.
    file_put_contents($tmp . '/xl/theme/theme1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Office Theme"><a:themeElements><a:clrScheme name="Office"><a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1><a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1><a:dk2><a:srgbClr val="44546A"/></a:dk2><a:lt2><a:srgbClr val="E7E6E6"/></a:lt2><a:accent1><a:srgbClr val="4472C4"/></a:accent1><a:accent2><a:srgbClr val="ED7D31"/></a:accent2><a:accent3><a:srgbClr val="A5A5A5"/></a:accent3><a:accent4><a:srgbClr val="FFC000"/></a:accent4><a:accent5><a:srgbClr val="5B9BD5"/></a:accent5><a:accent6><a:srgbClr val="70AD47"/></a:accent6><a:hlink><a:srgbClr val="0563C1"/></a:hlink><a:folHlink><a:srgbClr val="954F72"/></a:folHlink></a:clrScheme><a:fontScheme name="Office"><a:majorFont><a:latin typeface="Calibri Light"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont><a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme><a:fmtScheme name="Office"><a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst><a:lnStyleLst><a:ln w="9525"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln></a:lnStyleLst><a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst><a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst></a:fmtScheme></a:themeElements></a:theme>');

    // ---- [Content_Types].xml ----
    file_put_contents($tmp . '/[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>');

    // ---- zip ----
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($files as $file) {
        if (!$file->isDir()) {
            $rel = substr($file->getRealPath(), strlen($tmp) + 1);
            $rel = str_replace('\\', '/', $rel);
            $zip->addFile($file->getRealPath(), $rel);
        }
    }
    $zip->close();

    // cleanup
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($tmp);
}
