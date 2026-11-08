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
        $pdo=db();$pdo->exec('BEGIN IMMEDIATE');
        try {
        $ids = array_keys($_POST['en'] ?? []);
        $upd = db()->prepare("UPDATE products SET en=?, boy=?, yukseklik=?, kesilen_desi=? WHERE id=? AND user_id=? AND marketplace=?");
        $n = 0;
        foreach ($ids as $id) {
            $values=[];
            foreach(['en','boy','yukseklik','kesilen'] as $field){$raw=$_POST[$field][$id]??'';if(!is_string($raw)||($raw!==''&&!is_numeric($raw)))throw new InvalidArgumentException('Geçersiz ölçü.');$values[]=$raw===''?0.0:(float)$raw;}
            [$en,$boy,$yuk,$kes]=$values;
            profit_validate([$en,$boy,$yuk,$kes]);
            $upd->execute([$en, $boy, $yuk, $kes, (int)$id, $u['id'],$mp]);
            $n+=$upd->rowCount();
        }
        $pdo->exec('COMMIT');
        flash_set('success', $n . ' ürünün desi bilgisi kaydedildi.');
        }catch(Throwable $e){$pdo->exec('ROLLBACK');flash_set('error','Desi ölçüleri sıfır veya pozitif sayılar olmalı; hiçbir satır kaydedilmedi.');}
    }
    e_redirect('hakedis-desi.php?tab=desi&mp=' . $mp);
}

// Payment-order amounts and their financial records are queried separately from order revenue.
$hakedisResult=null;$paymentOrders=[];$payoutError='';$paymentPage=max(0,(int)($_POST['payment_page']??0));$paymentPages=0;
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='check_hakedis') {
    if (!csrf_verify($_POST['csrf']??null)) $payoutError='Oturum süresi doldu.';
    elseif ($mp!=='trendyol'||!empty($u['is_demo'])) $payoutError='Bu kontrol bağlı gerçek bir Trendyol mağazası gerektirir.';
    else try {
        $int=get_integration((int)$u['id'],'trendyol');if(!$int)throw new RuntimeException('Önce Trendyol mağazanızı bağlayın.');
        $client=integration_client($int);$ordersResult=$client->getPaymentOrders(20,$paymentPage);
        if(!$ordersResult['ok'])throw new RuntimeException($ordersResult['error']);
        $paymentPages=$ordersResult['pages'];$paymentOrders=$ordersResult['rows'];$selected=(string)($_POST['payment_order_id']??'');
        if($selected!==''){
            $order=null;foreach($paymentOrders as $item)if((string)$item['id']===$selected)$order=$item;
            if(!$order)throw new RuntimeException('Seçilen ödeme emri mağazanızda bulunamadı.');
            $bank=$_POST['bank_amount']??'';
            if(!is_string($bank)||!is_numeric($bank)||!is_finite((float)$bank)||(float)$bank<0)throw new RuntimeException('Geçerli bir banka tutarı girin.');
            $ledger=$client->getPaymentOrderLedger($selected,10);
            if(!$ledger['ok'])throw new RuntimeException($ledger['error']);
            $hakedisResult=['order'=>$order,'bank'=>(float)$bank,'difference'=>round((float)$bank-(float)$order['amount'],2),'rows'=>$ledger['rows']];
        }
    }catch(Throwable $e){$payoutError=$e instanceof RuntimeException?$e->getMessage():'Ödeme emri kontrolü tamamlanamadı.';}
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
    <h2>Desi Kontrolü</h2>
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
        <tr><th>Ürün</th><th>En (cm)</th><th>Boy (cm)</th><th>Yük (cm)</th><th>Olması Gereken Desi</th><th>Kesilen Desi</th><th>Durum</th></tr>
        <?php foreach ($products as $p): $beklenen = desi_from_dims((float)$p['en'], (float)$p['boy'], (float)$p['yukseklik']); $kesilen = (float)$p['kesilen_desi']; ?>
        <tr>
          <td><?= h($p['name']) ?></td>
          <td><input type="number" step="0.1" name="en[<?= $p['id'] ?>]" value="<?= (float)$p['en'] > 0 ? number_format((float)$p['en'], 1, '.', '') : '' ?>" style="width:70px"></td>
          <td><input type="number" step="0.1" name="boy[<?= $p['id'] ?>]" value="<?= (float)$p['boy'] > 0 ? number_format((float)$p['boy'], 1, '.', '') : '' ?>" style="width:70px"></td>
          <td><input type="number" step="0.1" name="yukseklik[<?= $p['id'] ?>]" value="<?= (float)$p['yukseklik'] > 0 ? number_format((float)$p['yukseklik'], 1, '.', '') : '' ?>" style="width:70px"></td>
          <td><?= $beklenen > 0 ? number_format($beklenen, 2, ',', '.') : '<span class="hint">—</span>' ?></td>
          <td><input type="number" step="0.01" name="kesilen[<?= $p['id'] ?>]" value="<?= $kesilen > 0 ? number_format($kesilen, 2, '.', '') : '' ?>" style="width:80px"></td>
          <td>
            <?php if ($beklenen > 0 && $kesilen > 0): ?>
              <?php if ($kesilen > $beklenen + 0.01): ?><span class="neg">▲ Fazla <?= number_format($kesilen - $beklenen, 2, ',', '.') ?> desi</span>
              <?php else: ?><span class="pos">● Normal</span><?php endif; ?>
            <?php else: ?><span class="hint">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="7" class="hint">Ürün yok. Önce ürünlerini senkronize et.</td></tr><?php endif; ?>
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
  <div class="card" style="margin:16px 0">
    <h2>Ödeme Emri ve Banka Tutarı Kontrolü</h2>
    <p>Önce Trendyol’un ödenmiş ödeme emirlerini getirin. Ardından bir ödeme emrini seçip aynı para birimindeki banka tutarını girin. Sipariş cirosu, hakediş yerine kullanılmaz.</p>
    <?php if($payoutError): ?><div class="flash flash-error"><?= h($payoutError) ?></div><?php endif; ?>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="check_hakedis"><input type="hidden" name="mp" value="<?= h($mp) ?>"><input type="hidden" name="tab" value="hakedis"><input type="hidden" name="payment_page" value="<?= $paymentPage ?>">
      <?php if($paymentOrders): ?><div><label for="po">Ödenmiş ödeme emri</label><select id="po" name="payment_order_id"><?php foreach($paymentOrders as $item): ?><option value="<?= h((string)$item['id']) ?>" <?= (string)($_POST['payment_order_id']??'')===(string)$item['id']?'selected':'' ?>><?= h((string)$item['id'].' · '.tl((float)$item['amount'])) ?></option><?php endforeach; ?></select></div><div><label for="bank">Bankaya gelen tutar</label><input id="bank" type="number" min="0" step="0.01" name="bank_amount" value="<?= h((string)($_POST['bank_amount']??'')) ?>" required></div><?php endif; ?>
      <button type="submit" class="btn-primary"><?= $paymentOrders?'Ödeme Emrini Karşılaştır':'Ödeme Emirlerini Getir' ?></button>
    </form>
    <?php if($_SERVER['REQUEST_METHOD']==='POST'&&!$paymentOrders&&!$payoutError): ?><p>Sağlayıcıdan ödenmiş ödeme emri gelmedi; mutabakat yapılamadı.</p><?php endif; ?>
    <?php if($paymentPages>1): ?><form method="post" style="margin-top:12px;display:flex;gap:8px;align-items:center"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="check_hakedis"><input type="hidden" name="tab" value="hakedis"><input type="hidden" name="mp" value="<?= h($mp) ?>"><?php if($paymentPage>0): ?><button class="btn-ghost" name="payment_page" value="<?= $paymentPage-1 ?>">Önceki ödeme emirleri</button><?php endif; ?><span><?= $paymentPage+1 ?> / <?= $paymentPages ?></span><?php if($paymentPage+1<$paymentPages): ?><button class="btn-ghost" name="payment_page" value="<?= $paymentPage+1 ?>">Sonraki ödeme emirleri</button><?php endif; ?></form><?php endif; ?>
    <?php if($hakedisResult): ?><div class="flash flash-<?= abs($hakedisResult['difference'])<=0.01?'success':'info' ?>">Ödeme emri: <?= tl((float)$hakedisResult['order']['amount']) ?> · Banka: <?= tl($hakedisResult['bank']) ?> · Fark: <?= tl($hakedisResult['difference']) ?>. <?= abs($hakedisResult['difference'])<=0.01?'Girilen banka tutarı bu ödeme emriyle uyumlu.':'Tutarlar farklı; banka ve ödeme emri ayrıntılarını inceleyin.' ?></div>
    <p class="hint">Bu sonuç banka tutarını ödeme emriyle karşılaştırır. Aşağıda ekstre kayıtlarının ilk 200 satırı gösterilir; bütün kesintilerin doğru olduğu veya kâra eşit olduğu anlamına gelmez.</p>
    <div style="overflow-x:auto"><table><tr><th>Kaynak</th><th>İşlem</th><th>Borç</th><th>Alacak</th></tr><?php foreach(array_slice($hakedisResult['rows'],0,200) as $row): ?><tr><td><?= h($row['source']) ?></td><td><?= h((string)($row['transactionType']??'')) ?></td><td><?= tl((float)$row['debt']) ?></td><td><?= tl((float)$row['credit']) ?></td></tr><?php endforeach; ?></table></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__.'/../includes/footer.php'; ?>
