<?php
declare(strict_types=1);

/**
 * Kâr motoru (v2): sipariş başına kâr bir kez hesaplanır ve order_profits tablosuna yazılır.
 *
 * İlke: her kalem önce TAHMİNİ hesaplanır, pazaryerinden gerçek kayıt gelince KESİN değerle değişir.
 *   Komisyon  : ürünün komisyon oranı              → cari hesap ekstresindeki Satış kaydı (commissionAmount)
 *   Kargo     : desi × kargo tarifesi veya barem   → kargo faturası kalemi (Gönderi Kargo Bedeli)
 *   İade      : varsayılan iade oranı (isteğe bağlı) → ekstredeki İade kaydı + İade Kargo Bedeli
 * Net KDV, stopaj ve hizmet bedeli her seferinde kesinleşen kalemlerle yeniden hesaplanır.
 *
 * Bu dosya functions.php tarafından yüklenir; vat_in(), profit_core(), auto_stopaj(),
 * trendyol_service_fee() oradadır.
 */

/** Kargo faturası kalemlerindeki tutarlar KDV hariç kabul edilir (Trendyol fatura kalemi). */
const CARGO_INVOICE_AMOUNT_EX_VAT = true;

/** Pazaryeri sipariş durumları. */
const ORDER_CANCELLED_STATUSES = ['Cancelled', 'UnSupplied', 'İptal', 'Iptal'];
const ORDER_RETURNED_STATUSES = ['Returned', 'İade', 'Iade'];

function default_user_settings(): array
{
    return [
        'zero_negative_vat' => 1, 'apply_stopaj' => 1, 'stopaj_rate' => 1.0,
        'fixed_cost_per_order' => 0.0, 'apply_return_rate' => 0, 'default_return_rate' => 0.0,
        'default_carrier' => 'TEX', 'barem_enabled' => 1, 'min_margin' => 10.0,
    ];
}

function user_settings(int $userId, bool $fresh = false): array
{
    static $cache = [];
    if (!$fresh && isset($cache[$userId])) return $cache[$userId];
    $st = db()->prepare("SELECT * FROM user_settings WHERE user_id = ?");
    $st->execute([$userId]);
    $row = $st->fetch() ?: [];
    $out = default_user_settings();
    foreach ($out as $k => $v) {
        if (array_key_exists($k, $row) && $row[$k] !== null) {
            $out[$k] = is_int($v) ? (int)$row[$k] : (is_float($v) ? (float)$row[$k] : (string)$row[$k]);
        }
    }
    return $cache[$userId] = $out;
}

function save_user_settings(int $userId, array $in): void
{
    $d = default_user_settings();
    $vals = [];
    foreach ($d as $k => $v) {
        $x = $in[$k] ?? $v;
        $vals[$k] = is_int($v) ? (int)(bool)$x : (is_float($v) ? max(0.0, (float)$x) : substr(preg_replace('/[^A-Z0-9_]/', '', strtoupper((string)$x)), 0, 20));
    }
    $cols = array_keys($vals);
    $sql = "INSERT INTO user_settings (user_id, " . implode(', ', $cols) . ", updated_at) VALUES (?, "
         . implode(', ', array_fill(0, count($cols), '?')) . ", datetime('now'))
            ON CONFLICT(user_id) DO UPDATE SET " . implode(', ', array_map(fn($c) => "$c = excluded.$c", $cols)) . ", updated_at = datetime('now')";
    db()->prepare($sql)->execute(array_merge([$userId], array_values($vals)));
    user_settings($userId, true);   // önbelleği tazele
}

/**
 * Ürünün desisi. Öncelik: Trendyol'un kestiği desi (kesilen_desi) > elle girilen desi > ölçülerden (en×boy×yük/3000).
 */
function product_desi(array $p): float
{
    if ((float)($p['kesilen_desi'] ?? 0) > 0) return (float)$p['kesilen_desi'];
    if ((float)($p['desi'] ?? 0) > 0) return (float)$p['desi'];
    $en = (float)($p['en'] ?? 0); $boy = (float)($p['boy'] ?? 0); $y = (float)($p['yukseklik'] ?? 0);
    if ($en > 0 && $boy > 0 && $y > 0) return desi_from_dims($en, $boy, $y);
    return 0.0;
}

/** Desi aralığına göre kargo fiyatı (KDV hariç). Önce kullanıcının tarifesi, yoksa sistem varsayılanı. */
function cargo_tariff_price(int $userId, string $carrier, float $desi): ?float
{
    static $cache = [];
    $key = "$userId|$carrier";
    if (!isset($cache[$key])) {
        $st = db()->prepare("SELECT user_id, desi_from, desi_to, price_ex_vat FROM cargo_tariffs
                             WHERE carrier = ? AND user_id IN (?, 0) ORDER BY user_id DESC, desi_from");
        $st->execute([$carrier, $userId]);
        $rows = $st->fetchAll();
        $own = array_values(array_filter($rows, fn($r) => (int)$r['user_id'] === $userId));
        $cache[$key] = $own ?: array_values(array_filter($rows, fn($r) => (int)$r['user_id'] === 0));
    }
    $rows = $cache[$key];
    if (!$rows) return null;
    $d = max(1.0, ceil($desi - 1e-9));     // kargo firmaları desiyi yukarı yuvarlar
    foreach ($rows as $r) {
        if ($d >= (float)$r['desi_from'] && $d <= (float)$r['desi_to']) return (float)$r['price_ex_vat'];
    }
    // Tablonun üst sınırını aşan desi: son kademenin fiyatı (alt tahmin; tarifeyi genişletmek gerekir)
    $last = end($rows);
    return (float)$last['price_ex_vat'];
}

/** Barem: sepet tutarına göre sabit kargo (KDV hariç). */
function cargo_barem_price(int $userId, string $carrier, float $basket, float $desi): ?float
{
    static $cache = [];
    $key = "$userId|$carrier";
    if (!isset($cache[$key])) {
        $st = db()->prepare("SELECT user_id, min_price, max_price, max_desi, price_ex_vat FROM cargo_barem
                             WHERE carrier = ? AND user_id IN (?, 0) ORDER BY user_id DESC, min_price");
        $st->execute([$carrier, $userId]);
        $rows = $st->fetchAll();
        $own = array_values(array_filter($rows, fn($r) => (int)$r['user_id'] === $userId));
        $cache[$key] = $own ?: array_values(array_filter($rows, fn($r) => (int)$r['user_id'] === 0));
    }
    foreach ($cache[$key] as $r) {
        if ($basket >= (float)$r['min_price'] && $basket < (float)$r['max_price'] && $desi <= (float)$r['max_desi']) {
            return (float)$r['price_ex_vat'];
        }
    }
    return null;
}

/**
 * Paketin tahmini kargo bedeli (KDV dahil).
 * @param array $items [['product'=>array,'qty'=>int], ...]
 * @return array{amount: float, src: string, note: string}
 */
function estimate_cargo(int $userId, array $settings, array $items, float $basket): array
{
    $override = 0.0; $desi = 0.0; $manual = 0.0;
    foreach ($items as $it) {
        $p = $it['product'];
        if (!empty($p['cargo_override']) && (float)$p['cargo'] > 0) $override = max($override, (float)$p['cargo']);
        $desi += product_desi($p) * max(1, (int)$it['qty']);
        $manual = max($manual, (float)($p['cargo'] ?? 0));
    }
    if ($override > 0) return ['amount' => $override, 'src' => 'tahmini', 'note' => 'ürün kartındaki kargo'];

    $carrier = $settings['default_carrier'] ?: 'TEX';
    if ($desi > 0) {
        if (!empty($settings['barem_enabled'])) {
            $b = cargo_barem_price($userId, $carrier, $basket, $desi);
            if ($b !== null) {
                return ['amount' => round($b * (1 + FEE_KDV_RATE / 100), 2), 'src' => 'tahmini',
                        'note' => 'barem · düşük tutarlı sepet'];
            }
        }
        $t = cargo_tariff_price($userId, $carrier, $desi);
        if ($t !== null) {
            return ['amount' => round($t * (1 + FEE_KDV_RATE / 100), 2), 'src' => 'tahmini',
                    'note' => sprintf('desi %s · %s tarifesi', rtrim(rtrim(number_format($desi, 1, ',', ''), '0'), ','), $carrier)];
        }
    }
    if ($manual > 0) return ['amount' => $manual, 'src' => 'tahmini', 'note' => 'ürün kartındaki kargo'];
    return ['amount' => 0.0, 'src' => 'yok', 'note' => $desi > 0 ? 'kargo tarifesi yüklenmemiş' : 'desi girilmemiş'];
}

/**
 * Bir paketin kârını hesaplar. $actual: ['commission'=>?float, 'cargo'=>?float, 'return_cargo'=>?float, 'returned'=>bool]
 * Tüm tutarlar KDV dahil.
 */
function compute_order_profit(int $userId, array $settings, array $order, array $byBarcode, array $actual = []): array
{
    $lines = $order['lines'] ?? [];
    if (is_string($lines)) $lines = json_decode($lines, true) ?: [];
    $status = (string)($order['status'] ?? '');

    $r = [
        'sale' => 0.0, 'cost' => 0.0, 'commission' => 0.0, 'commission_src' => 'tahmini',
        'cargo' => 0.0, 'cargo_src' => 'tahmini', 'cargo_note' => '', 'service_fee' => 0.0, 'stopaj' => 0.0,
        'fixed_cost' => 0.0, 'return_loss' => 0.0, 'return_src' => 'tahmini',
        'sale_vat' => 0.0, 'cost_vat' => 0.0, 'fee_vat' => 0.0, 'net_vat' => 0.0,
        'profit' => null, 'margin' => null, 'missing_cost' => 0, 'is_final' => 0,
        'is_cancelled' => in_array($status, ORDER_CANCELLED_STATUSES, true) ? 1 : 0,
        'item_count' => 0,
    ];
    if ($r['is_cancelled']) { $r['profit'] = 0.0; return $r; }

    $items = []; $missing = false; $commissionEst = 0.0; $stopaj = 0.0;
    foreach ($lines as $line) {
        $qty = max(1, (int)($line['quantity'] ?? 1));
        $lineSale = (float)($line['price'] ?? 0) * $qty;
        $p = $byBarcode[(string)($line['barcode'] ?? '')] ?? null;
        $r['item_count'] += $qty;
        $r['sale'] += $lineSale;
        $kdv = (isset($line['vatRate']) && $line['vatRate'] !== null) ? (float)$line['vatRate'] : (float)($p['kdv'] ?? 20);
        $r['sale_vat'] += vat_in($lineSale, $kdv);
        if (!empty($settings['apply_stopaj'])) $stopaj += $lineSale / (1 + $kdv / 100) * ((float)$settings['stopaj_rate'] / 100);
        if (!$p || (float)$p['cost'] <= 0) { $missing = true; $commissionEst += $lineSale * 0.20; continue; }
        $lineCost = (float)$p['cost'] * $qty;
        $costKdv = ($p['cost_kdv'] ?? null) !== null && $p['cost_kdv'] !== '' ? (float)$p['cost_kdv'] : $kdv;
        $r['cost'] += $lineCost;
        $r['cost_vat'] += vat_in($lineCost, $costKdv);
        $commissionEst += $lineSale * (float)$p['rate'] / 100;
        $items[] = ['product' => $p, 'qty' => $qty];
    }
    $r['stopaj'] = $stopaj;

    // Komisyon: ekstrede satış kaydı varsa kesin
    if (isset($actual['commission']) && $actual['commission'] !== null) {
        $r['commission'] = (float)$actual['commission']; $r['commission_src'] = 'kesin';
    } else {
        $r['commission'] = $commissionEst;
    }

    // Kargo: faturada varsa kesin, yoksa tahmin
    if (isset($actual['cargo']) && $actual['cargo'] !== null) {
        $r['cargo'] = (float)$actual['cargo']; $r['cargo_src'] = 'kesin'; $r['cargo_note'] = 'kargo faturası';
    } else {
        $est = estimate_cargo($userId, $settings, $items, $r['sale']);
        $r['cargo'] = $est['amount']; $r['cargo_src'] = $est['src']; $r['cargo_note'] = $est['note'];
    }

    $r['service_fee'] = trendyol_service_fee();
    $r['fixed_cost'] = (float)$settings['fixed_cost_per_order'];

    // İade
    $returned = !empty($actual['returned']) || in_array($status, ORDER_RETURNED_STATUSES, true);
    if ($returned) {
        // Ürün geri döner: satış, maliyet ve komisyon iptal; kayıp gidiş + dönüş kargosu + hizmet bedeli + sabit gider.
        $retCargo = isset($actual['return_cargo']) && $actual['return_cargo'] !== null ? (float)$actual['return_cargo'] : $r['cargo'];
        $r['return_src'] = isset($actual['return_cargo']) && $actual['return_cargo'] !== null ? 'kesin' : 'tahmini';
        $loss = $r['cargo'] + $retCargo + $r['service_fee'] + $r['fixed_cost'];
        $r['return_loss'] = $retCargo;
        $r['fee_vat'] = vat_in($r['cargo'] + $retCargo + $r['service_fee'], FEE_KDV_RATE);
        $r['profit'] = -($loss - $r['fee_vat']);   // kesintilerin KDV'si indirilebilir
        $r['margin'] = null;
        $r['sale_vat'] = $r['cost_vat'] = 0.0; $r['net_vat'] = -$r['fee_vat'];
        $r['is_final'] = ($r['cargo_src'] === 'kesin' && $r['return_src'] === 'kesin') ? 1 : 0;
        return $r;
    }
    if (!empty($settings['apply_return_rate']) && (float)$settings['default_return_rate'] > 0 && $r['commission_src'] !== 'kesin') {
        $r['return_loss'] = expected_return_loss((float)$settings['default_return_rate'], $r['cargo'], $r['service_fee']);
    }

    if ($missing || $r['sale'] <= 0) {
        $r['missing_cost'] = 1;
        return $r;
    }

    $core = profit_core($r['sale'], $r['sale_vat'], $r['cost'], $r['cost_vat'], $r['commission'], $r['cargo'],
                        $r['fixed_cost'], $r['service_fee'], $r['stopaj'], $r['return_loss'], (bool)$settings['zero_negative_vat']);
    $r['fee_vat'] = $core['fee_kdv'];
    $r['net_vat'] = $core['net_kdv'];
    $r['profit'] = $core['profit'];
    $r['margin'] = $core['margin'];
    $r['is_final'] = ($r['commission_src'] === 'kesin' && $r['cargo_src'] === 'kesin') ? 1 : 0;
    return $r;
}

/** Ürünleri barkoda göre indeksler. */
function products_by_barcode(int $userId, string $marketplace): array
{
    $st = db()->prepare("SELECT * FROM products WHERE user_id = ? AND marketplace = ? AND barcode != ''");
    $st->execute([$userId, $marketplace]);
    $out = [];
    foreach ($st->fetchAll() as $p) $out[(string)$p['barcode']] = $p;
    return $out;
}

/**
 * Paketlerin gerçek (kesin) kayıtlarını toplar: ekstre satış komisyonu, iade kaydı, kargo faturaları.
 * @return array<string, array> paket numarası => ['commission'=>?, 'cargo'=>?, 'return_cargo'=>?, 'returned'=>bool]
 */
function order_actuals(int $userId, string $marketplace, array $orders): array
{
    if (!$orders) return [];
    $db = db();
    $byPkg = []; $orderToPkgs = [];
    foreach ($orders as $o) {
        $byPkg[(string)$o['package_number']] = ['commission' => null, 'cargo' => null, 'return_cargo' => null, 'returned' => false];
        $orderToPkgs[(string)$o['order_number']][] = (string)$o['package_number'];
    }
    $pkgs = array_keys($byPkg);
    foreach (array_chunk($pkgs, 400) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT shipment_package_id pkg, transaction_type t, SUM(ABS(commission_amount)) c, COUNT(*) n
                            FROM settlements WHERE user_id = ? AND marketplace = ? AND shipment_package_id IN ($in)
                            GROUP BY shipment_package_id, transaction_type");
        $st->execute(array_merge([$userId, $marketplace], $chunk));
        foreach ($st->fetchAll() as $row) {
            $t = (string)$row['t'];
            if (in_array($t, ['Satış', 'Sale', 'Satis'], true)) $byPkg[$row['pkg']]['commission'] = (float)$row['c'];
            if (in_array($t, ['İade', 'Return', 'Iade'], true)) $byPkg[$row['pkg']]['returned'] = true;
        }
    }
    $orderNos = array_keys($orderToPkgs);
    foreach (array_chunk($orderNos, 400) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $db->prepare("SELECT order_number o, is_return r, SUM(amount) a FROM cargo_invoice_items
                            WHERE user_id = ? AND marketplace = ? AND order_number IN ($in) GROUP BY order_number, is_return");
        $st->execute(array_merge([$userId, $marketplace], $chunk));
        foreach ($st->fetchAll() as $row) {
            $amount = (float)$row['a'] * (CARGO_INVOICE_AMOUNT_EX_VAT ? (1 + FEE_KDV_RATE / 100) : 1);
            $pk = $orderToPkgs[$row['o']] ?? [];
            if (!$pk) continue;
            // Bir siparişin birden çok paketi varsa kargo faturası sipariş numarasıyla gelir: paketlere eşit böl.
            $share = $amount / count($pk);
            foreach ($pk as $p) {
                if ((int)$row['r'] === 1) $byPkg[$p]['return_cargo'] = $share;
                else $byPkg[$p]['cargo'] = $share;
            }
        }
    }
    return $byPkg;
}

/**
 * Siparişlerin kârını yeniden hesaplar ve order_profits'e yazar.
 * @param int $sinceTs 0 = tümü; aksi halde bu tarihten sonraki siparişler.
 * @return array{count:int, final:int, missing:int}
 */
function recompute_order_profits(int $userId, string $marketplace, int $sinceTs = 0): array
{
    $db = db();
    $settings = user_settings($userId);
    $byBarcode = products_by_barcode($userId, $marketplace);
    $st = $db->prepare("SELECT * FROM orders WHERE user_id = ? AND marketplace = ? AND order_date >= ? ORDER BY order_date");
    $st->execute([$userId, $marketplace, $sinceTs]);
    $orders = $st->fetchAll();

    $up = $db->prepare("INSERT INTO order_profits (user_id, marketplace, package_number, order_number, order_date, status, item_count,
            sale, cost, commission, commission_src, cargo, cargo_src, cargo_note, service_fee, stopaj, fixed_cost, return_loss, return_src,
            sale_vat, cost_vat, fee_vat, net_vat, profit, margin, missing_cost, is_final, is_cancelled, computed_at)
        VALUES (?,?,?,?,?,?,?, ?,?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(user_id, marketplace, package_number) DO UPDATE SET
            order_number=excluded.order_number, order_date=excluded.order_date, status=excluded.status, item_count=excluded.item_count,
            sale=excluded.sale, cost=excluded.cost, commission=excluded.commission, commission_src=excluded.commission_src,
            cargo=excluded.cargo, cargo_src=excluded.cargo_src, cargo_note=excluded.cargo_note, service_fee=excluded.service_fee,
            stopaj=excluded.stopaj, fixed_cost=excluded.fixed_cost, return_loss=excluded.return_loss, return_src=excluded.return_src,
            sale_vat=excluded.sale_vat, cost_vat=excluded.cost_vat, fee_vat=excluded.fee_vat, net_vat=excluded.net_vat,
            profit=excluded.profit, margin=excluded.margin, missing_cost=excluded.missing_cost, is_final=excluded.is_final,
            is_cancelled=excluded.is_cancelled, computed_at=excluded.computed_at");

    $count = $final = $missing = 0;
    $now = time();
    $db->beginTransaction();
    try {
        foreach (array_chunk($orders, 500) as $chunk) {
            $actuals = order_actuals($userId, $marketplace, $chunk);
            foreach ($chunk as $o) {
                $r = compute_order_profit($userId, $settings, $o, $byBarcode, $actuals[(string)$o['package_number']] ?? []);
                $up->execute([$userId, $marketplace, (string)$o['package_number'], (string)$o['order_number'], (int)$o['order_date'],
                    (string)$o['status'], $r['item_count'], $r['sale'], $r['cost'], $r['commission'], $r['commission_src'],
                    $r['cargo'], $r['cargo_src'], $r['cargo_note'], $r['service_fee'], $r['stopaj'], $r['fixed_cost'], $r['return_loss'], $r['return_src'],
                    $r['sale_vat'], $r['cost_vat'], $r['fee_vat'], $r['net_vat'], $r['profit'], $r['margin'], $r['missing_cost'],
                    $r['is_final'], $r['is_cancelled'], $now]);
                $count++;
                if ($r['is_final']) $final++;
                if ($r['missing_cost']) $missing++;
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['count' => $count, 'final' => $final, 'missing' => $missing];
}

/**
 * Tarih aralığı için toplamlar (dashboard). İptaller hariç; maliyeti eksik siparişler kâra katılmaz ama sayılır.
 */
function order_profit_totals(int $userId, string $marketplace, int $fromTs, int $toTs): array
{
    $st = db()->prepare("SELECT
            COUNT(*) orders,
            SUM(CASE WHEN missing_cost = 0 THEN 1 ELSE 0 END) costed,
            SUM(is_final) final_orders,
            SUM(sale) sale,
            SUM(CASE WHEN missing_cost = 0 THEN sale ELSE 0 END) costed_sale,
            SUM(CASE WHEN missing_cost = 0 THEN cost ELSE 0 END) cost,
            SUM(CASE WHEN missing_cost = 0 THEN commission ELSE 0 END) commission,
            SUM(CASE WHEN missing_cost = 0 THEN cargo ELSE 0 END) cargo,
            SUM(CASE WHEN missing_cost = 0 THEN service_fee ELSE 0 END) service_fee,
            SUM(CASE WHEN missing_cost = 0 THEN stopaj ELSE 0 END) stopaj,
            SUM(CASE WHEN missing_cost = 0 THEN fixed_cost ELSE 0 END) fixed_cost,
            SUM(CASE WHEN missing_cost = 0 THEN return_loss ELSE 0 END) return_loss,
            SUM(CASE WHEN missing_cost = 0 THEN net_vat ELSE 0 END) net_vat,
            SUM(COALESCE(profit, 0)) profit,
            SUM(CASE WHEN is_final = 1 THEN COALESCE(profit, 0) ELSE 0 END) final_profit,
            SUM(CASE WHEN commission_src = 'kesin' THEN 1 ELSE 0 END) commission_final,
            SUM(CASE WHEN cargo_src = 'kesin' THEN 1 ELSE 0 END) cargo_final
        FROM order_profits WHERE user_id = ? AND marketplace = ? AND is_cancelled = 0 AND order_date >= ? AND order_date < ?");
    $st->execute([$userId, $marketplace, $fromTs, $toTs]);
    $t = $st->fetch() ?: [];
    foreach ($t as $k => $v) $t[$k] = (float)($v ?? 0);
    $t['margin'] = ($t['costed_sale'] ?? 0) > 0 ? $t['profit'] / $t['costed_sale'] * 100 : 0.0;
    return $t;
}

/** Sayfalı sipariş kârı listesi. */
function order_profit_page(int $userId, string $marketplace, int $fromTs, int $toTs, int $page, int $perPage = 50, string $filter = ''): array
{
    $where = "user_id = ? AND marketplace = ? AND order_date >= ? AND order_date < ?";
    $args = [$userId, $marketplace, $fromTs, $toTs];
    if ($filter === 'zarar') $where .= " AND profit < 0 AND is_cancelled = 0";
    elseif ($filter === 'maliyetsiz') $where .= " AND missing_cost = 1 AND is_cancelled = 0";
    elseif ($filter === 'tahmini') $where .= " AND is_final = 0 AND is_cancelled = 0";
    elseif ($filter === 'kesin') $where .= " AND is_final = 1";
    $c = db()->prepare("SELECT COUNT(*) FROM order_profits WHERE $where");
    $c->execute($args);
    $total = (int)$c->fetchColumn();
    $off = max(0, ($page - 1) * $perPage);
    $st = db()->prepare("SELECT * FROM order_profits WHERE $where ORDER BY order_date DESC LIMIT $perPage OFFSET $off");
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'pages' => max(1, (int)ceil($total / $perPage))];
}

/**
 * Kâr kayıtları eksikse (ilk kurulum, eski veriler) tamamlar. Sayfa açılışında ucuz bir sayım kontrolü yapar.
 */
function ensure_order_profits(int $userId, string $marketplace, int $fromTs): void
{
    $a = db()->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND marketplace = ? AND order_date >= ?");
    $a->execute([$userId, $marketplace, $fromTs]);
    $b = db()->prepare("SELECT COUNT(*) FROM order_profits WHERE user_id = ? AND marketplace = ? AND order_date >= ?");
    $b->execute([$userId, $marketplace, $fromTs]);
    if ((int)$a->fetchColumn() > (int)$b->fetchColumn()) {
        recompute_order_profits($userId, $marketplace, $fromTs);
    }
}

/** "kesin" / "tahmini" rozeti (HTML). */
function src_badge(string $src, string $note = ''): string
{
    $map = ['kesin' => ['kesin', 'badge-final'], 'tahmini' => ['tahmini', 'badge-est'], 'yok' => ['bilgi yok', 'badge-missing']];
    [$label, $cls] = $map[$src] ?? [$src, 'badge-est'];
    $t = $note !== '' ? ' title="' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '"' : '';
    return '<span class="src-badge ' . $cls . '"' . $t . '>' . $label . '</span>';
}

/**
 * Otomatik hakediş kontrolleri.
 *  - fazla_desi: faturadaki desi, ürünün olması gereken desisinden büyük (tek ürünlü paketler).
 *  - eksik_hakedis: teslim edilmiş ve $graceDays günden eski olduğu halde ekstrede satış kaydı olmayan siparişler.
 *  - fazla_kargo: faturadaki kargo, tarifeye göre beklenenden %10'dan fazla yüksek.
 */
function hakedis_checks(int $userId, string $marketplace, int $days = 60, int $graceDays = 20): array
{
    $db = db();
    $from = time() - $days * 86400;
    $byBarcode = products_by_barcode($userId, $marketplace);
    $settings = user_settings($userId);
    $out = ['fazla_desi' => [], 'eksik_hakedis' => [], 'fazla_kargo' => []];

    $st = $db->prepare("SELECT c.order_number, c.desi, c.amount, c.item_type, o.lines, o.order_date
                        FROM cargo_invoice_items c
                        JOIN orders o ON o.user_id = c.user_id AND o.marketplace = c.marketplace AND o.order_number = c.order_number
                        WHERE c.user_id = ? AND c.marketplace = ? AND c.is_return = 0 AND o.order_date >= ?");
    $st->execute([$userId, $marketplace, $from]);
    foreach ($st->fetchAll() as $row) {
        $lines = json_decode((string)$row['lines'], true) ?: [];
        $expDesi = 0.0; $known = true;
        foreach ($lines as $l) {
            $p = $byBarcode[(string)($l['barcode'] ?? '')] ?? null;
            if (!$p) { $known = false; break; }
            $d = (float)($p['desi'] ?? 0) > 0 ? (float)$p['desi'] : desi_from_dims((float)$p['en'], (float)$p['boy'], (float)$p['yukseklik']);
            if ($d <= 0) { $known = false; break; }
            $expDesi += $d * max(1, (int)($l['quantity'] ?? 1));
        }
        if (!$known) continue;
        $charged = (float)$row['desi'];
        if ($charged > ceil($expDesi) + 0.01) {
            $exp = cargo_tariff_price($userId, $settings['default_carrier'], $expDesi);
            $out['fazla_desi'][] = [
                'order_number' => $row['order_number'], 'date' => (int)$row['order_date'],
                'product' => (string)($lines[0]['productName'] ?? ''), 'expected_desi' => $expDesi, 'charged_desi' => $charged,
                'charged' => (float)$row['amount'], 'expected' => $exp,
                'diff' => $exp !== null ? max(0.0, (float)$row['amount'] - $exp) : null,
            ];
        } else {
            $exp = cargo_tariff_price($userId, $settings['default_carrier'], $charged);
            if ($exp !== null && (float)$row['amount'] > $exp * 1.10) {
                $out['fazla_kargo'][] = ['order_number' => $row['order_number'], 'date' => (int)$row['order_date'], 'desi' => $charged,
                                         'charged' => (float)$row['amount'], 'expected' => $exp, 'diff' => (float)$row['amount'] - $exp];
            }
        }
    }

    $st = $db->prepare("SELECT o.order_number, o.package_number, o.order_date, o.total_price, o.status FROM orders o
                        WHERE o.user_id = ? AND o.marketplace = ? AND o.status = 'Delivered' AND o.order_date BETWEEN ? AND ?
                          AND NOT EXISTS (SELECT 1 FROM settlements s WHERE s.user_id = o.user_id AND s.marketplace = o.marketplace
                                          AND (s.shipment_package_id = o.package_number OR s.order_number = o.order_number))
                        ORDER BY o.order_date");
    $st->execute([$userId, $marketplace, $from, time() - $graceDays * 86400]);
    $hasAnySettlement = (int)$db->query("SELECT COUNT(*) FROM settlements WHERE user_id = " . (int)$userId)->fetchColumn() > 0;
    if ($hasAnySettlement) {
        foreach ($st->fetchAll() as $o) {
            $out['eksik_hakedis'][] = ['order_number' => $o['order_number'], 'date' => (int)$o['order_date'], 'sale' => (float)$o['total_price']];
        }
    }
    return $out;
}
