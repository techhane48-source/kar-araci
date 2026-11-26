<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = require_login();

// Bağlı pazaryerleri
$ints = db()->prepare("SELECT marketplace FROM integrations WHERE user_id = ?");
$ints->execute([$u['id']]);
$mps = array_column($ints->fetchAll(), 'marketplace');
if (!$mps) $mps = ['trendyol'];
$mp = $_GET['mp'] ?? $mps[0];
if (!in_array($mp, $mps, true)) $mp = $mps[0];

// Tarih aralığı (gün)
$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [1, 7, 15, 30], true)) $days = 30;
$fromTs = strtotime('today') - ($days - 1) * 86400;
$aralikLabel = $days === 1 ? 'Bugün' : ('Son ' . $days . ' gün');

// Ürünler (barkod -> ürün) — maliyet girdisi kârlılığın temeli
$ps = db()->prepare("SELECT * FROM products WHERE user_id = ? AND marketplace = ?");
$ps->execute([$u['id'], $mp]);
$byBarcode = [];
$toplamUrun = 0;
$maliyetGirilen = 0;
$catalogProducts=$ps->fetchAll();
$catalogAlerts=$mp==='trendyol'?alert_catalog_summary($catalogProducts,target_rules(db(),(int)$u['id']),time()):null;
foreach ($catalogProducts as $p) {
    $toplamUrun++;
    if ($p['barcode'] !== '') $byBarcode[$p['barcode']] = $p;
    if ((float)$p['cost'] > 0) $maliyetGirilen++;
}

// Siparişler
$q = db()->prepare("SELECT * FROM orders WHERE user_id = ? AND marketplace = ? AND order_date >= ? ORDER BY order_date DESC");
$q->execute([$u['id'], $mp, $fromTs]);
$expenseEvidence=$mp==='trendyol'?period_expense_evidence(db(),(int)$u['id'],$fromTs,time()):null;
$tariffState=$mp==='trendyol'?cargo_tariff_status(cargo_tariffs(),(new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul')))->format('Y-m-d')):null;
$orders = commission_profit_overlay(db(),(int)$u['id'],cargo_profit_overlay(db(),(int)$u['id'],cost_history_overlay(db(),(int)$u['id'],$q->fetchAll())));

// Aggregasyon
$ciro = 0.0; $maliyetliCiro = 0.0;
$urunMaliyeti = 0.0; $komisyon = 0.0; $kargo = 0.0;
$hizmet = 0.0; $stopaj = 0.0; $netKdv = 0.0;
$reklam = 0.0; $ceza = 0.0; $erkenOdeme = 0.0; $diger = 0.0;
$siparis = 0; $maliyetliSiparis = 0; $urunAdet = 0;
$profitGaps=[];

foreach ($orders as $o) {
    if (!order_is_active_sale($o)) continue;
    $o['lines'] = is_array($o['lines'])?$o['lines']:(json_decode($o['lines']??'[]',true)?:[]);
    $sale = (float)$o['total_price'];
    $ciro += $sale;
    $siparis++;
    foreach ($o['lines'] as $l) $urunAdet += max(1, (int)($l['quantity'] ?? 1));

    $pr = calc_order_profit($o, $byBarcode);
    if ($pr['ok']) {
        $maliyetliCiro += $sale;
        $maliyetliSiparis++;
        $urunMaliyeti += $pr['cost'];
        $komisyon += $pr['commission'];
        $kargo += $pr['cargo'];
        $hizmet += $pr['service_fee'];
        $stopaj += $pr['stopaj'];
        $netKdv += $pr['net_kdv'];
    }else{
        $reason=(string)($pr['reason']??'Veri eksik');
        if(!isset($profitGaps[$reason]))$profitGaps[$reason]=['count'=>0,'sale'=>0.0];
        $profitGaps[$reason]['count']++;$profitGaps[$reason]['sale']+=$sale;
    }
}

// Bugün saatlik (dashboard mini grafik)
$todayHourly = array_fill(0, 24, 0.0);
foreach ($orders as $o) {
    if (!order_is_active_sale($o)) continue;
    if ((int)$o['order_date'] >= strtotime('today')) {
        $todayHourly[(int)date('G', (int)$o['order_date'])] += (float)$o['total_price'];
    }
}

// Brüt & Net kâr (Melontik ayrımı)
$brutKar = $maliyetliCiro - $urunMaliyeti - $komisyon - $kargo - $hizmet - $stopaj - $netKdv;
$netKar = $brutKar; // Order estimate only; unmatched store expenses are unknown.
$karSatis = $maliyetliCiro > 0 ? $netKar / $maliyetliCiro * 100 : 0.0;
$karMaliyet = $urunMaliyeti > 0 ? $netKar / $urunMaliyeti * 100 : 0.0;
$ortKomisyon = $maliyetliCiro > 0 ? $komisyon / $maliyetliCiro * 100 : 0.0;

// Funnel (Kâr Performansı) — maliyetli ciro üzerinden (dengeli)
$funnel = [
    'ciro'          => $maliyetliCiro,
    'kargoSonrasi'  => $maliyetliCiro - $kargo,
    'pzSonrasi'     => $maliyetliCiro - $kargo - $komisyon - $hizmet,
    'vergiSonrasi'  => $maliyetliCiro - $kargo - $komisyon - $hizmet - $stopaj - $netKdv,
    'kar'           => $brutKar,
];

$pageTitle = 'Dashboard — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar">
    <h1>Dashboard</h1>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <select onchange="location.href='?mp='+encodeURIComponent(this.value)+'&days=<?= $days ?>'" style="width:auto">
        <?php foreach ($mps as $c): ?>
          <option value="<?= $c ?>" <?= $mp === $c ? 'selected' : '' ?>><?= h(marketplace_label($c)) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="btn-group">
        <?php foreach ([1 => 'Bugün', 7 => '7 gün', 15 => '15 gün', 30 => '30 gün'] as $d => $lbl): ?>
          <a href="?mp=<?= $mp ?>&days=<?= $d ?>" class="btn-ghost <?= $days === $d ? 'btn-active' : '' ?>" style="text-decoration:none"><?= $lbl ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php if($catalogAlerts!==null): ?><div class="card" style="margin:12px 0"><h2>Mağaza kontrolü</h2>
  <p><?= $catalogAlerts['products'] ?> ürün · <?= $catalogAlerts['evaluated'] ?> kâr hesabı · <?= $catalogAlerts['flagged'] ?> uyarılı ürün</p>
  <p><?= $catalogAlerts['counts']['loss'] ?> tahmini zarar · <?= $catalogAlerts['counts']['below_target'] ?> hedef altı · <?= $catalogAlerts['counts']['missing_cost'] ?> maliyet eksik</p>
  <a href="uyarilar.php">Uyarı merkezini aç</a> · <a href="kar-hedefleri.php">Kâr hedefleri</a></div><?php endif; ?>
  <?php if($tariffState!==null): ?>
  <section class="card" style="margin:12px 0" aria-labelledby="tariff-status">
    <h2 id="tariff-status">Kargo tarifesi durumu</h2>
    <p class="hint"><?php echo h(['operator_reference'=>'Bugün için yönetici tarafından eklenmiş tarihli tarife mevcut; resmî doğrulama değildir.','secondary_partial'=>'Bugün için yalnız sınırlı ikincil kaynak tahmini var.','conflict'=>'Tarife tarihleri çakışıyor; belirsiz fiyatlar kullanılmaz.','missing'=>'Bugün için geçerli tarife bulunamadı; eksik kargo fiyatı sıfır kabul edilmez.'][$tariffState['state']]); ?></p>
    <?php if($tariffState['state']==='missing'&&$tariffState['last_expired_through']!==null): ?><p class="hint">Son süresi dolan tarife: <?=h($tariffState['last_expired_through'])?></p><?php endif; ?>
    <a href="raporlar.php?r=cargo_effective">Kargo tahminleri ve faturalar</a>
  </section>
  <?php endif; ?>
  <?php if (!$orders): ?>
    <div class="card" style="padding:32px;text-align:center">
      <h2>Henüz veri yok</h2>
      <p class="hint" style="max-width:520px;margin:8px auto">
        <?= $toplamUrun === 0
            ? 'Mağazanı bağla, siparişler ve kârlılığın otomatik gelsin. <a href="magaza.php">Mağaza Bağla →</a>'
            : 'Siparişler senkron edilmedi. <a href="canli-performans.php">Canlı Performans</a> sayfasından siparişleri senkronize et.' ?>
      </p>
    </div>
  <?php else: ?>

  <div class="kpi-row">
    <div class="kpi"><div class="k-label">Toplam Ciro</div><div class="k-value"><?= tl($ciro) ?></div></div>
    <div class="kpi"><div class="k-label">Kârı Hesaplanabilen Ciro</div><div class="k-value"><?= tl($maliyetliCiro) ?></div></div>
    <div class="kpi"><div class="k-label">Kesin mağaza net kârı</div><div class="k-value">—</div></div>
    <div class="kpi"><div class="k-label">Tahmini Sipariş Kârı</div><div class="k-value <?= $netKar < 0 ? 'neg' : 'pos' ?>"><?= $maliyetliSiparis > 0 ? tl($netKar) : '—' ?></div></div>
    <div class="kpi"><div class="k-label">Tahmini Sipariş Kârı / Satış</div><div class="k-value <?= $karSatis < 0 ? 'neg' : '' ?>"><?= $maliyetliSiparis>0?'%'.number_format($karSatis,1,',','.'):'—' ?></div></div>
    <div class="kpi"><div class="k-label">Tahmini Sipariş Kârı / Maliyet</div><div class="k-value <?= $karMaliyet < 0 ? 'neg' : '' ?>"><?= $maliyetliSiparis>0?'%'.number_format($karMaliyet,1,',','.'):'—' ?></div></div>
  </div>

  <?php $excludedCurrency=count(array_filter($orders,static fn($o)=>!order_is_domestic_try($o))); if($excludedCurrency): ?><p class="hint"><?= $excludedCurrency ?> paket ülke/para birimi doğrulanmadığından TL cirosuna dahil edilmedi. Eski kayıtları tekrar senkronize edin.</p><?php endif; ?>
  <p class="hint">Kâr tahmini <?= $maliyetliSiparis ?>/<?= $siparis ?> paket üzerinden; reklam, ceza ve diğer mağaza giderleri henüz kesin net kâra işlenmiyor.</p>
  <?php if($expenseEvidence!==null): ?>
  <div class="card table-card" style="margin:12px 0">
    <h2>Dönemde kaydedilen gerçek finans hareketleri</h2>
    <p class="hint">İşlem tarihine göre; aşağıdaki tutarlar sipariş tarihi bazlı tahmini kârdan tekrar düşülmez. Stopaj vergi avansı/nakit kesintisidir. Fatura KDV niteliği ve siparişe dağılımı ayrıca doğrulanmalıdır.</p>
    <table><tr><th>Hareket</th><th>Borç</th><th>Alacak / iptal</th><th>Gözlemlenen net kesinti</th><th>Kapsam</th></tr>
    <?php foreach(['service'=>'Platform hizmet bedeli','withholding'=>'E-ticaret stopajı','cargo'=>'Kargo faturaları','other_invoice'=>'Diğer hizmet faturaları','other_adjustment'=>'Diğer düzeltme / iade belgeleri'] as $key=>$label):$g=$expenseEvidence['groups'][$key]; ?>
      <tr><td><?=h($label)?></td><td><?=$g['count']?tl($g['debt']/10000.0):'—'?></td><td><?=$g['count']?tl($g['credit']/10000.0):'—'?></td><td><?=$g['observed_net']===null?'—':tl($g['observed_net'])?></td><td><?= $g['coverage_complete']?'Aralık sorgulandı':'Eksik / doğrulanmadı' ?> (<?=$g['count']?> kayıt, <?=$g['excluded']?> hariç)</td></tr>
    <?php endforeach; ?>
    </table>
    <p><a href="raporlar.php?r=period_finance&amp;days=<?=in_array($days,[7,15,30],true)?$days:7?>">Dönem finans hareketleri</a></p>
  </div>
  <?php endif; ?>
  <?php if ($profitGaps): ?>
    <section class="card table-card" style="margin:12px 0" aria-labelledby="profit-coverage">
      <h2 id="profit-coverage">Kâr hesabı kapsamı ve eksik kanıtlar</h2>
      <p class="hint"><?= $maliyetliSiparis ?> / <?= $siparis ?> paket için tahmini hesap yapılabildi. Eksik paketler sıfır kâr veya zarar olarak kabul edilmez.</p>
      <table><thead><tr><th scope="col">Hesaplanamama nedeni</th><th scope="col">Paket</th><th scope="col">İlgili ciro</th></tr></thead><tbody>
      <?php foreach($profitGaps as $reason=>$gap): ?>
        <tr><td><?= h($reason) ?></td><td><?= $gap['count'] ?></td><td><?= tl($gap['sale']) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      <p><a href="urunler.php">Ürün maliyetleri</a> · <a href="raporlar.php?r=order_finance">Finansal eşleştirme</a> · <a href="kargo-kontrol.php">Kargo kontrolü</a></p>
    </section>
  <?php endif; ?>

  <div class="card table-card" style="margin:16px 0">
    <h2>Kâr Performansı (<?= h($aralikLabel) ?>)</h2>
    <div style="max-width:720px;margin-top:8px">
      <?php
      $maxW = max(1.0, $funnel['ciro']);
      $funnelRows = [
        ['Ciro', $funnel['ciro']],
        ['Kargo ücreti düşülmüş tutar', $funnel['kargoSonrasi']],
        ['Pazaryeri masrafı düşülmüş tutar', $funnel['pzSonrasi']],
        ['Vergi düşülmüş tutar', $funnel['vergiSonrasi']],
        ['Ürün Maliyeti Düşülmüş Tutar (Kâr)', $funnel['kar']],
      ];
      foreach ($funnelRows as $row):
        $pct = max(0.0, $row[1] / $maxW * 100);
      ?>
        <div style="display:flex;align-items:center;gap:12px;margin:6px 0">
          <div style="width:200px;font-size:13px;color:var(--muted)"><?= $row[0] ?></div>
          <div style="flex:1;background:var(--border);border-radius:6px;height:22px;position:relative">
            <div style="position:absolute;left:0;top:0;bottom:0;border-radius:6px;background:var(--accent);width:<?= $pct ?>%"></div>
          </div>
          <div style="width:110px;text-align:right;font-weight:600;font-size:14px"><?= tl($row[1]) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card table-card" style="margin:16px 0">
    <h2>Bugün Saatlik Satış</h2>
    <?php $maxH = max(1.0, max($todayHourly)); ?>
    <div style="display:flex;gap:4px;align-items:flex-end;height:90px;margin:8px 0 4px;padding:0 2px">
      <?php for ($h = 0; $h < 24; $h++): $sh = $todayHourly[$h] / $maxH * 100; ?>
        <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center" title="<?= str_pad((string)$h,2,'0',STR_PAD_LEFT) ?>:00 — <?= tl($todayHourly[$h]) ?>">
          <div style="width:100%;background:var(--accent);border-radius:2px 2px 0 0;height:<?= max(1, $sh) ?>%;opacity:<?= $todayHourly[$h] > 0 ? 1 : 0.15 ?>"></div>
        </div>
      <?php endfor; ?>
    </div>
    <p class="hint">Saat etiketleri 00-23. Bugün henüz sipariş yoksa çubuklar boş.</p>
  </div>

  <div class="card table-card" style="margin:16px 0">
    <h2>Masraf Kalemleri</h2>
    <table>
      <tr><td>Ürün Maliyeti</td><td class="tar"><?= tl($urunMaliyeti) ?></td></tr>
      <tr><td>Komisyon</td><td class="tar"><?= tl($komisyon) ?></td></tr>
      <tr><td>Kargo Ücreti</td><td class="tar"><?= tl($kargo) ?></td></tr>
      <tr><td>Tahmini Hizmet Bedeli</td><td class="tar"><?= tl($hizmet) ?></td></tr>
      <tr><td>Tahmini Stopaj Kesintisi</td><td class="tar"><?= tl($stopaj) ?></td></tr>
      <tr><td>Net KDV</td><td class="tar"><?= tl($netKdv) ?></td></tr>
      <tr><td>Reklam Harcaması</td><td class="tar">— <span class="hint">(mutabakat yok)</span></td></tr>
      <tr><td>Ceza</td><td class="tar">— <span class="hint">(doğrulanmadı)</span></td></tr>
      <tr><td>Erken Ödeme Kesintisi</td><td class="tar">— <span class="hint">(doğrulanmadı)</span></td></tr>
      <tr><td>Diğer Faturalar</td><td class="tar">— <span class="hint">(doğrulanmadı)</span></td></tr>
    </table>
  </div>

  <div class="card table-card" style="margin:16px 0">
    <h2>Metrikler</h2>
    <table>
      <tr><th></th><th class="tar">Değer</th></tr>
      <tr><td>Sipariş sayısı</td><td class="tar"><?= $siparis ?></td></tr>
      <tr><td>Maliyet girilen sipariş</td><td class="tar"><?= $maliyetliSiparis ?></td></tr>
      <tr><td>Satılan ürün adedi</td><td class="tar"><?= $urunAdet ?></td></tr>
      <tr><td>Sipariş başına ortalama satış</td><td class="tar"><?= $siparis > 0 ? tl($ciro / $siparis) : tl(0) ?></td></tr>
      <tr><td>Ortalama komisyon oranı</td><td class="tar">%<?= number_format($ortKomisyon, 1, ',', '.') ?></td></tr>
      <tr><td>Maliyet girilen ürün (<?= $toplamUrun ?> üründen)</td><td class="tar"><?= $maliyetGirilen ?></td></tr>
    </table>
  </div>

  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
