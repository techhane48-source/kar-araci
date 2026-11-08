<?php
declare(strict_types=1);
require_once __DIR__ . '/promotion_xlsx.php';
require_once __DIR__ . '/profit.php';

function promo_schema(array $book, string $kind): array
{
    $map = [];
    foreach ($book['headers'] as $i => $h) {
        $key = promo_header($h);
        if ($key === '') continue;
        if (isset($map[$key])) throw new RuntimeException('Excel aynı başlığı birden fazla içeriyor: ' . $h);
        $map[$key] = $i;
    }
    $schemas = [
        'komisyon'=>['BARKOD','1.Fiyat Alt Limit','2.Fiyat Üst Limiti','2.Fiyat Alt Limit','3.Fiyat Üst Limiti','3.Fiyat Alt Limit','4.Fiyat Üst Limiti','1.KOMİSYON','2.KOMİSYON','3.KOMİSYON','4.KOMİSYON','YENİ TSF (FİYAT GÜNCELLE)'],
        'etiket'=>['BARKOD','KOMİSYON TARİFESİ','1 YILDIZ ÜST FİYAT','1 YILDIZ ALT FİYAT','2 YILDIZ ÜST FİYAT','2 YILDIZ ALT FİYAT','3 YILDIZ ÜST FİYAT','YENİ TSF (FİYAT GÜNCELLE)'],
        'flash'=>['Barkod','Mevcut Komisyon','Güncellenecek Fiyat','24 Saat Fiyat','3 Saat Fiyat','24 Saat Flaş Başlangıç Tarihi','24 Saat Flaş Bitiş Tarihi','3 Saat Flaş Başlangıç Tarihi','3 Saat Flaş Bitiş Tarihi','Ürün Komisyon Tarife Seçeneği'],
    ];
    if (!isset($schemas[$kind])) throw new RuntimeException('Bu promosyon dosyası türü henüz desteklenmiyor.');
    foreach ($schemas[$kind] as $name) if (!isset($map[promo_header($name)])) throw new RuntimeException('Seçilen modül ile Excel şeması uyuşmuyor. Eksik başlık: ' . $name);
    return $map;
}

function promo_cell(array $row, array $map, string $name): string
{
    $i = $map[promo_header($name)] ?? null;
    if ($i === null) return '';
    if (in_array($i, $row['formulas'], true)) throw new RuntimeException('Hesaplama alanlarında formül yerine sabit değer içeren Trendyol dosyasını kullanın.');
    return trim((string)($row['cells'][$i] ?? ''));
}

function promo_number(string $raw, bool $rate = false): ?float
{
    $s = str_replace(["\xc2\xa0",' '], '', trim($raw));
    if ($s === '') return null;
    if ($rate && str_ends_with($s,'%')) $s = substr($s,0,-1);
    if (str_contains($s, ',')) {
        if (!preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:\.[0-9]{3})+),[0-9]+$/D', $s)) throw new RuntimeException('Excel tutar biçimi geçersiz.');
        $s = str_replace(',', '.', str_replace('.', '', $s));
    }
    if (!preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $s)) throw new RuntimeException('Excel sayısal alanında geçersiz değer var.');
    $n = (float)$s;
    if (!is_finite($n) || $n > PROFIT_MAX_AMOUNT || ($rate && $n > 100)) throw new RuntimeException('Excel tutarı veya komisyon oranı sınır dışında.');
    if (!$rate && abs($n-round($n,2))>1e-7) throw new RuntimeException('Teklif tutarı en fazla iki ondalık basamak içermeli.');
    return $rate?$n:round($n,2);
}

function promo_time(string $s): int
{
    $zone = new DateTimeZone('Europe/Istanbul');
    foreach (['Y-m-d\TH:i','d/m/Y H:i','d.m.Y H:i','Y-m-d H:i','d/m/Y H:i:s'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, trim($s), $zone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) && $date->format($format) === trim($s)) {
            if ((int)$date->format('Y') < 2000 || (int)$date->format('Y') > 2100) break;
            return $date->getTimestamp();
        }
    }
    throw new RuntimeException('Tarih biçimi geçersiz; dosyada gün/ay/yıl saat:dakika bulunmalı.');
}

function promo_range(string $start, string $end): array
{
    $range = ['start'=>promo_time($start),'end'=>promo_time($end) + 59];
    if ($range['end'] <= $range['start']) throw new RuntimeException('Tarife bitişi başlangıçtan sonra olmalı.');
    return $range;
}

function promo_required_number(array $row, array $map, string $name, bool $rate = false): float
{
    $n = promo_number(promo_cell($row, $map, $name), $rate);
    if ($n === null) throw new RuntimeException('Eksik alan: ' . $name);
    return $n;
}

function promo_tariff(array $book, array $range): array
{
    $map = promo_schema($book, 'komisyon'); $index = [];
    $months = ['ocak'=>1,'subat'=>2,'mart'=>3,'nisan'=>4,'mayis'=>5,'haziran'=>6,'temmuz'=>7,'agustos'=>8,'eylul'=>9,'ekim'=>10,'kasim'=>11,'aralik'=>12];
    foreach ($book['records'] as $row) {
        $label = promo_cell($row,$map,'Tarih aralığı (7 Gün)');
        if ($label !== '' && preg_match('/^(\d{1,2})\s+(\p{L}+)\s+(\d{2})[.:](\d{2})\s*[-–]\s*(\d{1,2})\s+(\p{L}+)\s+(\d{2})[.:](\d{2})$/u',$label,$m)) {
            $a=$months[promo_header($m[2])]??0;$b=$months[promo_header($m[6])]??0;
            $zone=new DateTimeZone('Europe/Istanbul');
            $start=(new DateTimeImmutable('@'.$range['start']))->setTimezone($zone)->format('j-n H:i');
            $end=(new DateTimeImmutable('@'.$range['end']))->setTimezone($zone)->format('j-n H:i');
            if ($start!==(int)$m[1].'-'.$a.' '.$m[3].':'.$m[4] || $end!==(int)$m[5].'-'.$b.' '.$m[7].':'.$m[8]) throw new RuntimeException('Girilen tarihler Excel’deki tarife gün ve saatleriyle uyuşmuyor.');
        }
        $barcode = promo_cell($row, $map, 'BARKOD');
        if ($barcode === '') continue;
        if (isset($index[$barcode])) throw new RuntimeException('Komisyon dosyasında tekrarlı barkod var; tarife eşleştirilemiyor.');
        $entry = ['range'=>$range,'tiers'=>[],'error'=>null];
        try {
            $bounds = [
                [promo_required_number($row,$map,'1.Fiyat Alt Limit'),null],
                [promo_required_number($row,$map,'2.Fiyat Alt Limit'),promo_required_number($row,$map,'2.Fiyat Üst Limiti')],
                [promo_required_number($row,$map,'3.Fiyat Alt Limit'),promo_required_number($row,$map,'3.Fiyat Üst Limiti')],
                [0.0,promo_required_number($row,$map,'4.Fiyat Üst Limiti')],
            ];
            foreach ($bounds as $i => [$min,$max]) {
                if ($max !== null && $min > $max) throw new RuntimeException('Tarife fiyat sınırları ters.');
                $entry['tiers'][] = ['key'=>(string)($i+1),'min'=>$min,'max'=>$max,'price'=>$i===0?$min:$max,
                    'rate'=>promo_required_number($row,$map,($i+1).'.KOMİSYON',true)];
            }
            for ($i=1;$i<4;$i++) if ($bounds[$i][1] >= $bounds[$i-1][0]) throw new RuntimeException('Tarife fiyat aralıkları çakışıyor.');
        } catch (RuntimeException $e) { $entry['error'] = $e->getMessage(); }
        $index[$barcode] = $entry;
    }
    return $index;
}

/** A tariff must cover the entire offer. Partial time overlap is not silently rounded to days. */
function promo_rate(?array $tariff, float $price, array $period, ?float $baseRate, bool $needsTariff): array
{
    if ($tariff) {
        if ($tariff['error']) throw new RuntimeException($tariff['error']);
        $r = $tariff['range'];
        $overlap = $r['start'] <= $period['end'] && $r['end'] >= $period['start'];
        if ($overlap && ($r['start'] > $period['start'] || $r['end'] < $period['end'])) throw new RuntimeException('Komisyon tarifesi teklif dönemini kısmen kapsıyor; dönemleri ayırarak kontrol edin.');
        if ($overlap) {
            foreach ($tariff['tiers'] as $t) if ($price >= $t['min'] - 1e-7 && ($t['max'] === null || $price <= $t['max'] + 1e-7)) return ['rate'=>$t['rate'],'source'=>'Dönem komisyon tarifesi'];
            throw new RuntimeException('Fiyat komisyon tarifesindeki aralıklara uymuyor.');
        }
        return ['rate'=>$tariff['tiers'][0]['rate'],'source'=>'Tarife dönemi dışı temel komisyon'];
    }
    if ($needsTariff) throw new RuntimeException('Bu teklif için geçerli ürün komisyon tarifesini de yükleyin.');
    if ($baseRate === null || $baseRate < 0 || $baseRate > 100) throw new RuntimeException('Ürünün temel komisyonu eksik veya geçersiz.');
    return ['rate'=>$baseRate,'source'=>'Kayıtlı temel komisyon'];
}

function promo_offer(array $p, float $price, array $rate, string $label, array $period, int $now): array
{
    if ($price <= 0) throw new RuntimeException('Teklif fiyatı sıfırdan büyük olmalı.');
    if ($period['end'] < $now) throw new RuntimeException('Teklifin geçerlilik süresi dolmuş.');
    $product = $p; $product['rate'] = $rate['rate'];
    $profit = calc_product_profit($product, $price);
    if (!$profit['ok']) throw new RuntimeException($profit['error'] ?? 'Ürün maliyeti eksik.');
    return ['label'=>$label,'price'=>$price,'rate'=>$rate['rate'],'rate_source'=>$rate['source'],'period'=>$period,'profit'=>$profit['profit'],'margin'=>$profit['margin']];
}

/** Returns source row identities, never barcode identities, for selectable offers. */
function promo_analyze(array $book, string $kind, array $products, ?array $period, array $tariffs = [], ?int $now = null): array
{
    $map = promo_schema($book,$kind); $now ??= time(); $results = [];
    foreach ($book['records'] as $row) {
        $result = ['row'=>$row['row'],'barcode'=>'','name'=>'','offers'=>[],'warnings'=>[],'error'=>null];
        try {
            $barcode = promo_cell($row,$map,'BARKOD'); $result['barcode'] = $barcode;
            $p = $products[$barcode] ?? null;
            if (!empty($p['ambiguous'])) throw new RuntimeException('Barkod birden fazla kayıtlı ürünle eşleşiyor; ürün kayıtlarını düzeltin.');
            $result['name'] = $p['name'] ?? promo_cell($row,$map,$kind==='flash'?'Ürün Adı':'ÜRÜN İSMİ');
            if (!$p) throw new RuntimeException('Ürün bulunamadı.');
            if ((float)($p['cost']??0) <= 0) throw new RuntimeException('Ürün maliyeti eksik.');
            $promo = promo_cell($row,$map,'Güncel Uygulanan Promosyonlar');
            if ($promo !== '' && !in_array(mb_strtolower($promo),['yok','-'],true)) $result['warnings'][] = 'Mevcut kupon/kampanyalar bu tahmine dahil değil.';
            $baseRate = isset($p['rate']) && is_numeric($p['rate']) ? (float)$p['rate'] : null;
            if ($kind === 'komisyon') {
                $tariff = $tariffs[$barcode] ?? null;
                if (!$tariff || $tariff['error']) throw new RuntimeException($tariff['error'] ?? 'Komisyon tarifesi okunamadı.');
                foreach ($tariff['tiers'] as $t) {
                    try { $result['offers'][$t['key']] = promo_offer($p,$t['price'],['rate'=>$t['rate'],'source'=>'Excel kademe komisyonu'], $t['key'].'. fiyat aralığı',$period,$now); }
                    catch (RuntimeException $e) { $result['warnings'][] = $t['key'].'. kademe: '.$e->getMessage(); }
                }
            } elseif ($kind === 'etiket') {
                $flag=promo_header(promo_cell($row,$map,'KOMİSYON TARİFESİ'));
                if (!in_array($flag,['var','yok'],true)) throw new RuntimeException('Komisyon tarifesi Var/Yok bilgisi geçersiz.');
                $needs = $flag === 'var';
                foreach (['1'=>'Avantajlı','2'=>'Çok avantajlı','3'=>'Süper avantajlı'] as $key=>$label) {
                    try {
                        $price = promo_required_number($row,$map,$key.' YILDIZ ÜST FİYAT');
                        if ((string)$key!=='3' && promo_required_number($row,$map,$key.' YILDIZ ALT FİYAT')>$price) throw new RuntimeException('Yıldız fiyat sınırları ters.');
                        $rate = promo_rate($tariffs[$barcode]??null,$price,$period,$baseRate,$needs);
                        $result['offers'][$key] = promo_offer($p,$price,$rate,$label,$period,$now);
                    } catch (RuntimeException $e) { $result['warnings'][] = $label.': '.$e->getMessage(); }
                }
            } else {
                $flag = promo_header(promo_cell($row,$map,'Ürün Komisyon Tarife Seçeneği'));
                if (!in_array($flag,['var','yok'],true)) throw new RuntimeException('Komisyon tarifesi Var/Yok bilgisi geçersiz.');
                foreach (['24'=>'24 Saat','3'=>'3 Saat'] as $key=>$label) {
                    try {
                        $raw = promo_cell($row,$map,$label.' Fiyat');
                        if ($raw === '') continue;
                        $price = promo_number($raw);
                        $offerPeriod = promo_range(promo_cell($row,$map,$label.' Flaş Başlangıç Tarihi'),promo_cell($row,$map,$label.' Flaş Bitiş Tarihi'));
                        if ($offerPeriod['end']-$offerPeriod['start']>(int)$key*3600-1) throw new RuntimeException('Flaş teklif süresi '.$key.' saati aşamaz.');
                        $rate = promo_rate($tariffs[$barcode]??null,$price,$offerPeriod,$baseRate,$flag==='var');
                        $result['offers'][$key] = promo_offer($p,$price,$rate,$label,$offerPeriod,$now);
                    } catch (RuntimeException $e) { $result['warnings'][] = $label.': '.$e->getMessage(); }
                }
            }
            if (!$result['offers']) $result['error'] = $result['warnings'][0] ?? 'Geçerli teklif bulunamadı.';
        } catch (RuntimeException $e) { $result['error'] = $e->getMessage(); }
        $results[$row['row']] = $result;
    }
    return ['rows'=>$results,'map'=>$map,'kind'=>$kind];
}

function promo_export_changes(array $analysis, array $choices): array
{
    $changes = []; $kind = $analysis['kind'];
    $column = $analysis['map'][promo_header($kind==='flash'?'Güncellenecek Fiyat':'YENİ TSF (FİYAT GÜNCELLE)')];
    foreach ($choices as $row=>$key) {
        if ($key === '') continue;
        $offer = $analysis['rows'][$row]['offers'][$key] ?? null;
        if (!$offer) throw new RuntimeException('Seçim artık geçerli değil. Sayfayı yenileyin; gerekirse tüm taslak seçimleri temizleyip yeniden seçin.');
        $changes[$row][$column] = $kind==='flash' ? $offer['label'] : round($offer['price'],2);
    }
    if (!$changes) throw new RuntimeException('İndirmek için en az bir teklif seçin.');
    return $changes;
}
