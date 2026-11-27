<?php
declare(strict_types=1);
/**
 * Trendyol tarife Excel'leri: tanıma, aralık başına komisyonla kâr, Trendyol formatında geri yazma.
 * Fixture'lar gerçek panel dosyalarının kolon yapısıyla üretilmiştir (scripts/fixtures/olustur.py).
 * Çalıştır: php scripts/test-tarife.php
 */
$db = sys_get_temp_dir() . '/karfiyat-tarife-' . getmypid() . '.sqlite';
@unlink($db);
putenv('KARFIYAT_DB=' . $db);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tariff.php';

$fail = 0; $n = 0;
function ok(bool $c, string $m): void { global $fail, $n; $n++; echo ($c ? "  ✓ " : "  ✗ ") . $m . "\n"; if (!$c) $fail++; }
function near(?float $a, float $b, float $e = 0.02): bool { return $a !== null && abs($a - $b) <= $e; }

$pdo = db();
$pdo->exec("INSERT INTO users (email, name, password_hash) VALUES ('t@t', 'T', 'x')");
$uid = (int)$pdo->lastInsertId();
$mp = 'trendyol';
$ins = $pdo->prepare("INSERT INTO products (user_id, name, barcode, marketplace, category, rate, cost, price, cargo, kdv, desi, en, boy, yukseklik)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$ins->execute([$uid, 'Alez 8li', 'bamboyastik8li', $mp, 'Yastık Alezi', 13.1, 300, 799.05, 0, 10, 3, 0, 0, 0]);
$ins->execute([$uid, 'Alez 2li', 'yastikalezi50x70', $mp, 'Yastık Alezi', 21, 90, 299.9, 0, 10, 1, 0, 0, 0]);
$ins->execute([$uid, 'Kanepe', 'kanepecan', $mp, 'Koltuk Örtüsü', 21, 0, 670, 0, 10, 2, 0, 0, 0]);   // maliyetsiz
$ins->execute([$uid, 'Nevresim', '1cmyollugriset', $mp, 'Nevresim', 19, 250, 600, 0, 10, 2, 0, 0, 0]);
$tar = $pdo->prepare("INSERT INTO cargo_tariffs (user_id, carrier, desi_from, desi_to, price_ex_vat) VALUES (?,?,?,?,?)");
foreach ([[1, 1, 70.00], [2, 2, 80.00], [3, 3, 95.00], [4, 10, 120.00]] as [$f, $t, $p]) $tar->execute([$uid, 'TEX', $f, $t, $p]);
$pdo->prepare("INSERT INTO cargo_barem (user_id, carrier, min_price, max_price, max_desi, price_ex_vat) VALUES (?, 'TEX', 0, 300, 10, 40)")->execute([$uid]);
$fx = __DIR__ . '/fixtures/';

echo "Ürün Komisyon Tarifesi\n";
$a = tariff_analyze($uid, $mp, $fx . 'komisyon-tarifesi.xlsx');
ok($a['type'] === 'komisyon', 'dosya türü tanındı');
ok(count($a['rows']) === 4 && $a['unmatched'] === 1 && $a['no_cost'] === 1, '4 satır, 1 eşleşmeyen, 1 maliyetsiz');
$r = $a['rows'][0];
ok(count($r['options']) === 4, '8li alez: 4 aralık');
ok(near($r['options'][0]['price'], 799.06) && near($r['options'][1]['price'], 799.05) && near($r['options'][3]['price'], 647.65),
   '1. aralık alt limit (mevcut 791,06 altında kalıyor), diğerleri aralığın üst sınırı');
ok($r['options'][1]['rates'] === ['3 gün' => 13.1, '4 gün' => 17.6], 'her aralığın 3 gün / 4 gün komisyonu ayrı');
// 2. aralık 4 gün: kâr = elle hesap (KDV %10 satış, maliyet KDV %10, kargo 3 desi 95×1,2=114, hizmet 13,19, stopaj %1)
$p = 799.05; $com = $p * 0.176; $cargo = 114.0; $svc = trendyol_service_fee(); $stop = $p / 1.1 * 0.01;
$netVat = max(0, $p / 11 - 300 / 11 - ($com + $cargo + $svc) / 6);
$exp = $p - 300 - $com - $cargo - $svc - $stop - $netVat;
ok(near($r['options'][1]['profits']['4 gün'], $exp), '2. aralık 4 gün kâr elle hesapla aynı (' . round($exp, 2) . ')');
ok($r['options'][1]['worst'] === min($r['options'][1]['profits']), 'en kötü dönem kârı esas alınıyor');
// 4. aralık 13,6% komisyonla daha ucuz satış: 2. aralıktan farklı kâr
ok($r['options'][3]['profits']['3 gün'] !== $r['options'][1]['profits']['3 gün'], 'her aralık kendi komisyonuyla hesaplanıyor');
$r2 = $a['rows'][1];
ok($r2['options'][3]['cargo_note'] !== '' && str_contains($r2['options'][3]['cargo_note'], 'barem'), '300 TL altı fiyatta barem kargo uygulanıyor');
ok(near($r['tsf_ratio'], 799.05 / 791.06, 0.0001), 'TSF / müşteri fiyatı oranı (sepet indirimi) bulundu');
ok(near((float)$r['options'][1]['write']['AE'], round(799.05 * 799.05 / 791.06, 2)), 'yeni TSF hedef müşteri fiyatına indirim oranıyla çevrilir');
ok($r['options'][1]['write']['AH'] === '7 Günlük Fiyat', 'tarife seçimi 7 günlük');
ok(near($r['current_profit'], min($r['options'][1]['profits']) - 0, 30) && $r['current_profit'] < $r['options'][1]['profits']['3 gün'], 'bugünkü kâr da mevcut aralığın kötü dönemiyle (17,6%) hesaplanıyor');
ok($r['suggest'] === 'b2', '8li alez için 2. aralığın üst sınırı öneriliyor');
ok($a['rows'][2]['options'][0]['worst'] === null, 'maliyetsiz üründe kâr hesaplanmıyor');

echo "Dışa aktarma\n";
$dest = sys_get_temp_dir() . '/tarife-out-' . getmypid() . '.xlsx';
tariff_export($fx . 'komisyon-tarifesi.xlsx', $a['sheet'], [$r['row'] => $r['options'][2]['write']], $dest);
$back = xlsx_cells($dest)['rows'];
ok(near(tariff_num($back[2]['AE']), $r['options'][2]['write']['AE']) && $back[2]['AH'] === '7 Günlük Fiyat', 'seçilen satıra YENİ TSF ve tarife seçimi yazıldı');
ok(($back[3]['AE'] ?? '') === '' && ($back[3]['AL'] ?? '') === 'grp1', 'seçilmeyen satır dokunulmadı, gizli kolonlar korundu');
$orig = xlsx_cells($fx . 'komisyon-tarifesi.xlsx')['rows'];
$same = true; foreach ($orig as $rn => $cells) foreach ($cells as $c => $v) if (!($rn === 2 && in_array($c, ['AE', 'AH'], true)) && ($back[$rn][$c] ?? null) !== $v) $same = false;
ok($same, 'diğer tüm hücreler birebir aynı');
$z = new ZipArchive(); ok($z->open($dest) === true && $z->numFiles >= 5, 'çıktı geçerli xlsx'); $z->close();
$py = @shell_exec('python3 -c "import openpyxl,sys;wb=openpyxl.load_workbook(sys.argv[1]);print(wb.active[\'AE2\'].value)" ' . escapeshellarg($dest) . ' 2>&1');
if ($py !== null && !str_contains((string)$py, 'No module')) ok(near((float)trim((string)$py), $r['options'][2]['write']['AE']), 'openpyxl çıktıyı okuyabiliyor');
@unlink($dest);

echo "Plus / Etiket / Flaş\n";
$a = tariff_analyze($uid, $mp, $fx . 'plus-tarifesi.xlsx');
ok($a['type'] === 'plus' && count($a['rows']) === 2, 'Plus tanındı');
$o = $a['rows'][1]['options'][0];
ok(near($o['price'], 597.35) && $o['rates'] === ['3 gün' => 2.7, '4 gün' => 9.7], 'Plus üst limit ve Plus komisyon teklifleri');
ok(empty($o['active']), 'Plus seçilmemiş satır aktif değil');
ok(near((float)$o['write']['V'], 597.35) && $o['write']['W'] === '7 Günlük Fiyat', 'Plus Fiyat Seçimi + Tarife Seçimi yazılacak');
$a = tariff_analyze($uid, $mp, $fx . 'avantajli-etiket.xlsx');
$r = $a['rows'][0];
ok($a['type'] === 'etiket' && count($r['options']) === 3, 'Avantajlı etiket: 3 yıldız');
ok(near($r['options'][0]['price'], 682.88) && near($r['options'][2]['price'], 553.49), 'virgüllü Türkçe sayılar okunuyor');
$exp = scenario_profit($uid, user_settings($uid), products_by_barcode($uid, $mp)['1cmyollugriset'], 553.49, null)['profit'];
ok(near($r['options'][2]['worst'], $exp), 'etikette komisyon ürünün kendi oranı (%19)');
ok(near((float)$r['options'][2]['write']['S'], round(553.49 * 600 / 520.56, 2)), 'etikette yeni TSF indirim oranıyla');
$a = tariff_analyze($uid, $mp, $fx . 'flas-urunler.xlsx');
ok($a['type'] === 'flas' && count($a['rows']) === 2 && $a['unmatched'] === 1, 'Flaş tanındı');
ok(near((float)$a['rows'][1]['options'][0]['write']['O'], 416.93), 'flaş fiyatı "Senin Belirlediğin Flaş Fiyatı" kolonuna');

echo "Gerçek Trendyol dosyası (Plus, 27.11.2026)\n";
$real = $fx . 'gercek/plus-tarifesi-2026-11-27.xlsx';
$a = tariff_analyze($uid, $mp, $real);
ok($a['type'] === 'plus' && count($a['rows']) === 3, 'gerçek Plus dosyası tanındı, 3 ürün');
$byB = array_column($a['rows'], null, 'barcode');
ok(!empty($byB['yastikalezi50x70']['options'][0]['active']), 'panelde daha önce yapılmış Plus seçimi "şu an seçili" algılandı');
ok(empty($byB['kanepecan']['options'][0]['active']), 'seçim yapılmamış satır aktif değil');
$k = $byB['kanepecan'];
$dest = sys_get_temp_dir() . '/tarife-real-' . getmypid() . '.xlsx';
tariff_export($real, $a['sheet'], [$k['row'] => $k['options'][0]['write']], $dest);
$before = xlsx_cells($real)['rows']; $after = xlsx_cells($dest)['rows'];
$diff = [];
foreach ($after as $rn => $cells) foreach ($cells as $c => $v) if (($before[$rn][$c] ?? '') !== $v) $diff[] = $c . $rn;
foreach ($before as $rn => $cells) foreach ($cells as $c => $v) if (!array_key_exists($c, $after[$rn] ?? [])) $diff[] = $c . $rn . '(silindi)';
sort($diff);
ok($diff === ['V' . $k['row'], 'W' . $k['row']], 'dışa aktarmada yalnız Plus Fiyat Seçimi ve Tarife Seçimi değişti (' . implode(',', $diff) . ')');
ok(near(tariff_num($after[$k['row']]['V']), 343.5) && $after[$k['row']]['W'] === '7 Günlük Fiyat', 'yazılan değerler Trendyol\'un kendi seçim biçimiyle aynı');
$z = new ZipArchive(); $z->open($dest);
$xmlOut = $z->getFromName('xl/worksheets/sheet1.xml'); $z->close();
$z = new ZipArchive(); $z->open($real);
$same = $z->getFromName('xl/styles.xml'); $z->close();
$z = new ZipArchive(); $z->open($dest); $sameOut = $z->getFromName('xl/styles.xml'); $z->close();
ok($same === $sameOut && str_contains($xmlOut, '<dataValidations') && str_contains($xmlOut, 'SEARCH("7 Günlük"'), 'stiller, açılır listeler ve formüller korundu');
@unlink($dest);

echo "Kampanyalar (Excel ile Toplu İşlem)\n";
$t = campaign_terms("Ev Ürünlerinde Çok Avantajlı Fiyat'tan %10 İndirim - %40 Trendyol Karşılamalı - 395526");
ok($t['pct'] === 10.0 && $t['cover'] === 40.0 && $t['id'] === '395526', 'kampanya adından %10 indirim, %40 karşılama, ID');
$t = campaign_terms("Ev Ürünlerinde 500 TL Üzerine 50 TL İndirim! - %25 Trendyol Karşılamalı - 394367");
ok($t['min'] === 500.0 && $t['tl'] === 50.0 && $t['pct'] === null && $t['cover'] === 25.0, '500 TL üzerine 50 TL, %25 karşılama');
$u = campaign_unit($t, 841.1);
ok(near($u['customer'], 791.1) && near($u['seller_discount'], 37.5) && near($u['net'], 803.6), 'müşteri 791,10 öder, satıcı 37,50 karşılar, satıcıya 803,60 kalır');
$a = tariff_analyze($uid, $mp, $fx . 'kampanya-coklu.xlsx');
ok($a['type'] === 'kampanya' && $a['multi'] && count($a['campaigns']) === 3, 'kampanya dosyası: 3 kampanya, çoklu seçim');
$byB = array_column($a['rows'], null, 'barcode');
$o8 = $byB['bamboyastik8li']['options'];
ok(near($o8[0]['price'], 687.18) && $o8[0]['write'] === ['L' => 687.18], 'katılım fiyatı maksimum fiyat, giriş kolonuna yazılır');
ok(near($o8[2]['sale'], 803.6), 'TL indirimli kampanyada satıcıya kalan tutar');
ok(near($o8[0]['rate_used'], 15.8), 'indirimli fiyat 3. komisyon aralığına düşüyor: kayıtlı tarifeden %15,8 (kötü dönem)');
ok(near($o8[2]['rate_used'], 21.0), '803,60 TL 1. aralıkta: %21');
ok(!empty($byB['10adetbamboyastik']['options'][2]['ineligible']) && $byB['10adetbamboyastik']['options'][2]['write'] === [], '"Uygun değil" kampanya seçilemez');
ok(str_contains($byB['yastikalezi50x70']['options'][2]['note'], 'sepet şartı'), '500 TL altı üründe TL indirimi tek üründe uygulanmıyor notu');
$dest = sys_get_temp_dir() . '/kamp-' . getmypid() . '.xlsx';
tariff_export($fx . 'kampanya-coklu.xlsx', $a['sheet'], [$byB['bamboyastik8li']['row'] => $o8[0]['write'] + $o8[1]['write']], $dest);
$back = xlsx_cells($dest)['rows'];
ok(near(tariff_num($back[4]['L']), 687.18) && near(tariff_num($back[4]['N']), 723.35) && ($back[4]['P'] ?? '') === '', 'iki kampanyaya birden katılım tek satıra yazıldı');
ok(($back[1]['K'] ?? '') === "Ev Ürünlerinde Çok Avantajlı Fiyat'tan %5 İndirim - %25 Trendyol Karşılamalı - 394414", 'kampanya başlık satırı korundu');
@unlink($dest);

echo "İndirim simülasyonu\n";
$sim = array_column(discount_simulate($uid, $mp, ['type' => 'yuzde', 'pct' => 10]), null, 'barcode');
$s8 = $sim['bamboyastik8li'];
ok(near($s8['unit'], 719.15) && near($s8['new_rate'], 15.8), '%10 sepet indirimi: 719,15 TL, komisyon aralığı değişiyor (%15,8)');
ok($s8['new'] < $s8['now'] && $s8['max_pct'] > 10 && $s8['max_pct'] < 95, 'kâr düşüyor, zararsız en yüksek indirim hesaplandı (%' . $s8['max_pct'] . ')');
$z = scenario_profit($uid, user_settings($uid), products_by_barcode($uid, $mp)['bamboyastik8li'], 799.05 * (1 - $s8['max_pct'] / 100), null)['profit'];
ok(abs($z) < 3, 'zararsız indirim sınırında kâr ≈ 0 (' . round($z, 2) . ')');
$sim = array_column(discount_simulate($uid, $mp, ['type' => 'xaly', 'x' => 3, 'y' => 2]), null, 'barcode');
ok($sim['yastikalezi50x70']['qty'] === 3 && near($sim['yastikalezi50x70']['unit'], 199.93), '3 al 2 öde: 3 adetlik sipariş, birim 199,93');
$sim = array_column(discount_simulate($uid, $mp, ['type' => 'tl', 'min' => 500, 'tl' => 50]), null, 'barcode');
ok($sim['yastikalezi50x70']['qty'] === 2 && near($sim['yastikalezi50x70']['unit'], 274.9), '500 TL\'ye 50 TL: 299,90\'lık üründe 2 adet, birim 274,90');
ok($sim['kanepecan']['new'] === null && $sim['kanepecan']['note'] === 'maliyet girilmemiş', 'maliyetsiz ürün atlanıyor');

echo "Hatalı dosya\n";
$bad = sys_get_temp_dir() . '/bad-' . getmypid() . '.xlsx';
$z = new ZipArchive(); $z->open($bad, ZipArchive::CREATE); $z->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Merhaba</t></is></c></row></sheetData></worksheet>'); $z->close();
try { tariff_analyze($uid, $mp, $bad); ok(false, 'tanınmayan dosya hata vermeli'); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), 'tanınmadı'), 'tanınmayan dosyada anlaşılır hata'); }
@unlink($bad);

@unlink($db);
echo "\n" . ($n - $fail) . "/$n geçti\n";
exit($fail ? 1 : 0);
