<?php
declare(strict_types=1);
/**
 * Trendyol fiyat/komisyon tarifesi Excel'leri (Satıcı Paneli → Promosyon & Fiyat).
 *
 * Panelde "Excel ile Güncelle → Excel Oluştur" ile indirilen dosyaların yapısı (27.11.2026, gerçek hesaptan):
 *  - Ürün Komisyon Tarifeleri  (pricing/rate-card): 4 fiyat aralığı, her aralığın 3 gün ve 4 gün için ayrı komisyonu.
 *    Aralıklar "Komisyona Esas Fiyat" üzerinden (müşterinin gördüğü, satıcı indirimleri düşülmüş fiyat).
 *    Geri yükleme: "YENİ TSF (FİYAT GÜNCELLE)" + "Tarife Seçimi" (3 Günlük / 4 Günlük / 7 Günlük Fiyat).
 *  - Plus Komisyon Tarifeleri  (pricing/ty-plus): tek Plus fiyat üst limiti + 3 gün / 4 gün Plus komisyon teklifi.
 *    Geri yükleme: "Plus Fiyat Seçimi" + "Tarife Seçimi".
 *  - Avantajlı Ürün Etiketleri (pricing/badge): 1/2/3 yıldız üst fiyatları, komisyon değişmez.
 *    Geri yükleme: "YENİ TSF (FİYAT GÜNCELLE)".
 *  - Flaş Ürünler (pricing/flash): 24 saat ve 3 saat flaş fiyatı, mevcut komisyon.
 *    Geri yükleme: "Senin Belirlediğin Flaş Fiyatı".
 *
 * Analiz her seçenek için kârı, siparişlerde kullanılan motorla (compute_order_profit) hesaplar: ürünün KDV'si,
 * maliyet KDV'si, seçeneğin kendi komisyonu, fiyata göre kargo/barem, hizmet bedeli, stopaj.
 * Dışa aktarma kullanıcının yüklediği dosyanın kendisini değiştirir: yalnızca seçilen satırların geri yükleme
 * hücreleri doldurulur, Trendyol'un beklediği yapı (kolonlar, gizli ID'ler, tarife grubu) olduğu gibi kalır.
 */

require_once __DIR__ . '/engine.php';

const TARIFF_TYPES = [
    'komisyon' => 'Ürün Komisyon Tarifesi',
    'plus'     => 'Plus Komisyon Tarifesi',
    'etiket'   => 'Avantajlı Ürün Etiketi',
    'flas'     => 'Flaş Ürünler',
    'kampanya' => 'Kampanyalar',
];

/** Komisyon aralıkları bu kadar gün sonra eskimiş sayılır (Trendyol tarifeleri haftalık). */
const TARIFF_BAND_MAX_AGE_DAYS = 10;

/** "A" → 1, "AE" → 31 */
function xl_col_index(string $letters): int
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n;
}

function xl_col_letter(int $n): string
{
    $s = '';
    while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); }
    return $s;
}

/** Başlık metnini karşılaştırma için sadeleştirir: küçük harf, Türkçe harfler katlanmış, yalnız harf/rakam. */
function tariff_norm(string $s): string
{
    $s = strtr($s, ['İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ç' => 'c', 'ç' => 'c', 'Ğ' => 'g', 'ğ' => 'g',
                    'Ö' => 'o', 'ö' => 'o', 'Ş' => 's', 'ş' => 's', 'Ü' => 'u', 'ü' => 'u']);
    return preg_replace('/[^a-z0-9]/', '', strtolower($s));
}

/** "682,88" / "1.234,50" / "799.05" / "1234.0" → float; boş/"-" → null */
function tariff_num($v): ?float
{
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || $s === '-') return null;
    $s = str_replace(['₺', 'TL', ' ', '%'], '', $s);
    if (str_contains($s, ',')) $s = str_replace(['.', ','], ['', '.'], $s);
    return is_numeric($s) ? (float)$s : null;
}

/**
 * xlsx'in ilk sayfasını okur. ['rows' => [satırNo => [kolonHarfi => değer]], 'sheet' => zip içindeki yol]
 */
function xlsx_cells(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Excel dosyası açılamadı.');
    $sheetPath = xlsx_first_sheet_path($zip);
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $x = simplexml_load_string($ssXml);
        if ($x) foreach ($x->si as $si) {
            $t = isset($si->t) ? (string)$si->t : '';
            foreach ($si->r as $r) $t .= (string)$r->t;
            $shared[] = $t;
        }
    }
    $xml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($xml === false) throw new RuntimeException('Excel sayfası bulunamadı.');
    $sx = simplexml_load_string($xml);
    if (!$sx) throw new RuntimeException('Excel sayfası okunamadı.');
    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $rn = (int)$row['r'];
        foreach ($row->c as $c) {
            if (!preg_match('/^([A-Z]+)(\d+)$/', (string)$c['r'], $m)) continue;
            $t = (string)$c['t'];
            if ($t === 's') $v = $shared[(int)$c->v] ?? '';
            elseif ($t === 'inlineStr') { $v = (string)$c->is->t; if (isset($c->is->r)) foreach ($c->is->r as $r) $v .= (string)$r->t; }
            else $v = isset($c->v) ? (string)$c->v : '';
            $rows[$rn][$m[1]] = $v;
        }
    }
    ksort($rows);
    return ['rows' => $rows, 'sheet' => $sheetPath];
}

function xlsx_first_sheet_path(ZipArchive $zip): string
{
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false
        && preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $wb, $m)
        && preg_match('/<Relationship\b[^>]*Id="' . preg_quote($m[1], '/') . '"[^>]*Target="([^"]+)"/', $rels, $t)) {
        $target = ltrim($t[1], '/');
        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
    }
    return 'xl/worksheets/sheet1.xml';
}

/**
 * Başlık satırını ve dosya türünü bulur. Aynı başlık birden fazla kez geçebilir ("1.KOMİSYON" 3 gün ve 4 gün için),
 * bu yüzden başlık → kolon listesi döner.
 */
function tariff_detect(array $rows): ?array
{
    foreach (array_slice($rows, 0, 10, true) as $rn => $cells) {
        $map = [];
        foreach ($cells as $col => $v) $map[tariff_norm((string)$v)][] = $col;
        $has = fn(string $h) => isset($map[tariff_norm($h)]);
        if (!$has('Barkod')) continue;
        $type = null;
        if ($has('1.Fiyat Alt Limit') && $has('YENİ TSF (FİYAT GÜNCELLE)')) $type = 'komisyon';
        elseif ($has('Plus Fiyat Üst Limiti')) $type = 'plus';
        elseif ($has('1 YILDIZ ÜST FİYAT')) $type = 'etiket';
        elseif ($has('24 Saat Fiyat')) $type = 'flas';
        elseif ($has('İndirim Uygulanmadan Önceki Fiyat')) $type = 'kampanya';
        if (!$type) continue;
        $det = ['type' => $type, 'header_row' => $rn, 'map' => $map, 'campaigns' => []];
        if ($type === 'kampanya') {
            // Bir üst satırda kampanya adı (iki kolona yayılır), başlıkta Maksimum Fiyat + giriş kolonu
            $names = $rows[$rn - 1] ?? [];
            foreach ($map[tariff_norm('İndirim Uygulanmadan Önceki Fiyat')] as $col) {
                $maxCol = xl_col_letter(xl_col_index($col) - 1);
                $name = trim((string)($names[$col] ?? $names[$maxCol] ?? ''));
                $det['campaigns'][] = ['col' => $col, 'max_col' => $maxCol, 'name' => $name] + campaign_terms($name);
            }
        }
        return $det;
    }
    return null;
}

/**
 * Kampanya adından indirim kuralını çıkarır. Trendyol adları: "…Çok Avantajlı Fiyat'tan %10 İndirim - %40 Trendyol
 * Karşılamalı - 395526", "…500 TL Üzerine 50 TL İndirim! - %25 Trendyol Karşılamalı - 394367".
 */
function campaign_terms(string $name): array
{
    $t = ['pct' => null, 'min' => null, 'tl' => null, 'cover' => 0.0, 'id' => null, 'title' => $name];
    if (preg_match('/%\s*(\d+(?:[.,]\d+)?)\s*Trendyol\s*Karşılamalı/iu', $name, $m)) $t['cover'] = (float)str_replace(',', '.', $m[1]);
    if (preg_match('/(\d[\d.]*)\s*TL\s*(?:Üzerine|\'ye|\'a|ve üzeri)\s*(\d[\d.]*)\s*TL\s*İndirim/iu', $name, $m)) {
        $t['min'] = (float)str_replace('.', '', $m[1]); $t['tl'] = (float)str_replace('.', '', $m[2]);
    } elseif (preg_match('/%\s*(\d+(?:[.,]\d+)?)\s*(?:Ek\s*)?İndirim/iu', $name, $m)) {
        $t['pct'] = (float)str_replace(',', '.', $m[1]);
    }
    if (preg_match('/-\s*(\d{3,})\s*$/', $name, $m)) { $t['id'] = $m[1]; $t['title'] = trim(preg_replace('/\s*-\s*\d{3,}\s*$/', '', $name)); }
    return $t;
}

/**
 * Kampanya kuralına göre tek üründe: müşterinin ödediği, satıcının karşıladığı indirim ve satıcıya kalan tutar.
 * Trendyol karşılamalı kısmı satıcıya geri ödenir. Varsayım: komisyon satıcıya kalan tutar üzerinden.
 */
function campaign_unit(array $c, float $price): array
{
    $disc = 0.0; $note = '';
    if ($c['pct'] !== null) $disc = $price * $c['pct'] / 100;
    elseif ($c['tl'] !== null) {
        if ($price >= $c['min']) $disc = $c['tl'];
        else $note = 'tek üründe ' . rtrim(rtrim(number_format($c['min'], 2, ',', ''), '0'), ',') . ' TL sepet şartı sağlanmıyor';
    }
    $seller = $disc * (1 - $c['cover'] / 100);
    return ['customer' => round($price - $disc, 2), 'discount' => round($disc, 2), 'seller_discount' => round($seller, 2),
            'net' => round($price - $seller, 2), 'note' => $note];
}

/** Başlığın n'inci (0'dan) geçtiği kolon. */
function tariff_col(array $map, string $header, int $nth = 0): ?string
{
    return $map[tariff_norm($header)][$nth] ?? null;
}

/**
 * Bir satırdan seçenekleri çıkarır. Her seçenek:
 *  key, label, price (müşterinin ödeyeceği, kâra esas fiyat), rates [dönem => komisyon %] (null = ürünün kendi oranı),
 *  write [kolon => değer] (Excel'e geri yazılacaklar), note.
 * Ayrıca satırın mevcut durumu: barcode, name, current_price, current_rate, tsf_ratio (TSF / müşteri fiyatı).
 */
function tariff_row(string $type, array $map, array $cells, array $det = []): ?array
{
    $g = fn(string $h, int $n = 0) => ($c = tariff_col($map, $h, $n)) !== null ? ($cells[$c] ?? '') : '';
    $num = fn(string $h, int $n = 0) => tariff_num($g($h, $n));
    $barcode = trim((string)$g('Barkod'));
    if ($barcode === '') return null;
    $name = trim((string)($g('Ürün İsmi') ?: $g('Ürün Adı')));
    $promos = trim((string)($g('Güncel Uygulanan Promosyonlar')));
    $row = ['barcode' => $barcode, 'name' => $name, 'promos' => $promos, 'options' => [],
            'current_price' => null, 'current_rate' => null, 'tsf' => null, 'tsf_ratio' => 1.0, 'periods' => []];

    // TSF (Trendyol satış fiyatı, satıcının girdiği) ile müşterinin ödediği fiyat arasındaki oran: satıcı indirimleri.
    // Yeni TSF = hedef müşteri fiyatı × oran.
    $ratio = function (?float $tsf, ?float $seen): float {
        return ($tsf && $seen && $seen > 0) ? $tsf / $seen : 1.0;
    };

    if ($type === 'komisyon') {
        $row['current_price'] = $num('KOMİSYONA ESAS FİYAT');
        $row['current_rate'] = $num('GÜNCEL KOMİSYON');
        $row['tsf'] = $num('GÜNCEL TSF');
        $row['tsf_ratio'] = $ratio($row['tsf'], $row['current_price']);
        $p3 = $g('Tarih aralığı (3 Gün)'); $p4 = $g('Tarih aralığı (4 Gün)');
        $row['periods'] = array_values(array_filter(['3 gün' => $p3, '4 gün' => $p4]));
        $bands = [
            1 => ['min' => $num('1.Fiyat Alt Limit'), 'max' => null],
            2 => ['min' => $num('2.Fiyat Alt Limit'), 'max' => $num('2.Fiyat Üst Limiti')],
            3 => ['min' => $num('3.Fiyat Alt Limit'), 'max' => $num('3.Fiyat Üst Limiti')],
            4 => ['min' => null, 'max' => $num('4.Fiyat Üst Limiti')],
        ];
        $tarifeCol = tariff_col($map, 'Tarife Seçimi');
        $tsfCol = tariff_col($map, 'YENİ TSF (FİYAT GÜNCELLE)');
        foreach ($bands as $k => $b) {
            $r3 = $num($k . '.KOMİSYON', 0); $r4 = $num($k . '.KOMİSYON', 1);
            if ($r3 === null && $r4 === null) continue;
            // Aralığın üst sınırında satmak o aralıktaki en kârlı fiyattır. 1. aralığın üst sınırı yok:
            // mevcut fiyat aralıktaysa mevcut fiyat, değilse alt limit.
            if ($k === 1) {
                if ($b['min'] === null) continue;
                $price = ($row['current_price'] !== null && $row['current_price'] >= $b['min']) ? $row['current_price'] : $b['min'];
            } else {
                if ($b['max'] === null) continue;
                $price = $b['max'];
            }
            $label = $k . '. aralık';
            $rates = array_filter(['3 gün' => $r3, '4 gün' => $r4], fn($v) => $v !== null);
            $write = [];
            if ($tsfCol) $write[$tsfCol] = round($price * $row['tsf_ratio'], 2);
            if ($tarifeCol) $write[$tarifeCol] = '7 Günlük Fiyat';
            $row['options'][] = ['key' => 'b' . $k, 'label' => $label, 'price' => $price, 'rates' => $rates, 'write' => $write,
                                 'note' => $k === 1 ? 've üstü' : 've altı', 'bmin' => $b['min'], 'bmax' => $b['max']];
        }
        return $row;
    }

    if ($type === 'plus') {
        $row['current_price'] = $num('Komisyona Esas Fiyat');
        $row['current_rate'] = $num('Güncel Komisyon');
        $row['tsf'] = $num('Güncel TSF');
        $limit = $num('Plus Fiyat Üst Limiti');
        $r3 = $num('Plus Komisyon Teklifi', 0); $r4 = $num('Plus Komisyon Teklifi', 1);
        $row['periods'] = array_values(array_filter([$g('Tarih Aralığı (3 Gün)'), $g('Tarih Aralığı (4 Gün)')]));
        if ($limit !== null && ($r3 !== null || $r4 !== null)) {
            $write = [];
            if ($c = tariff_col($map, 'Plus Fiyat Seçimi')) $write[$c] = round($limit, 2);
            if ($c = tariff_col($map, 'Tarife Seçimi')) $write[$c] = '7 Günlük Fiyat';
            $row['options'][] = ['key' => 'plus', 'label' => 'Plus fiyatı', 'price' => $limit,
                                 'rates' => array_filter(['3 gün' => $r3, '4 gün' => $r4], fn($v) => $v !== null),
                                 'write' => $write, 'note' => 'yalnız Plus üyelere'];
        }
        return $row;
    }

    if ($type === 'etiket') {
        $row['current_price'] = $num('Müşterinin Gördüğü Güncel Fiyat') ?? $num('MÜŞTERİNİN GÖRDÜĞÜ FİYAT');
        $row['tsf'] = $num('TRENDYOL SATIŞ FİYATI');
        $row['tsf_ratio'] = $ratio($row['tsf'], $row['current_price']);
        $tsfCol = tariff_col($map, 'YENİ TSF (FİYAT GÜNCELLE)');
        foreach ([1 => 'Avantajlı', 2 => 'Çok avantajlı', 3 => 'Süper avantajlı'] as $k => $label) {
            $price = $num($k . ' YILDIZ ÜST FİYAT');
            if ($price === null) continue;
            $write = $tsfCol ? [$tsfCol => round($price * $row['tsf_ratio'], 2)] : [];
            $row['options'][] = ['key' => 's' . $k, 'label' => $label, 'price' => $price, 'rates' => ['' => null],
                                 'write' => $write, 'note' => str_repeat('★', $k)];
        }
        return $row;
    }

    if ($type === 'kampanya') {
        $row['current_price'] = $num('Mevcut Satış Fiyatı');
        $row['name'] = $row['name'] ?: trim((string)$g('Ürün Adı'));
        foreach ($det['campaigns'] ?? [] as $i => $c) {
            $max = tariff_num($cells[$c['max_col']] ?? null);
            $flag = trim((string)($cells[$c['col']] ?? ''));
            if ($max === null || tariff_norm($flag) === tariff_norm('Uygun değil')) {
                $row['options'][] = ['key' => 'k' . $i, 'label' => $c['title'], 'price' => 0.0, 'rates' => [], 'write' => [],
                                     'note' => 'uygun değil', 'ineligible' => true];
                continue;
            }
            // Katılmak için en yüksek fiyat: maksimum fiyat; mevcut fiyat daha düşükse mevcut fiyat
            $price = ($row['current_price'] !== null && $row['current_price'] < $max) ? $row['current_price'] : $max;
            $u = campaign_unit($c, $price);
            $parts = [];
            if ($c['pct'] !== null) $parts[] = '%' . rtrim(rtrim(number_format($c['pct'], 1, ',', ''), '0'), ',') . ' indirim';
            if ($c['tl'] !== null) $parts[] = number_format($c['min'], 0, ',', '.') . ' TL\'ye ' . number_format($c['tl'], 0, ',', '.') . ' TL';
            if ($c['cover'] > 0) $parts[] = '%' . rtrim(rtrim(number_format($c['cover'], 1, ',', ''), '0'), ',') . ' Trendyol karşılar';
            $row['options'][] = ['key' => 'k' . $i, 'label' => $c['title'], 'price' => $price, 'sale' => $u['net'],
                                 'customer' => $u['customer'], 'rates' => ['' => null], 'write' => [$c['col'] => round($price, 2)],
                                 'note' => implode(' · ', $parts) . ($u['note'] ? ' · ' . $u['note'] : ''),
                                 'seller_discount' => $u['seller_discount']];
        }
        return $row;
    }

    if ($type === 'flas') {
        $row['current_price'] = $num('Müşterinin Gördüğü Fiyat');
        $row['current_rate'] = $num('Mevcut Komisyon');
        $row['tsf'] = $num('Mevcut Fiyat');
        $col = tariff_col($map, 'Senin Belirlediğin Flaş Fiyatı');
        foreach (['24 Saat Fiyat' => '24 saat flaş', '3 Saat Fiyat' => '3 saat flaş'] as $h => $label) {
            $price = $num($h);
            if ($price === null) continue;
            $start = $g(str_starts_with($h, '24') ? '24 Saat Flaş Başlangıç Tarihi' : '3 Saat Flaş Başlangıç Tarihi');
            $row['options'][] = ['key' => str_starts_with($h, '24') ? 'f24' : 'f3', 'label' => $label, 'price' => $price,
                                 'rates' => ['' => $row['current_rate']], 'write' => $col ? [$col => round($price, 2)] : [],
                                 'note' => $start ? 'başlangıç ' . $start : ''];
        }
        return $row;
    }
    return null;
}

/**
 * Tek ürün, tek adet, verilen müşteri fiyatı ve komisyon oranıyla net kâr (sipariş motoruyla aynı model).
 * $rate null ise ürünün kendi oranı kullanılır.
 */
function scenario_profit(int $userId, array $settings, array $product, float $price, ?float $rate, int $qty = 1): array
{
    $p = $product;
    if ($rate === null) $rate = band_rate($userId, (string)($p['marketplace'] ?? 'trendyol'), (string)$p['barcode'], $price);
    if ($rate !== null) $p['rate'] = $rate;
    $order = ['status' => 'Created', 'lines' => [[
        'barcode' => (string)$p['barcode'], 'quantity' => max(1, $qty), 'price' => $price,
        'vatRate' => isset($p['kdv']) && $p['kdv'] !== null && $p['kdv'] !== '' ? (float)$p['kdv'] : null,
    ]]];
    $r = compute_order_profit($userId, $settings, $order, [(string)$p['barcode'] => $p]);
    $r['rate'] = (float)$p['rate'];
    return $r;
}

/** Son komisyon tarifesindeki aralıklar (güncelse). */
function tariff_bands(int $userId, string $marketplace, string $barcode): ?array
{
    static $cache = [];
    $k = "$userId|$marketplace|$barcode";
    if (!array_key_exists($k, $cache)) {
        $st = db()->prepare("SELECT bands, updated_at FROM tariff_bands WHERE user_id = ? AND marketplace = ? AND barcode = ?");
        $st->execute([$userId, $marketplace, $barcode]);
        $r = $st->fetch();
        $cache[$k] = ($r && (int)$r['updated_at'] >= time() - TARIFF_BAND_MAX_AGE_DAYS * 86400) ? json_decode((string)$r['bands'], true) : null;
    }
    return $cache[$k];
}

/**
 * Bu fiyata düşen aralığın komisyonu (dönemlerin kötüsü). Aralık bilgisi yoksa null: ürünün kendi oranı kullanılır.
 * Satıcı indirimi komisyona esas fiyatı düşürdüğü için indirimle daha düşük komisyon aralığına geçilebilir ("etkili indirim").
 */
function band_rate(int $userId, string $marketplace, string $barcode, float $price): ?float
{
    $bands = tariff_bands($userId, $marketplace, $barcode);
    if (!$bands) return null;
    foreach ($bands as $b) {
        if (($b['min'] === null || $price >= $b['min'] - 0.001) && ($b['max'] === null || $price <= $b['max'] + 0.001)) {
            return $b['rates'] ? max(array_map('floatval', $b['rates'])) : null;
        }
    }
    return null;
}

function save_tariff_bands(int $userId, string $marketplace, array $rows): int
{
    $st = db()->prepare("INSERT INTO tariff_bands (user_id, marketplace, barcode, bands, updated_at) VALUES (?,?,?,?,?)
                         ON CONFLICT(user_id, marketplace, barcode) DO UPDATE SET bands = excluded.bands, updated_at = excluded.updated_at");
    $n = 0;
    foreach ($rows as $row) {
        $bands = [];
        foreach ($row['options'] as $o) if (array_key_exists('bmin', $o)) $bands[] = ['min' => $o['bmin'], 'max' => $o['bmax'], 'rates' => $o['rates']];
        if (!$bands) continue;
        $st->execute([$userId, $marketplace, $row['barcode'], json_encode($bands), time()]);
        $n++;
    }
    return $n;
}

/**
 * Excel'i analiz eder. Dönüş: type, header_row, rows[] (her satırda options[]: price, rates, profits[dönem], worst,
 * margin, cargo_note), unmatched, no_cost.
 */
function tariff_analyze(int $userId, string $marketplace, string $path): array
{
    $cells = xlsx_cells($path);
    $det = tariff_detect($cells['rows']);
    if (!$det) throw new RuntimeException('Dosya tanınmadı. Trendyol panelinde Promosyon & Fiyat sayfasındaki "Excel ile Güncelle → Excel Oluştur" ile indirilen dosyayı yükleyin.');
    $settings = user_settings($userId);
    $products = products_by_barcode($userId, $marketplace);
    $out = ['type' => $det['type'], 'title' => TARIFF_TYPES[$det['type']], 'header_row' => $det['header_row'],
            'sheet' => $cells['sheet'], 'rows' => [], 'unmatched' => 0, 'no_cost' => 0, 'periods' => [],
            'multi' => $det['type'] === 'kampanya', 'campaigns' => $det['campaigns'], 'bands_saved' => 0];

    foreach ($cells['rows'] as $rn => $rowCells) {
        if ($rn <= $det['header_row']) continue;
        $row = tariff_row($det['type'], $det['map'], $rowCells, $det);
        if (!$row) continue;
        // Dosyada zaten bu seçenek girilmişse (ör. daha önce Plus fiyatı seçilmiş) işaretle
        foreach ($row['options'] as &$o) {
            $o['active'] = $o['write'] !== [];
            foreach ($o['write'] as $col => $val) {
                $cur = $rowCells[$col] ?? '';
                $same = is_string($val) ? trim((string)$cur) === $val
                                        : (($n = tariff_num($cur)) !== null && abs($n - (float)$val) < 0.005);
                if (!$same) { $o['active'] = false; break; }
            }
        }
        unset($o);
        $row['row'] = $rn;
        if ($row['periods'] && !$out['periods']) $out['periods'] = $row['periods'];
        $p = $products[$row['barcode']] ?? null;
        $row['matched'] = $p !== null;
        $row['has_cost'] = $p !== null && (float)$p['cost'] > 0;
        if (!$row['matched']) $out['unmatched']++;
        elseif (!$row['has_cost']) $out['no_cost']++;
        if ($p && $row['name'] === '') $row['name'] = (string)$p['name'];

        // Bugünkü durum: mevcut fiyat ve mevcut komisyon
        // Bugün: mevcut fiyat. Komisyon tarifesinde mevcut fiyat da bir aralığa düşer ve o aralığın 3 gün / 4 gün
        // komisyonlarına tabidir; karşılaştırma adil olsun diye o aralığın en kötü dönemi alınır.
        $row['current_profit'] = null;
        if ($row['has_cost'] && $row['current_price']) {
            $rates = [$row['current_rate']];
            if ($det['type'] === 'komisyon') {
                foreach ($row['options'] as $o) {
                    if (($o['bmin'] === null || $row['current_price'] >= $o['bmin'] - 0.001)
                        && ($o['bmax'] === null || $row['current_price'] <= $o['bmax'] + 0.001)) { $rates = array_values($o['rates']); break; }
                }
            }
            foreach ($rates as $rate) {
                $c = scenario_profit($userId, $settings, $p, $row['current_price'], $rate);
                if ($row['current_profit'] === null || $c['profit'] < $row['current_profit']) $row['current_profit'] = $c['profit'];
            }
        }
        foreach ($row['options'] as &$o) {
            $o['profits'] = []; $o['worst'] = null; $o['margin'] = null; $o['cargo_note'] = ''; $o['rate_used'] = null;
            if (!$row['has_cost'] || $o['price'] <= 0) continue;
            foreach ($o['rates'] as $period => $rate) {
                $r = scenario_profit($userId, $settings, $p, (float)($o['sale'] ?? $o['price']), $rate);
                $o['rate_used'] = $r['rate'];
                $o['profits'][$period] = $r['profit'];
                $o['cargo_note'] = $r['cargo_note'];
                if ($o['worst'] === null || $r['profit'] < $o['worst']) { $o['worst'] = $r['profit']; $o['margin'] = $r['margin']; }
            }
        }
        unset($o);
        // Öneri: en kötü dönemde bile en yüksek kâr; en düşük marj ayarının altındaysa önerilmez.
        $row['best'] = null;
        foreach ($row['options'] as $o) {
            if ($o['worst'] === null) continue;
            if ($row['best'] === null || $o['worst'] > $row['options'][$row['best']]['worst']) {
                $row['best'] = array_search($o['key'], array_column($row['options'], 'key'), true);
            }
        }
        $row['suggest'] = null;
        if ($row['best'] !== null) {
            $b = $row['options'][$row['best']];
            $minMargin = (float)$settings['min_margin'];
            $beatsCurrent = $row['current_profit'] === null || $b['worst'] >= $row['current_profit'] - 0.005
                            || $det['type'] === 'flas' || $det['type'] === 'etiket' || $det['type'] === 'plus';
            if ($b['worst'] > 0 && ($b['margin'] ?? 0) >= $minMargin && $beatsCurrent) $row['suggest'] = $b['key'];
        }
        // Kampanyalar birbirinden bağımsız seçilir: marj eşiğini geçen her kampanya önerilir.
        $row['suggest_multi'] = [];
        if ($out['multi']) {
            foreach ($row['options'] as $o) {
                if ($o['worst'] !== null && $o['worst'] > 0 && ($o['margin'] ?? 0) >= (float)$settings['min_margin']) $row['suggest_multi'][] = $o['key'];
            }
        }
        $out['rows'][] = $row;
    }
    if ($det['type'] === 'komisyon') $out['bands_saved'] = save_tariff_bands($userId, $marketplace, $out['rows']);
    return $out;
}

/**
 * Yüklenen Excel'in kopyasına seçimleri yazar. $writes: [satırNo => [kolon => değer]]
 */
function tariff_export(string $srcPath, string $sheetPath, array $writes, string $destPath): void
{
    if (!copy($srcPath, $destPath)) throw new RuntimeException('Dosya kopyalanamadı.');
    $zip = new ZipArchive();
    if ($zip->open($destPath) !== true) throw new RuntimeException('Excel açılamadı.');
    $xml = $zip->getFromName($sheetPath);
    if ($xml === false) { $zip->close(); throw new RuntimeException('Excel sayfası bulunamadı.'); }
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    $dom->loadXML($xml);
    $ns = $dom->documentElement->namespaceURI;
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('m', $ns);
    $sheetData = $xp->query('//m:sheetData')->item(0);

    foreach ($writes as $rn => $cols) {
        $rowEl = $xp->query('m:row[@r="' . (int)$rn . '"]', $sheetData)->item(0);
        if (!$rowEl) {
            $rowEl = $dom->createElementNS($ns, 'row');
            $rowEl->setAttribute('r', (string)$rn);
            $after = null;
            foreach ($xp->query('m:row', $sheetData) as $r) if ((int)$r->getAttribute('r') > $rn) { $after = $r; break; }
            $after ? $sheetData->insertBefore($rowEl, $after) : $sheetData->appendChild($rowEl);
        }
        foreach ($cols as $col => $val) {
            $ref = $col . $rn;
            $c = $xp->query('m:c[@r="' . $ref . '"]', $rowEl)->item(0);
            if (!$c) {
                $c = $dom->createElementNS($ns, 'c');
                $c->setAttribute('r', $ref);
                $idx = xl_col_index($col); $before = null;
                foreach ($xp->query('m:c', $rowEl) as $other) {
                    if (preg_match('/^([A-Z]+)/', $other->getAttribute('r'), $m) && xl_col_index($m[1]) > $idx) { $before = $other; break; }
                }
                $before ? $rowEl->insertBefore($c, $before) : $rowEl->appendChild($c);
            }
            while ($c->firstChild) $c->removeChild($c->firstChild);
            if (is_int($val) || is_float($val)) {
                $c->removeAttribute('t');
                $c->appendChild($dom->createElementNS($ns, 'v', rtrim(rtrim(number_format((float)$val, 2, '.', ''), '0'), '.')));
            } else {
                $c->setAttribute('t', 'inlineStr');
                $is = $dom->createElementNS($ns, 'is');
                $t = $dom->createElementNS($ns, 't');
                $t->appendChild($dom->createTextNode((string)$val));
                $is->appendChild($t);
                $c->appendChild($is);
            }
        }
        // spans özniteliği yeni kolonu kapsamayabilir; Excel spans'ı ipucu olarak kullanır, kaldırmak güvenli.
        $rowEl->removeAttribute('spans');
    }
    $zip->addFromString($sheetPath, $dom->saveXML());
    if ($zip->locateName('xl/calcChain.xml') !== false) $zip->deleteName('xl/calcChain.xml');
    $zip->close();
}

/**
 * İndirim simülasyonu (Trendyol İndirimler sayfasındaki türler; bu sayfanın Excel'i yok).
 * $d: type = yuzde | tl | xaly | adet; pct, min, tl, x, y, n
 * Her ürün için en küçük uygun siparişi kurar (ör. 3 al 2 öde → 3 adet) ve sipariş kârını bugünküyle karşılaştırır.
 * Satıcı indirimi komisyona esas fiyatı düşürür: komisyon tarifesi yüklendiyse indirimli fiyatın aralığı kullanılır.
 */
function discount_simulate(int $userId, string $marketplace, array $d, ?array $barcodes = null): array
{
    $settings = user_settings($userId);
    $out = [];
    foreach (products_by_barcode($userId, $marketplace) as $bc => $p) {
        if ($barcodes !== null && !in_array($bc, $barcodes, true)) continue;
        $price = (float)$p['price'];
        $row = ['barcode' => $bc, 'name' => (string)$p['name'], 'price' => $price, 'has_cost' => (float)$p['cost'] > 0,
                'qty' => 1, 'unit' => $price, 'note' => '', 'now' => null, 'now_rate' => null, 'new' => null, 'new_rate' => null,
                'now_order' => null, 'new_order' => null, 'margin' => null, 'max_pct' => null];
        if ($price <= 0) { $row['note'] = 'fiyat yok'; $out[] = $row; continue; }
        switch ($d['type']) {
            case 'yuzde':
                $row['unit'] = $price * (1 - (float)$d['pct'] / 100); break;
            case 'tl':
                $min = max(0.01, (float)$d['min']);
                $row['qty'] = max(1, (int)ceil($min / $price - 1e-9));
                $row['unit'] = $price - min((float)$d['tl'], $price * $row['qty']) / $row['qty'];
                if ($row['qty'] > 1) $row['note'] = $row['qty'] . ' adetle sepet şartı sağlanır';
                break;
            case 'xaly':
                $x = max(1, (int)$d['x']); $y = max(1, min($x, (int)$d['y']));
                $row['qty'] = $x; $row['unit'] = $price * $y / $x; break;
            case 'adet':
                $row['qty'] = max(1, (int)$d['n']); $row['unit'] = $price * (1 - (float)$d['pct'] / 100); break;
            default:
                throw new InvalidArgumentException('bilinmeyen indirim türü');
        }
        $row['unit'] = round($row['unit'], 2);
        if (!$row['has_cost']) { $row['note'] = 'maliyet girilmemiş'; $out[] = $row; continue; }
        // Bugün: aynı adet indirimsiz (karşılaştırma aynı sepet büyüklüğüyle)
        $now = scenario_profit($userId, $settings, $p, $price, null, $row['qty']);
        $new = scenario_profit($userId, $settings, $p, $row['unit'], null, $row['qty']);
        $row['now_order'] = $now['profit']; $row['new_order'] = $new['profit'];
        $row['now'] = $now['profit'] / $row['qty']; $row['new'] = $new['profit'] / $row['qty'];
        $row['now_rate'] = $now['rate']; $row['new_rate'] = $new['rate']; $row['margin'] = $new['margin'];
        $row['cargo_note'] = $new['cargo_note'];
        // Zarar etmeden verilebilecek en yüksek tek ürün yüzde indirimi
        $lo = 0.0; $hi = 95.0;
        if (scenario_profit($userId, $settings, $p, $price, null)['profit'] <= 0) $row['max_pct'] = 0.0;
        else {
            for ($i = 0; $i < 18; $i++) {
                $mid = ($lo + $hi) / 2;
                $pr = scenario_profit($userId, $settings, $p, $price * (1 - $mid / 100), null)['profit'];
                if ($pr > 0) $lo = $mid; else $hi = $mid;
            }
            $row['max_pct'] = round($lo, 1);
        }
        $out[] = $row;
    }
    usort($out, fn($a, $b) => [(int)!$a['has_cost'], $a['new'] ?? 0] <=> [(int)!$b['has_cost'], $b['new'] ?? 0]);
    return $out;
}
