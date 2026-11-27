<?php
declare(strict_types=1);
/**
 * Chrome eklentisi API'si. Kimlik: "Authorization: Bearer kf_…" (Ayarlar → Chrome eklentisi).
 * Çerez kullanılmadığı için her kökene açık (CORS *); anahtarsız istek reddedilir.
 *
 * POST {"action":"ping"}                         → hesap bilgisi
 * POST {"action":"profit","marketplace":"trendyol","items":[{"barcode":"…","current":799.05,"options":[{"price":723.35,"rate":8.4}]}]}
 *      rate verilmezse: kayıtlı komisyon tarifesindeki aralık, o da yoksa ürünün oranı.
 * POST {"action":"calc","cost":…,"price":…,"rate":…,"kdv":20,"cargo":…} → kayıtsız hızlı hesap
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tariff.php';
require_once __DIR__ . '/../includes/api_tokens.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function out(array $d, int $code = 200): never { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok' => false, 'error' => 'POST bekleniyor'], 405);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(\S+)$/', $auth, $m)) out(['ok' => false, 'error' => 'Erişim anahtarı yok'], 401);
$u = api_token_user($m[1]);
if (!$u) out(['ok' => false, 'error' => 'Erişim anahtarı geçersiz. Ayarlar → Chrome eklentisi bölümünden yeni anahtar oluşturun.'], 401);
$uid = (int)$u['id'];

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) out(['ok' => false, 'error' => 'Geçersiz JSON'], 400);
$action = (string)($in['action'] ?? '');
$fnum = fn($v) => is_numeric($v) ? (float)$v : null;

if ($action === 'ping') {
    out(['ok' => true, 'name' => (string)$u['name'], 'pro' => is_pro($u)]);
}

if ($action === 'calc') {
    $price = (float)($fnum($in['price'] ?? null) ?? 0);
    $kdv = (float)($fnum($in['kdv'] ?? null) ?? 20);
    $s = user_settings($uid);
    $stopaj = !empty($s['apply_stopaj']) ? auto_stopaj($price, $kdv, (float)$s['stopaj_rate']) : 0.0;
    $r = calc_profit((float)($fnum($in['cost'] ?? null) ?? 0), $price, (float)($fnum($in['cargo'] ?? null) ?? 0),
                     (float)($fnum($in['rate'] ?? null) ?? 0), $kdv, (float)$s['fixed_cost_per_order'], trendyol_service_fee(), $stopaj);
    out(['ok' => true, 'profit' => round($r['profit'], 2), 'margin' => round($r['margin'], 2), 'commission' => round($r['commission'], 2),
         'net_kdv' => round($r['net_kdv'], 2), 'stopaj' => round($stopaj, 2), 'service_fee' => trendyol_service_fee()]);
}

if ($action === 'profit') {
    if (!is_pro($u)) out(['ok' => false, 'error' => 'Panelde kâr gösterimi Pro plan özelliğidir.'], 402);
    $mp = (string)($in['marketplace'] ?? 'trendyol');
    $items = array_slice((array)($in['items'] ?? []), 0, 200);
    $products = products_by_barcode($uid, $mp);
    $settings = user_settings($uid);
    $res = [];
    // Barkodu görünmeyen sayfalar (flaş) için ad eşleştirmesi: tek ürün eşleşirse kabul
    $fold = fn(string $x) => preg_replace('/[^a-z0-9çğıöşü]+/u', ' ', mb_strtolower(trim($x)));
    $byName = function (string $name) use ($products, $fold): ?array {
        $n = trim($fold($name)); if (mb_strlen($n) < 12) return null;
        $hits = [];
        foreach ($products as $p) {
            $pn = trim($fold((string)$p['name'])); if ($pn === '') continue;
            if ($pn === $n || str_ends_with($n, $pn) || str_ends_with($pn, $n)) $hits[] = $p;
        }
        return count($hits) === 1 ? $hits[0] : null;
    };
    foreach ($items as $it) {
        $bc = trim((string)($it['barcode'] ?? ''));
        $p = $products[$bc] ?? null;
        if (!$p && $bc === '' && !empty($it['name'])) { $p = $byName((string)$it['name']); $bc = $p ? (string)$p['barcode'] : ''; }
        $row = ['barcode' => $bc, 'found' => $p !== null, 'has_cost' => $p !== null && (float)$p['cost'] > 0, 'current' => null, 'options' => []];
        if (!$row['has_cost']) { $res[] = $row; continue; }
        $calc = function (float $price, $rate) use ($uid, $settings, $p) {
            $r = scenario_profit($uid, $settings, $p, $price, is_numeric($rate) ? (float)$rate : null);
            return ['price' => $price, 'rate' => round($r['rate'], 2), 'profit' => $r['profit'] === null ? null : round($r['profit'], 2),
                    'margin' => $r['margin'] === null ? null : round($r['margin'], 2), 'cargo_note' => $r['cargo_note']];
        };
        if (is_numeric($it['current'] ?? null) && (float)$it['current'] > 0) $row['current'] = $calc((float)$it['current'], $it['current_rate'] ?? null);
        foreach (array_slice((array)($it['options'] ?? []), 0, 8) as $o) {
            $price = $fnum($o['price'] ?? null);
            $row['options'][] = ($price && $price > 0) ? $calc($price, $o['rate'] ?? null) : null;
        }
        $res[] = $row;
    }
    out(['ok' => true, 'min_margin' => (float)$settings['min_margin'], 'items' => $res]);
}

out(['ok' => false, 'error' => 'Bilinmeyen işlem'], 400);
