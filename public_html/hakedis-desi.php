<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = require_login();
$pro = is_pro($u);
if (!$pro) {
    flash_set('error', 'Hakediş & Desi Kontrolü Pro plan özelliğidir.');
    e_redirect('odeme.php');
}

$tab = $_GET['tab'] ?? ($_POST['tab'] ?? 'desi');
if (!in_array($tab, ['desi', 'hakedis'], true)) $tab = 'desi';

// Kullanıcının bağladığı pazaryerleri
$ints = db()->prepare("SELECT marketplace FROM integrations WHERE user_id = ?");
$ints->execute([$u['id']]);
$mps = array_column($ints->fetchAll(), 'marketplace');
if (!$mps) $mps = ['trendyol'];
$mp = $_POST['mp'] ?? $_GET['mp'] ?? $mps[0];
if (!in_array($mp, $mps, true)) $mp = $mps[0];

// --- Desi kaydet ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_desi') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_set('error', 'Oturum süresi doldu.');
    } else {
        $db=db();$n=0;
        try{
            if(!is_array($_POST['en']??null)||count($_POST['en'])>100)throw new InvalidArgumentException('En fazla 100 ürün.');
            $upd=$db->prepare("UPDATE products SET en=?,boy=?,yukseklik=?,kesilen_desi=?,expected_desi=? WHERE id=? AND user_id=? AND marketplace=?");
            $db->beginTransaction();
            foreach(array_keys($_POST['en']) as $id){
                $vals=[];
                foreach(['en','boy','yukseklik','kesilen','expected_desi'] as $key){
                    $v=$_POST[$key][$id]??'';
                    if(!is_string($v))throw new InvalidArgumentException('Geçersiz ölçü.');
                    $num=$v===''?0:parse_money_input($v);
                    if($num===null||$num<0||$num>100000)throw new InvalidArgumentException('Geçersiz ölçü.');
                    $vals[]=$num;
                }
                $upd->execute(array_merge($vals,[(int)$id,$u['id'],$mp]));$n+=$upd->rowCount();
            }
            $db->commit();flash_set('success',$n.' ürünün desi bilgisi kaydedildi.');
        }catch(Throwable $ex){if($db->inTransaction())$db->rollBack();flash_set('error','Ölçüler kaydedilemedi; girişleri kontrol edin.');}
    }
    e_redirect('hakedis-desi.php?tab=desi&mp=' . $mp);
}

// --- Hakediş kontrolü ---
$hakedisResult = null;
$officialCargo = [];
$officialPayments = [];
$payouts=[]; $payoutPage=max(0,(int)($_POST['payout_page']??$_GET['payout_page']??0));
$payoutMessage=null;
if ($mp==='trendyol' && $_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['action']??'',['payout_list','payout_check'],true)) {
    if (!csrf_verify($_POST['csrf']??'')) $payoutMessage='Oturum süresi doldu.';
    else {
        set_time_limit(0);
        $r=($_POST['action']==='payout_list')?sync_trendyol_payout_page((int)$u['id'],$payoutPage):
            check_trendyol_payout((int)$u['id'],(string)($_POST['payment_id']??''));
        $payoutMessage=$r['ok']?'Resmî kayıtlar alındı.':($r['error']??'Sorgu tamamlanmadı.');
    }
}
if ($mp === 'trendyol') {
    $integration=get_integration((int)$u['id'],'trendyol');
    if($integration) {
        $st=db()->prepare("SELECT * FROM trendyol_payouts WHERE user_id=? AND seller_id=? ORDER BY payout_date DESC LIMIT 100");
        $st->execute([$u['id'],$integration['seller_id']]);$payouts=$st->fetchAll();
    }
    $cq = db()->prepare("SELECT order_number, shipment_type, COUNT(*) AS kalem, SUM(amount) AS tutar, SUM(desi) AS desi FROM trendyol_cargo_items WHERE user_id=? GROUP BY order_number, shipment_type ORDER BY order_number DESC LIMIT 100");
    $cq->execute([$u['id']]); $officialCargo = $cq->fetchAll();
    $pq = db()->prepare("SELECT payment_order_id, transaction_date, raw_json FROM trendyol_financial_documents WHERE user_id=? AND query_type='PaymentOrder' ORDER BY transaction_date DESC LIMIT 100");
    $pq->execute([$u['id']]); $officialPayments = $pq->fetchAll();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_hakedis') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_set('error', 'Oturum süresi doldu.');
    } else {
        $entered = (float)($_POST['hakedis_satis'] ?? 0);
        // Son 30 gün senkron sipariş toplamı
        $q = db()->prepare("SELECT COALESCE(SUM(total_price),0) FROM orders WHERE user_id = ? AND marketplace = ? AND order_date >= ? AND (marketplace!='trendyol' OR (currency_code='TRY' AND country_code='TR')) AND LOWER(status) NOT IN ('cancelled','canceled','unsupplied','returned','undelivered','unpacked')");
        $q->execute([$u['id'], $mp, time() - 30 * 86400]);
        $synced = (float)$q->fetchColumn();

        // Fazla desi kesilen ürünler
        $ps = db()->prepare("SELECT * FROM products WHERE user_id = ? AND marketplace = ?");
        $ps->execute([$u['id'], $mp]);
        $fazlaDesi = [];
        foreach ($ps->fetchAll() as $p) {
            $beklenen = desi_from_dims((float)$p['en'], (float)$p['boy'], (float)$p['yukseklik']);
            $kesilen = (float)$p['kesilen_desi'];
            if ($beklenen > 0 && $kesilen > $beklenen + 0.01) {
                $fazlaDesi[] = ['name' => $p['name'], 'beklenen' => $beklenen, 'kesilen' => $kesilen];
            }
        }

        $eksikSatis = $entered > 0 && $synced > 0 && $entered < $synced - 0.01;
        $hakedisResult = [
            'entered' => $entered,
            'synced' => $synced,
            'eksikSatis' => $eksikSatis,
            'fazlaDesi' => $fazlaDesi,
        ];
    }
}

// Ürünler (desi tabı için) — sayfalı + arama (14k+ ürün için performans)
$dq = trim($_GET['dq'] ?? '');
$dwhere = 'user_id = ? AND marketplace = ?';
$dargs = [$u['id'], $mp];
if ($dq !== '') {
    $dwhere .= ' AND (name LIKE ? OR barcode LIKE ?)';
    $dargs[] = '%' . $dq . '%';
    $dargs[] = '%' . $dq . '%';
}
$dc = db()->prepare("SELECT COUNT(*) c FROM products WHERE $dwhere");
$dc->execute($dargs);
$dtotal = (int)$dc->fetch()['c'];
$dperPage = 100;
$dpage = max(1, (int)($_GET['dpage'] ?? 1));
$dtotalPages = max(1, (int)ceil($dtotal / $dperPage));
$dpage = min($dpage, $dtotalPages);
$doffset = ($dpage - 1) * $dperPage;
$ps = db()->prepare("SELECT * FROM products WHERE $dwhere ORDER BY name LIMIT $dperPage OFFSET $doffset");
$ps->execute($dargs);
$products = $ps->fetchAll();

$pageTitle = 'Hakediş & Desi Kontrolü — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar">
    <h1>Hakediş &amp; Desi Kontrolü</h1>
    <form method="get" style="display:flex;gap:8px;align-items:center">
      <select name="mp" style="width:auto" onchange="this.form.submit()">
        <?php foreach ($mps as $c): ?>
          <option value="<?= $c ?>" <?= $mp === $c ? 'selected' : '' ?>><?= h(marketplace_label($c)) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
    </form>
  </div>

  <div class="tabbar" style="margin:12px 0">
    <a href="hakedis-desi.php?tab=desi&mp=<?= h($mp) ?>" class="tab <?= $tab === 'desi' ? 'active' : '' ?>">Desi Kontrolü</a>
    <a href="hakedis-desi.php?tab=hakedis&mp=<?= h($mp) ?>" class="tab <?= $tab === 'hakedis' ? 'active' : '' ?>">Hakediş Kontrolü</a>
  </div>

  <?php $flash = flash_get(); if ($flash): ?><div class="alert alert-<?= $flash['type'] ?>"><?= h($flash['msg']) ?></div><?php endif; ?>

  <?php if ($tab === 'desi'): ?>
  <div class="card table-card" style="margin:16px 0">
    <h2>Desi Kontrolü</h2><p><a href="raporlar.php?r=cargo_effective">Tahmini / faturalanmış kargo raporu</a></p>
    <p class="hint" style="max-width:760px">Ürünün <b>en × boy × yükseklik (cm)</b> ölçülerini gir. "Olması gereken desi" otomatik hesaplanır (÷3000). Kargo firmasının kestiği <b>desi</b>'yi de gir — kesilen desi olması gerekenden büyükse <b>fazla kargo</b> kesilmiş olabilir.</p>
    <form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
      <input type="hidden" name="tab" value="desi">
      <input type="hidden" name="mp" value="<?= h($mp) ?>">
      <input type="text" name="dq" value="<?= h($dq) ?>" placeholder="Ürün adı veya barkod ara..." style="width:240px">
      <button type="submit" class="btn-ghost">Ara</button>
      <?php if ($dq !== ''): ?><a href="hakedis-desi.php?tab=desi&mp=<?= h($mp) ?>" class="btn-ghost" style="text-decoration:none">Temizle</a><?php endif; ?>
    </form>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_desi">
      <input type="hidden" name="tab" value="desi">
      <input type="hidden" name="mp" value="<?= h($mp) ?>">
      <table>
        <tr><th>Ürün</th><th>En (cm)</th><th>Boy (cm)</th><th>Yük (cm)</th><th>Olması Gereken Desi</th><th>Girilen paket desisi</th><th>Kesilen Desi</th><th>Durum</th></tr>
        <?php foreach ($products as $p): $beklenen = (float)($p['expected_desi']??0) ?: desi_from_dims((float)$p['en'], (float)$p['boy'], (float)$p['yukseklik']); $kesilen = (float)$p['kesilen_desi']; ?>
        <tr>
          <td><?= h($p['name']) ?></td>
          <td><input type="number" step="0.1" name="en[<?= $p['id'] ?>]" value="<?= (float)$p['en'] > 0 ? number_format((float)$p['en'], 1, '.', '') : '' ?>" style="width:70px"></td>
          <td><input type="number" step="0.1" name="boy[<?= $p['id'] ?>]" value="<?= (float)$p['boy'] > 0 ? number_format((float)$p['boy'], 1, '.', '') : '' ?>" style="width:70px"></td>
          <td><input type="number" step="0.1" name="yukseklik[<?= $p['id'] ?>]" value="<?= (float)$p['yukseklik'] > 0 ? number_format((float)$p['yukseklik'], 1, '.', '') : '' ?>" style="width:70px"></td>
          <td><?= $beklenen > 0 ? number_format($beklenen, 2, ',', '.') : '<span class="hint">—</span>' ?></td>
          <td><input type="number" min="0" max="500" step="0.01" name="expected_desi[<?= $p['id'] ?>]" value="<?= (float)($p['expected_desi']??0)>0?number_format((float)$p['expected_desi'],2,'.',''):'' ?>" style="width:80px"></td>
          <td><input type="number" step="0.01" name="kesilen[<?= $p['id'] ?>]" value="<?= $kesilen > 0 ? number_format($kesilen, 2, '.', '') : '' ?>" style="width:80px"></td>
          <td>
            <?php if ($beklenen > 0 && $kesilen > 0): ?>
              <?php if ($kesilen > $beklenen + 0.01): ?><span class="neg">▲ Fazla <?= number_format($kesilen - $beklenen, 2, ',', '.') ?> desi</span>
              <?php else: ?><span class="pos">● Normal</span><?php endif; ?>
            <?php else: ?><span class="hint">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="8" class="hint">Ürün yok. Önce ürünlerini senkronize et.</td></tr><?php endif; ?>
      </table>
      <?php if ($dtotalPages > 1): ?>
      <div style="display:flex;gap:8px;align-items:center;justify-content:center;margin-top:14px">
        <?php if ($dpage > 1): ?><a class="btn-ghost" style="text-decoration:none" href="hakedis-desi.php?tab=desi&mp=<?= h($mp) ?>&dq=<?= urlencode($dq) ?>&dpage=<?= $dpage - 1 ?>">← Önceki</a><?php endif; ?>
        <span class="hint">Sayfa <?= $dpage ?> / <?= $dtotalPages ?> (<?= $dtotal ?> ürün)</span>
        <?php if ($dpage < $dtotalPages): ?><a class="btn-ghost" style="text-decoration:none" href="hakedis-desi.php?tab=desi&mp=<?= h($mp) ?>&dq=<?= urlencode($dq) ?>&dpage=<?= $dpage + 1 ?>">Sonraki →</a><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($products): ?><button type="submit" class="btn-primary" style="margin-top:12px">Kaydet &amp; Kontrol Et</button><?php endif; ?>
    </form>
  </div>

  <?php else: ?>
  <div class="card" style="margin:16px 0;max-width:620px">
    <h2>Hakediş Kontrolü</h2>
    <p class="hint">Bu manuel karşılaştırma kesin hakediş mutabakatı değildir. Sipariş tarihi ile ödeme tarihi farklı olabilir. Resmî ödeme ve kargo kayıtları aşağıda ayrıca gösterilir.</p>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="check_hakedis">
      <input type="hidden" name="tab" value="hakedis">
      <input type="hidden" name="mp" value="<?= h($mp) ?>">
      <div class="field" style="margin:0"><label>Hakediş dönemi satış tutarı (₺)</label><input type="number" step="0.01" name="hakedis_satis" placeholder="örn. 45000.00" required style="width:200px"></div>
      <button type="submit" class="btn-primary">Kontrol Et</button>
    </form>

    <?php if ($hakedisResult): ?>
    <div style="margin-top:16px">
      <div class="alert alert-<?= ($hakedisResult['eksikSatis'] || $hakedisResult['fazlaDesi']) ? 'error' : 'success' ?>">
        <?php if (!$hakedisResult['eksikSatis'] && !$hakedisResult['fazlaDesi']): ?>
          Girilen değerlerde fark işareti görülmedi. Bu karşılaştırma, hakedişin veya kargo faturalarının doğru olduğunu kanıtlamaz.
        <?php else: ?>
          Dikkat gerektiren kalemler tespit edildi:
          <ul style="margin:8px 0 0 18px">
            <?php if ($hakedisResult['eksikSatis']): ?>
              <li><b>Manuel tutar farkı:</b> girilen <?= tl($hakedisResult['entered']) ?> &lt; senkron siparişler <?= tl($hakedisResult['synced']) ?></li>
            <?php endif; ?>
            <?php foreach ($hakedisResult['fazlaDesi'] as $f): ?>
              <li><b>Fazla kargo (desi):</b> <?= h($f['name']) ?> — kesilen <?= number_format($f['kesilen'], 2, ',', '.') ?> &gt; olması gereken <?= number_format($f['beklenen'], 2, ',', '.') ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <p class="hint">Karşılaştırma: senkron sipariş toplamı <b><?= tl($hakedisResult['synced']) ?></b> (son 30 gün).</p>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php if ($mp === 'trendyol'): ?>
<div class="wrap">
<div class="card table-card" style="margin:16px 0">
<h2>Ödeme emri bazlı finans kontrolü</h2><p><a class="btn-primary" href="banka-mutabakat.php">Banka dekontu karşılaştırması</a></p>
<p class="hint">Ödeme emrini seçerek ilgili tüm finansal işlem türlerini sorgulayın. Alacak ve borç kalemleri ayrı gösterilir; bunlar banka mutabakatı veya kesin net kâr değildir.</p>
<?php if($payoutMessage!==null): ?><p><?= h($payoutMessage) ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="mp" value="trendyol"><input type="hidden" name="tab" value="hakedis"><input type="hidden" name="action" value="payout_list"><label>API sayfası (0'dan başlar) <input type="number" name="payout_page" min="0" value="<?= $payoutPage ?>"></label><button>Ödeme emirlerini çek</button></form>
<table><tr><th>Ödeme emri</th><th>Ödeme tarihi</th><th>Resmî tutar</th><th>Finansal detay</th></tr>
<?php foreach($payouts as $po): $summary=json_decode($po['summary_json']??'null',true); ?>
<tr><td><?= h($po['payment_order_id']) ?></td><td><?= date('d.m.Y',(int)$po['payout_date']) ?></td><td><?= tl((float)$po['amount']) ?></td><td>
<form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="mp" value="trendyol"><input type="hidden" name="tab" value="hakedis"><input type="hidden" name="action" value="payout_check"><input type="hidden" name="payment_id" value="<?= h($po['payment_order_id']) ?>"><button>Kalemleri sorgula</button></form>
<?php if(is_array($summary)): ?><details><summary><?= (int)$summary['settlement_rows']+(int)$summary['document_rows'] ?> işlem</summary>
<?php if(is_array($summary['candidate_check']??null)&&($summary['candidate_check']['version']??0)===2): $check=$summary['candidate_check']; ?>
<p><b><?=h(['matched'=>'Üç Trendyol tutarı aritmetik olarak eşleşiyor','difference'=>'Hakediş farkı var','incomplete'=>'Finans kanıtı eksik / çelişkili'][$check['state']]??'Kontrol gerekli')?></b> · <?=!empty($po['checked_at'])?date('d.m.Y H:i',(int)$po['checked_at']):'—'?></p>
<p>Ekstre ödeme tutarı: <?= $check['statement_payment']===null?'—':tl((float)$check['statement_payment']) ?> · Ekstre / ödeme emri farkı: <?= $check['statement_difference']===null?'—':tl((float)$check['statement_difference']) ?></p>
<p>İşlem işaretlerinden hesaplanan ön kontrol: <?= $check['candidate']===null?'—':tl((float)$check['candidate']) ?> · Ön kontrol farkı: <?= $check['candidate_difference']===null?'—':tl((float)$check['candidate_difference']) ?></p>
<?php if($check['issues']): ?><p class="hint"><?= h(implode('; ',$check['issues'])) ?></p><?php endif; ?>
<p class="hint">Banka hareketi eşleştirilmedi. İşlem kalemlerinden hesaplanan ön kontrol henüz kesin hakediş değildir.</p>
<?php if(!empty($check['complete_inputs'])): ?>
<table><tr><th>İşlem</th><th>Alacak</th><th>Borç</th></tr><?php foreach($summary['by_type'] as $kind=>$v): ?><tr><td><?= h($kind) ?></td><td><?= tl((float)$v['credit']) ?></td><td><?= tl((float)$v['debt']) ?></td></tr><?php endforeach; ?></table>
<?php else: ?><p class="hint">Doğrulanmayan kayıtların toplu tutarları gösterilmez.</p><?php endif; ?>
<?php else: ?><p class="hint">Eski kontrol sürümü; otomatik yeniden sorgu gerekli.</p><?php endif; ?></details><?php endif; ?>
</td></tr><?php endforeach; ?></table></div>
  <div class="card table-card" style="margin:16px 0">
    <h2>Resmî Trendyol ödeme kayıtları (cari ekstre)</h2>
    <p class="hint">Mağaza Bağlama ekranındaki cari hesap senkronundan alınır. Bu kayıtlar tek başına net kâr veya kesin banka mutabakatı değildir.</p>
    <table><tr><th>Ödeme emri</th><th>İşlem tarihi</th><th>Ödeme kaydı</th></tr>
    <?php foreach ($officialPayments as $payment): $raw=json_decode($payment['raw_json'],true) ?: []; ?>
    <tr><td><?= h((string)($payment['payment_order_id'] ?? '—')) ?></td>
    <td><?= date('d.m.Y',(int)$payment['transaction_date']) ?></td>
    <td><?= isset($raw['debt']) ? tl((float)$raw['debt']) : '—' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$officialPayments): ?><tr><td colspan="3">Henüz ödeme kaydı yok.</td></tr><?php endif; ?>
    </table>
  </div>
  <div class="card table-card" style="margin:16px 0">
    <h2>Resmî kargo faturası kalemleri</h2><p><a class="btn-primary" href="kargo-kontrol.php">Fatura / paket bazlı kargo kontrolü</a></p>
    <p class="hint">Sipariş numarası ve kargo türüne göre gruplanmış son 100 kayıt grubu. Gönderi ve iade kargoları ayrı tutulur.</p>
    <table><tr><th>Sipariş no</th><th>Kargo türü</th><th>Kalem</th><th>Toplam desi</th><th>Fatura tutarı</th></tr>
    <?php foreach ($officialCargo as $row): ?>
    <tr><td><?= h((string)$row['order_number']) ?></td><td><?= h((string)$row['shipment_type']) ?></td>
    <td><?= (int)$row['kalem'] ?></td><td><?= (float)$row['desi'] ?></td><td><?= tl((float)$row['tutar']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$officialCargo): ?><tr><td colspan="5">Henüz kargo faturası kalemi yok.</td></tr><?php endif; ?>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
