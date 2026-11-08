<?php
declare(strict_types=1);

const PROMO_XLSX_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

function promo_xml(string $xml): DOMDocument
{
    if (trim($xml)==='') throw new RuntimeException('Excel XML içeriği eksik.');
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) throw new RuntimeException('Excel içinde desteklenmeyen XML bildirimi var.');
    $old = libxml_use_internal_errors(true);
    try {
        $doc = new DOMDocument();
        if (!$doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) throw new RuntimeException('Excel XML içeriği okunamadı.');
        return $doc;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
}

function promo_column(int $index): string
{
    $s = '';
    for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s;
    return $s;
}

function promo_column_index(string $reference): int
{
    if (!preg_match('/^([A-Z]{1,3})[1-9][0-9]*$/D', $reference, $m)) throw new RuntimeException('Excel hücre adresi geçersiz.');
    $n = 0;
    foreach (str_split($m[1]) as $c) $n = $n * 26 + ord($c) - 64;
    if ($n > 256) throw new RuntimeException('Excel çok fazla sütun içeriyor.');
    return $n - 1;
}

function promo_header(string $s): string
{
    $s = strtr(trim($s, " \t\n\r\0\x0B\xEF\xBB\xBF"), ['İ'=>'i','I'=>'i','ı'=>'i']);
    return preg_replace('/[^a-z0-9]/', '', strtr(mb_strtolower($s, 'UTF-8'), ['ç'=>'c','ğ'=>'g','ö'=>'o','ş'=>'s','ü'=>'u']));
}

function promo_zip(string $path): ZipArchive
{
    foreach (['ZipArchive','DOMDocument','XMLReader'] as $class) {
        if (!class_exists($class)) throw new RuntimeException('Sunucuda Excel desteği etkin değil.');
    }
    if (!is_file($path) || filesize($path) > 10 * 1024 * 1024) throw new RuntimeException('Excel dosyası en fazla 10 MB olabilir.');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new RuntimeException('Geçerli bir XLSX dosyası seçin.');
    try {
        if ($zip->numFiles > 2000) throw new RuntimeException('Excel çok fazla bileşen içeriyor.');
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $s = $zip->statIndex($i);
            $total += $s['size'];
            if (str_starts_with($s['name'], '/') || str_contains($s['name'], '\\') || in_array('..', explode('/', $s['name']), true)) throw new RuntimeException('Excel bileşen yolu geçersiz.');
            if ($s['size'] > 64 * 1024 * 1024 || $total > 128 * 1024 * 1024) throw new RuntimeException('Excel açılmış boyut sınırını aşıyor.');
            if (preg_match('~(^|/)(vbaProject\.bin|encryptionInfo)$~i', $s['name'])) throw new RuntimeException('Makrolu veya şifreli dosya desteklenmiyor.');
        }
        return $zip;
    } catch (Throwable $e) { $zip->close(); throw $e; }
}

function promo_zip_xml(ZipArchive $zip, string $member): string
{
    $xml = $zip->getFromName($member);
    if ($xml === false) throw new RuntimeException('Excel çalışma kitabı eksik.');
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) throw new RuntimeException('Excel XML bildirimi desteklenmiyor.');
    return $xml;
}

/** Reads row fragments rather than building a DOM for a whole large worksheet. No ZIP extraction. */
function promo_workbook(string $path): array
{
    $zip = promo_zip($path);
    $oldErrors = libxml_use_internal_errors(true);
    try {
        $wb = promo_xml(promo_zip_xml($zip, 'xl/workbook.xml'));
        $sheet = $wb->getElementsByTagNameNS(PROMO_XLSX_NS, 'sheet')->item(0);
        if (!$sheet) throw new RuntimeException('Excel sayfası bulunamadı.');
        $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        $rels = promo_xml(promo_zip_xml($zip, 'xl/_rels/workbook.xml.rels'));
        $member = null;
        foreach ($rels->documentElement->childNodes as $rel) {
            if (!$rel instanceof DOMElement || $rel->getAttribute('Id') !== $rid) continue;
            if ($rel->getAttribute('TargetMode') === 'External') throw new RuntimeException('Dış çalışma sayfası desteklenmiyor.');
            $target = $rel->getAttribute('Target');
            if (str_contains($target, '..') || str_contains($target, '\\')) throw new RuntimeException('Excel sayfa yolu geçersiz.');
            $member = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        }
        if (!$member) throw new RuntimeException('Excel sayfa bağlantısı eksik.');
        $shared = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            $reader = new XMLReader();
            $reader->XML(promo_zip_xml($zip, 'xl/sharedStrings.xml'), null, LIBXML_NONET | LIBXML_COMPACT);
            $complete = false;
            while ($reader->read()) {
                if ($reader->localName==='sst' && ($reader->nodeType===XMLReader::END_ELEMENT || ($reader->nodeType===XMLReader::ELEMENT && $reader->isEmptyElement))) $complete=true;
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') continue;
                $node = promo_xml($reader->readOuterXml());
                $v = '';
                foreach ($node->getElementsByTagNameNS(PROMO_XLSX_NS, 't') as $t) $v .= $t->textContent;
                $shared[] = $v;
                if (count($shared) > 250000) throw new RuntimeException('Excel çok fazla metin içeriyor.');
            }
            if (!$complete || libxml_get_errors()) throw new RuntimeException('Excel metin tablosu bozuk.');
            $reader->close();
        }
        $reader = new XMLReader();
        $reader->XML(promo_zip_xml($zip, $member), null, LIBXML_NONET | LIBXML_COMPACT);
        $headers = null; $records = []; $headerRow = null; $seen = []; $complete = false;
        while ($reader->read()) {
            if ($reader->nodeType===XMLReader::END_ELEMENT && $reader->localName==='worksheet' && $reader->namespaceURI===PROMO_XLSX_NS) $complete=true;
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row' || $reader->namespaceURI !== PROMO_XLSX_NS) continue;
            $doc = promo_xml($reader->readOuterXml()); $row = $doc->documentElement;
            if (!preg_match('/^[1-9][0-9]*$/D',$row->getAttribute('r'))) throw new RuntimeException('Excel satır numarası geçersiz.');
            $number = (int)$row->getAttribute('r');
            if ($number < 1 || $number > 1048576 || isset($seen[$number])) throw new RuntimeException('Excel satır numaraları geçersiz veya tekrarlı.');
            $seen[$number] = true; $cells = []; $formulas = [];
            foreach ($row->getElementsByTagNameNS(PROMO_XLSX_NS, 'c') as $cell) {
                $ref = $cell->getAttribute('r'); $col = promo_column_index($ref);
                if ((int)preg_replace('/^[A-Z]+/', '', $ref) !== $number || array_key_exists($col, $cells)) throw new RuntimeException('Excel hücre adresleri tutarsız.');
                $type = $cell->getAttribute('t');
                $v = $cell->getElementsByTagNameNS(PROMO_XLSX_NS, 'v')->item(0)?->textContent ?? '';
                if ($type === 's') {
                    if (!preg_match('/^[0-9]+$/D',$v) || !array_key_exists((int)$v, $shared)) throw new RuntimeException('Excel metin bağlantısı geçersiz.');
                    $v = $shared[(int)$v];
                } elseif ($type === 'inlineStr') {
                    $v = '';
                    foreach ($cell->getElementsByTagNameNS(PROMO_XLSX_NS, 't') as $t) $v .= $t->textContent;
                }
                if (strlen($v) > 32767) throw new RuntimeException('Excel hücresi çok uzun.');
                $cells[$col] = $v;
                if ($cell->getElementsByTagNameNS(PROMO_XLSX_NS, 'f')->length) $formulas[] = $col;
            }
            if (!$cells || !array_filter($cells, static fn($v) => trim($v) !== '')) continue;
            if ($headers === null) {
                if (!in_array('barkod', array_map('promo_header', $cells), true)) {
                    if ($number > 20) throw new RuntimeException('Excel başlık satırı bulunamadı.');
                    continue;
                }
                $headers = array_fill(0, max(array_keys($cells)) + 1, '');
                foreach ($cells as $c => $v) $headers[$c] = $v;
                $headerRow = $number;
                continue;
            }
            $records[] = ['row'=>$number,'cells'=>$cells,'formulas'=>$formulas];
            if (count($records) > 50000) throw new RuntimeException('Bir dosyada en fazla 50.000 ürün satırı işlenebilir.');
        }
        if (!$complete || libxml_get_errors()) throw new RuntimeException('Excel çalışma sayfası bozuk.');
        $reader->close();
        if ($headers === null || !$records) throw new RuntimeException('Excel içinde başlık ve ürün satırları bulunmalı.');
        return ['path'=>$path,'sheet'=>$member,'headers'=>$headers,'header_row'=>$headerRow,'records'=>$records];
    } finally { $zip->close(); libxml_clear_errors(); libxml_use_internal_errors($oldErrors); }
}

/** Patches a single row DOM. Other rows are streamed without building a worksheet DOM. */
function promo_patch_row(string $xml, array $changes): string
{
    $doc=promo_xml($xml);$row=$doc->documentElement;$number=(int)$row->getAttribute('r');
    foreach ($changes as $column=>$value) {
        $reference=promo_column((int)$column).$number;$cell=null;$before=null;
        foreach ($row->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->localName!=='c') continue;
            $index=promo_column_index($child->getAttribute('r'));
            if ($index===$column) {$cell=$child;break;}
            if ($index>$column && !$before) $before=$child;
        }
        if (!$cell) {$cell=$doc->createElementNS(PROMO_XLSX_NS,'c');$cell->setAttribute('r',$reference);$row->insertBefore($cell,$before);}
        while ($cell->firstChild) $cell->removeChild($cell->firstChild);
        if (is_float($value)||is_int($value)) {
            $cell->removeAttribute('t');$cell->appendChild($doc->createElementNS(PROMO_XLSX_NS,'v',json_encode($value,JSON_THROW_ON_ERROR)));
        } else {
            $cell->setAttribute('t','inlineStr');$is=$doc->createElementNS(PROMO_XLSX_NS,'is');$t=$doc->createElementNS(PROMO_XLSX_NS,'t');
            $t->appendChild($doc->createTextNode((string)$value));$is->appendChild($t);$cell->appendChild($is);
        }
    }
    return $doc->saveXML($row);
}

/** Copies the ZIP and replaces its worksheet using bounded row fragments. */
function promo_export_workbook(array $book, array $changes, string $destination): void
{
    if (!class_exists('XMLWriter')) throw new RuntimeException('Sunucuda Excel desteği etkin değil.');
    $sourceXml=tempnam(sys_get_temp_dir(),'kf-promo-source-');$targetXml=tempnam(sys_get_temp_dir(),'kf-promo-sheet-');
    $reader=null;$oldErrors=libxml_use_internal_errors(true);
    try {
        $zip=promo_zip($book['path']);
        try {
            $input=$zip->getStream($book['sheet']);$output=fopen($sourceXml,'wb');
            if (!$input || !$output) throw new RuntimeException('Excel sayfası okunamadı.');
            try {if (stream_copy_to_stream($input,$output)===false) throw new RuntimeException('Excel geçici dosyası yazılamadı.');}
            finally {fclose($input);fclose($output);}
        } finally {$zip->close();}
        $reader=new XMLReader();
        if (!$reader->open($sourceXml,null,LIBXML_NONET|LIBXML_COMPACT)) throw new RuntimeException('Excel sayfası açılamadı.');
        $writer=new XMLWriter();
        if (!$writer->openUri($targetXml)) throw new RuntimeException('Excel çıktı alanı açılamadı.');
        $writer->startDocument('1.0','UTF-8');$skipDepth=null;$remaining=$changes;$complete=false;
        while ($reader->read()) {
            if ($skipDepth!==null) {
                if ($reader->nodeType===XMLReader::END_ELEMENT && $reader->depth===$skipDepth) $skipDepth=null;
                continue;
            }
            if ($reader->nodeType===XMLReader::DOC_TYPE) throw new RuntimeException('Excel XML bildirimi desteklenmiyor.');
            if ($reader->nodeType===XMLReader::ELEMENT && $reader->localName==='row' && $reader->namespaceURI===PROMO_XLSX_NS) {
                $number=(int)$reader->getAttribute('r');$outer=$reader->readOuterXml();
                $writer->writeRaw(isset($remaining[$number])?promo_patch_row($outer,$remaining[$number]):$outer);
                unset($remaining[$number]);
                if (!$reader->isEmptyElement) $skipDepth=$reader->depth;
                continue;
            }
            switch ($reader->nodeType) {
                case XMLReader::ELEMENT:
                    $empty=$reader->isEmptyElement;$writer->startElement($reader->name);
                    if ($reader->moveToFirstAttribute()) {
                        do {$writer->writeAttribute($reader->name,$reader->value);} while ($reader->moveToNextAttribute());
                        $reader->moveToElement();
                    }
                    if ($empty) $writer->endElement();
                    break;
                case XMLReader::END_ELEMENT:
                    if ($reader->localName==='worksheet' && $reader->namespaceURI===PROMO_XLSX_NS) $complete=true;
                    $writer->endElement();break;
                case XMLReader::TEXT: case XMLReader::WHITESPACE: case XMLReader::SIGNIFICANT_WHITESPACE:
                    $writer->text($reader->value);break;
                case XMLReader::CDATA: $writer->writeCdata($reader->value);break;
                case XMLReader::COMMENT: $writer->writeComment($reader->value);break;
                case XMLReader::PI: $writer->writePi($reader->name,$reader->value);break;
            }
        }
        if (!$complete || $remaining || libxml_get_errors()) throw new RuntimeException('Excel seçimi yazılamadı.');
        $writer->endDocument();
        if ($writer->flush()===false) throw new RuntimeException('Excel çıktısı tamamlanamadı.');
        unset($writer);$reader->close();$reader=null;
        if (!copy($book['path'],$destination)) throw new RuntimeException('Excel çıktısı oluşturulamadı.');
        $out=new ZipArchive();
        if ($out->open($destination)!==true) throw new RuntimeException('Excel çıktısı açılamadı.');
        try {if (!$out->addFile($targetXml,$book['sheet'])) throw new RuntimeException('Excel seçimi eklenemedi.');}
        finally {if (!$out->close()) throw new RuntimeException('Excel çıktısı tamamlanamadı.');}
    } finally {
        if ($reader) $reader->close();
        if (is_file($sourceXml)) unlink($sourceXml);
        if (is_file($targetXml)) unlink($targetXml);
        libxml_clear_errors();libxml_use_internal_errors($oldErrors);
    }
}
