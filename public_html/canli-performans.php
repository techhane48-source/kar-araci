<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = require_login();
$pro = is_pro($u);
if (!$pro) {
    flash_set('error', 'Canlı Performans Pro plan özelliğidir.');
    e_redirect('odeme.php');
}

// Kullanıcının bağladığı pazaryerleri
$ints = db()->prepare("SELECT marketplace FROM integrations WHERE user_id = ?");
$ints->execute([$u['id']]);
$mps = array_column($ints->fetchAll(), 'marketplace');
if (!$mps) $mps = ['trendyol'];

$mp = $_POST['mp'] ?? $_GET['mp'] ?? $mps[0];
if (!in_array($mp, $mps, true)) $mp = $mps[0];
$days=(int)($_POST['sync_days']??$_POST['days']??$_GET['days']??7);
if(!in_array($days,[7,30,89],true))$days=7;
$page=max(1,(int)($_GET['page']??1));
$search=trim(is_string($_GET['q']??null)?$_GET['q']:'');
$statusFilter=is_string($_GET['status']??null)?$_GET['status']:'';
if(!in_array($statusFilter,['','new','shipping','delivered','closed'],true))$statusFilter='';
$rangeUrl=static fn(int $p=1):string=>'canli-performans.php?mp='.urlencode($mp).'&days='.$days.'&q='.urlencode($search).'&status='.urlencode($statusFilter).'&page='.$p;

// --- Maliyet kaydet (inline; Bugün Sipariş Alan Ürünler) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_cost') {
    if (!csrf_verify(is_string($_POST['csrf']??null)?$_POST['csrf']:null)) {
        flash_set('error', 'Oturum süresi doldu.');
    } else try {
        foreach(['pid','cost','vat','scope'] as $key)if(!is_string($_POST[$key]??null))throw new InvalidArgumentException('Maliyet, alış KDV ve geçerlilik kapsamı gerekli.');
        if($mp!=='trendyol'||!ctype_digit($_POST['pid'])||!in_array($_POST['vat'],['1','10','20'],true)||!in_array($_POST['scope'],['today','first_sale'],true))throw new InvalidArgumentException('Ürün, alış KDV veya tarih seçimi geçersiz.');
        $uid=(int)$u['id'];$pid=(int)$_POST['pid'];$scope=$_POST['scope'];
        $res=with_marketplace_lock($uid,'trendyol',static function()use($uid,$pid,$scope){
            $q=db()->prepare("SELECT barcode FROM products WHERE user_id=? AND id=? AND marketplace='trendyol' AND barcode<>''");$q->execute([$uid,$pid]);$bc=$q->fetchColumn();
            if($bc===false)throw new InvalidArgumentException('Ürün bulunamadı.');
            $values=$scope==='first_sale'?cost_history_first_sale_values([(string)$bc],$_POST['cost'],$_POST['vat']):cost_history_group_values([(string)$bc],date('Y-m-d'),$_POST['cost'],$_POST['vat']);
            return ['ok'=>true]+cost_history_save(db(),$uid,$values,false,$scope==='first_sale'?'first_sale':'manual',true);
        });
        if(empty($res['ok']))throw new InvalidArgumentException($res['error']??'Maliyet kaydedilemedi.');
        flash_set('success','Maliyet ve alış KDV oranı kaydedildi.');
    }catch(InvalidArgumentException|RuntimeException $e){flash_set('error',$e->getMessage());}
    catch(Throwable $e){flash_set('error','Maliyet kaydedilemedi; önceki kayıtlar korundu.');}
    e_redirect($rangeUrl());
}

// --- Senkronize et ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_set('error', 'Oturum süresi doldu.');
    } else {
        $r = sync_orders($u['id'], $mp, $days);
        if ($r['ok']) {
            flash_set('success', ($r['count'] ?? 0) . ' paket sorgudan işlendi. Son '.$days.' günün kayıtlı siparişleri gösteriliyor.');
        } else {
            flash_set('error', $r['error'] ?? 'Senkron başarısız.');
        }
    }
    e_redirect($rangeUrl());
}

// Network jobs run via explicit POST or CLI, never on GET. Show actual freshness.
$autoSyncSt=db()->prepare("SELECT last_orders_sync FROM integrations WHERE user_id=? AND marketplace=?");
$autoSyncSt->execute([$u['id'],$mp]);$lastOrdersSync=(int)$autoSyncSt->fetchColumn();

// Ürünler (barkod -> ürün)
$ps = db()->prepare("SELECT * FROM products WHERE user_id = ? AND marketplace = ?");
$ps->execute([$u['id'], $mp]);
$byBarcode = [];
foreach ($ps->fetchAll() as $p) {
    if ($p['barcode'] !== '') $byBarcode[$p['barcode']] = $p;
}

// Calendar-day display window; provider sync uses modified time separately.
$weekStart = strtotime('-'.($days-1).' days',strtotime('today'));
$rangeEnd=strtotime('tomorrow');
$q = db()->prepare("SELECT * FROM orders WHERE user_id = ? AND marketplace = ? AND order_date >= ? AND order_date < ? ORDER BY order_date DESC,id DESC");
$q->execute([$u['id'], $mp, $weekStart,$rangeEnd]);
$orders = commission_profit_overlay(db(),(int)$u['id'],cargo_profit_overlay(db(),(int)$u['id'],cost_history_overlay(db(),(int)$u['id'],$q->fetchAll())));

$orders=closed_package_loss_overlay(db(),(int)$u['id'],$orders);
$todayStart = strtotime('today');
$yesterdayStart = $todayStart - 86400;
$now = time();
$visibleOrders=array_values(array_filter($orders,static function($o)use($search,$statusFilter){
    $status=strtolower((string)($o['status']??''));
    $group=isset($o['closed_loss'])||in_array($status,['returned','cancelled','canceled','unsupplied','undelivered'],true)?'closed':(in_array($status,['shipped','atcollectionpoint'],true)?'shipping':($status==='delivered'?'delivered':(in_array($status,['created','awaiting','unpacked','picking','invoiced','packed','readytoship'],true)?'new':'unknown')));
    if($statusFilter!==''&&$group!==$statusFilter)return false;
    return $search===''||str_contains((string)($o['order_number']??''),$search)||str_contains((string)($o['package_number']??''),$search);
}));
$perPage=50;$orderTotal=count($visibleOrders);$pages=max(1,(int)ceil($orderTotal/$perPage));$page=min($page,$pages);
$blank=['sale'=>0.0,'profit'=>0.0,'count'=>0,'profitCount'=>0,'profitSale'=>0.0];$period=$blank;$closed=['count'=>0,'known'=>0,'loss'=>0.0];

$today = ['sale' => 0.0, 'profit' => 0.0, 'count' => 0, 'profitCount' => 0, 'profitSale'=>0.0];
$yesterday = ['sale' => 0.0, 'profit' => 0.0, 'count' => 0];
$hourly = array_fill(0,24,$blank);
$week = []; // dayKey (Y-m-d) => [sale, profit, count]

foreach ($orders as $o) {
    if (!order_is_active_sale($o)&&!isset($o['closed_loss'])) continue;
    $isClosed=isset($o['closed_loss']);
    $o['lines'] = is_array($o['lines'])?$o['lines']:(json_decode($o['lines']??'[]',true)?:[]);
    $day = strtotime(date('Y-m-d', (int)$o['order_date']));
    $dayKey = date('Y-m-d', (int)$o['order_date']);
    if (!isset($week[$dayKey])) $week[$dayKey] = $blank;

    if($isClosed){
        $cl=$o['closed_loss'];$closed['count']++;$known=$cl['loss']!==null;
        if($known){$closed['known']++;$closed['loss']+=(float)$cl['loss'];}
        $pr=['ok'=>$known,'profit'=>$known?-(float)$cl['loss']:null];$sale=0.0;
    }else{$pr=calc_order_profit($o,$byBarcode);$sale=(float)$o['total_price'];}
    $profit=$pr['ok']?(float)$pr['profit']:0.0;

    $week[$dayKey]['sale'] += $sale;
    $week[$dayKey]['count']++;
    $period['sale']+=$sale;$period['count']++;
    if($pr['ok']){
        $week[$dayKey]['profit']+=$profit;$week[$dayKey]['profitCount']++;$week[$dayKey]['profitSale']+=$sale;
        $period['profit']+=$profit;$period['profitCount']++;$period['profitSale']+=$sale;
    }

    if ($day >= $todayStart) {
        $today['sale'] += $sale;
        $today['count']++;
        $hour = (int) date('G', (int)$o['order_date']);
        $hourly[$hour]['sale'] += $sale;
        $hourly[$hour]['count']++;
        if ($pr['ok']) {
            $today['profit'] += $profit;
            $today['profitCount']++;
            $today['profitSale'] += $sale;
            $hourly[$hour]['profit'] += $profit;
            $hourly[$hour]['profitCount']++;
        }
    } elseif ($day >= $yesterdayStart) {
        $yesterday['sale'] += $sale;
        $yesterday['count']++;
        if ($pr['ok']) $yesterday['profit'] += $profit;
    }
}

$deltaSale = $yesterday['sale'] > 0 ? ($today['sale'] - $yesterday['sale']) / $yesterday['sale'] * 100 : null;
$avgMargin = $today['profitSale'] > 0 ? $today['profit'] / $today['profitSale'] * 100 : null;
$excludedCurrency = count(array_filter($orders, static fn($o)=>!order_is_domestic_try($o)));

$pageTitle = 'Siparişler — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar">
    <div><h1>Siparişler</h1><p class="ui-muted">Satışlarınızı, kargo durumlarını ve sipariş kârınızı takip edin.</p></div>
    <form method="post" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="sync">
      <input type="hidden" name="mp" value="<?=h($mp)?>">
      <input type="hidden" name="sync_days" value="<?=$days?>">
      <button type="submit" class="btn-primary">Siparişleri güncelle</button>
    </form>
  </div>

  <form method="get" class="ui-filterbar">
    <label>Mağaza <select name="mp"><?php foreach($mps as $c):?><option value="<?=h($c)?>" <?=$mp===$c?'selected':''?>><?=h(marketplace_label($c))?></option><?php endforeach;?></select></label>
    <label>Görüntüleme aralığı <select name="days"><?php foreach([7,30,89] as $n):?><option value="<?=$n?>" <?=$days===$n?'selected':''?>>Son <?=$n?> gün</option><?php endforeach;?></select></label>
    <label>Sipariş veya paket no <input name="q" value="<?=h($search)?>" placeholder="Sipariş numarası"></label>
    <label>Durum <select name="status"><?php foreach([''=>'Tümü','new'=>'Yeni / hazırlanıyor','shipping'=>'Kargoda','delivered'=>'Teslim edildi','closed'=>'İptal / iade'] as $key=>$label):?><option value="<?=h($key)?>" <?=$statusFilter===$key?'selected':''?>><?=h($label)?></option><?php endforeach;?></select></label>
    <button class="btn-primary">Filtrele</button><a class="btn-ghost" href="?mp=<?=urlencode($mp)?>&days=<?=$days?>">Temizle</a>
  </form>
  <?php $flash = flash_get(); if ($flash): ?><div class="alert alert-<?= $flash['type'] ?>"><?= h($flash['msg']) ?></div><?php endif; ?>

  <p class="hint">Son sipariş güncellemesi: <?= $lastOrdersSync > 0 ? date("d.m.Y H:i", $lastOrdersSync) : "Henüz yok" ?><?= $lastOrdersSync > 0 && $lastOrdersSync < time()-900 ? " — veriler eski olabilir" : "" ?>.</p>
  <?php if($mp==='trendyol'): ?><p class="hint"><?= h(sync_coverage_label(db(),(int)$u['id'],$mp,'orders_modified',$weekStart,time())) ?> Sipariş aralığı son güncelleme tarihine göredir; oluşturulma tarihi kapsamı garanti edilmez.</p><?php endif; ?>
  <section class="ui-kpi-grid" aria-label="Bugünkü satışlar">
    <?php ui_kpi('wallet','Bugün satış',tl($today['sale']),'Bugün · aktif satışlar');?>
    <?php ui_kpi('finance','Bugün tahmini kâr',$today['profitCount']?tl($today['profit']):'—',$today['profitCount'].'/'.$today['count'].' paket hesaplandı',$today['profit']<0?'neg':'');?>
    <?php ui_kpi('orders','Bugünkü paketler',(string)$today['count'],'İptal ve iadeler dahil');?>
    <?php ui_kpi('price','Tahmini kâr marjı',$avgMargin===null?'—':'%'.number_format($avgMargin,1,',','.'),'Hesaplanabilen satış üzerinden');?>
  </section>
  <p class="hint">Kâr kapsamı: <?= $today['profitCount'] ?>/<?= $today['count'] ?> sipariş; <?= tl($today['profitSale']) ?> ciro. <?= $excludedCurrency ?> paket ülke/para birimi doğrulaması dışında. Eski kayıtlar için yeniden senkron gerekir.</p>
  <?php if ($deltaSale !== null): ?>
    <p class="hint" style="margin:8px 0">Dünün tamamına göre bugünkü satış <b class="<?= $deltaSale >= 0 ? 'pos' : 'neg' ?>">%<?= number_format($deltaSale, 1, ',', '.') ?></b> (dün <?= tl($yesterday['sale']) ?>).</p>
  <?php endif; ?>

  <div class="card table-card" style="margin:16px 0">
    <h2>Son <?=$days?> Günün Siparişleri</h2><p class="hint"><?=$orderTotal?> kayıtlı paket · Sayfa <?=$page?> / <?=$pages?> · Sayfa başına <?=$perPage?> paket</p>
    <table>
      <tr><th>Sipariş No</th><th>Tarih</th><th>Satış tutarı</th><th>Tahmini Kâr</th><th>Durum</th></tr>
      <?php foreach (array_slice($visibleOrders,($page-1)*$perPage,$perPage) as $o): $o['lines'] = is_array($o['lines'])?$o['lines']:(json_decode($o['lines']??'[]',true)?:[]); $pr = calc_order_profit($o, $byBarcode); ?>
        <tr>
          <td style="font-size:12px"><?= h($o['order_number'] ?: $o['package_number'] ?: '—') ?></td>
          <td style="font-size:12px"><?= date('d.m H:i', (int)$o['order_date']) ?></td>
          <td><?= order_is_domestic_try($o) ? tl((float)$o['total_price']) : h((string)($o['currency_code'] ?? 'Belirsiz')) . ' ' . number_format((float)$o['total_price'],2,',','.') ?></td>
          <td>
            <?php if(isset($o['closed_loss'])):$cl=$o['closed_loss'];$cancel=in_array(strtolower((string)$o['status']),['cancelled','canceled','unsupplied'],true);?>
              <?php if($cancel&&$cl['loss']===0.0):?><span class="hint" title="0 TL tahmin; henüz gönderim/kesinti kaydı yok.">Paket iptal edildi</span>
              <?php elseif($cancel&&$cl['loss']===null):?><span class="hint" title="<?=h($cl['state'])?>">Paket iptal edildi · Gider kontrolü bekleniyor</span>
              <?php else:?>
              <?php if($cl['loss']!==null):?><span class="neg">Tahmini zarar: <?=tl(-$cl['loss'])?></span><br><small>Gidiş: <?=$cl['outbound']!==null?tl($cl['outbound']):'bekleniyor'?> · Dönüş: <?=$cl['return_cargo']!==null?tl($cl['return_cargo']):'bekleniyor'?> · Stok kaybı: <?=$cl['inventory']!==null?tl($cl['inventory']):'belirsiz'?> · Ekstre kaybı: <?=$cl['settlement_loss']!==null?tl($cl['settlement_loss']):'bekleniyor'?></small><br><?php endif;?>
              <small><?=h($cl['state'])?><?=$cl['assumed']?' · Operasyonel varsayım':''?></small><?php if(!$cancel):?><br><a href="iade-kontrol.php?q=<?=urlencode((string)$o['order_number'])?>">İade / satılamaz işareti</a><?php endif;?>
              <?php endif;?>
            <?php elseif ($pr['ok']): ?><span class="<?= $pr['profit'] < 0 ? 'neg' : 'pos' ?>"><?= tl($pr['profit']) ?></span><br><small><?=h((string)$pr['cargo_source'])?> · <?=h((string)$pr['commission_source'])?></small>
              <?php if(isset($o['partial_units'])):?><br><small><?= (int)$o['partial_units']?> adet <?=h($o['partial_kind'])?> · kalan satış <?=tl((float)$o['total_price'])?><?php if($o['partial_kind']==='iade'):?> · <a href="iade-kontrol.php?q=<?=urlencode((string)$o['order_number'])?>">Satılamaz işareti</a><?php endif;?></small><?php endif;?>
            <?php else: ?><span class="hint"><?= h($pr['reason'] ?? 'Veri eksik') ?></span><?php endif; ?>
          </td>
          <td><?php [$label,$tone]=ui_status(isset($o['closed_loss'])&&!in_array(strtolower((string)$o['status']),['cancelled','canceled','unsupplied','undelivered'],true)?'Returned':(string)$o['status']);?><span class="ui-status <?=h($tone)?>"><?=h($label)?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$visibleOrders): ?><tr><td colspan="5" class="hint">Bu filtreye uygun sipariş yok. Filtreleri temizleyin veya siparişleri güncelleyin.</td></tr><?php endif; ?>
    </table>
    <nav aria-label="Sipariş sayfaları" style="display:flex;gap:12px;margin-top:12px">
      <?php if($page>1):?><a class="btn-ghost" href="<?=h($rangeUrl($page-1))?>">Önceki sayfa</a><?php endif;?>
      <?php if($page<$pages):?><a class="btn-ghost" href="<?=h($rangeUrl($page+1))?>">Sonraki sayfa</a><?php endif;?>
    </nav>
  </div>
  <details class="ui-disclosure"><summary>Satış ve kâr ayrıntıları · maliyet düzenleme</summary><div class="ui-detail-body">
  <div class="card table-card" style="margin:16px 0">
    <h2>Bugün Saatlik</h2>
    <?php
    // Saatlik grafik (SVG bar chart) — Melontik paritesi
    $maxSaleH = max(1.0, max(array_column($hourly, 'sale')));
    $maxProfitH = max(1.0, max(array_column($hourly, 'profit')));
    ?>
    <div style="display:flex;gap:8px;align-items:flex-end;height:120px;margin:10px 0 6px;padding:0 4px">
      <?php for ($h = 0; $h < 24; $h++): $d = $hourly[$h]; $sh = $d['sale'] / $maxSaleH * 100; $ph = $d['profit'] / $maxSaleH * 100; ?>
        <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:2px" title="<?= str_pad((string)$h,2,'0',STR_PAD_LEFT) ?>:00 — Satış <?= tl($d['sale']) ?> / Kâr <?= tl($d['profit']) ?>">
          <div style="width:100%;background:var(--accent-soft);border-radius:3px 3px 0 0;height:<?= max(2, $sh) ?>%;position:relative">
            <?php if ($d['profit'] > 0): ?><div style="position:absolute;bottom:0;left:0;right:0;background:var(--green);height:<?= max(1, $ph) ?>%;border-radius:3px 3px 0 0"></div><?php endif; ?>
          </div>
          <?php if ($h % 3 === 0): ?><div style="font-size:9px;color:var(--muted-2)"><?= str_pad((string)$h,2,'0',STR_PAD_LEFT) ?></div><?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>
    <p class="hint" style="margin-bottom:10px">Mavi: satış, yeşil: kâr (satış ölçeğinde). Saat etiketleri her 3 saatte bir.</p>
    <table>
      <tr><th>Saat</th><th>Satış</th><th>Tahmini Kâr</th><th>Sipariş</th></tr>
      <?php foreach ($hourly as $h => $d): if ($d['count'] === 0) continue; ?>
        <tr>
          <td><?= str_pad((string)$h, 2, '0', STR_PAD_LEFT) ?>:00</td>
          <td><?= tl($d['sale']) ?></td>
          <td class="<?= $d['profit'] < 0 ? 'neg' : ($d['profitCount']?'pos':'hint') ?>"><?= $d['profitCount']?tl($d['profit']):'—' ?></td>
          <td><?= $d['count'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!array_filter(array_column($hourly, 'count'))): ?><tr><td colspan="4" class="hint">Bugün henüz sipariş yok. "Siparişleri güncelle" ile güncelleyin.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="card table-card" style="margin:16px 0" id="period-summary">
    <h2>Son <?=$days?> Gün</h2>
    <p>Satış: <b><?=tl($period['sale'])?></b> · Hesaplanabilen tahmini kâr: <b id="period-profit"><?=$period['profitCount']?tl($period['profit']):'—'?></b> · Kâr kapsamı: <?=$period['profitCount']?>/<?=$period['count']?> paket (iptal/iade dahil).</p>
    <p class="hint">Eksik verili paketler kâr toplamına alınmaz. Hesaplanamayan kâr sıfır değildir.</p>
    <p id="closed-loss-summary">İptal/iade: <?=$closed['count']?> paket · Tahmini zarar: <b><?=$closed['known']?tl($closed['loss']):'—'?></b> (<?=$closed['known']?>/<?=$closed['count']?> paket). Hesaplanabilen zarar dönem kârından bir kez düşülür. Satış ve komisyon sıfır varsayılır; eksik dönüş kargosu mevcut taşıyıcı ve desi verisiyle tahmin edilir, fatura geldiğinde değişir. <a href="iade-kontrol.php">Satılamaz iadeleri işaretle</a>.</p>
    <table>
      <tr><th>Gün</th><th>Satış</th><th>Tahmini Kâr</th><th>Kâr kapsamı</th><th>Paket</th></tr>
      <?php foreach ($week as $day => $d): ?>
        <tr data-day="<?=h($day)?>">
          <td><?= h($day) ?></td>
          <td><?= tl($d['sale']) ?></td>
          <td data-profit-count="<?=$d['profitCount']?>" class="<?= $d['profit'] < 0 ? 'neg' : ($d['profitCount']?'pos':'hint') ?>"><?= $d['profitCount']?tl($d['profit']):'—' ?></td>
          <td><?=$d['profitCount']?>/<?=$d['count']?></td>
          <td><?= $d['count'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$week): ?><tr><td colspan="5" class="hint">Aktif sipariş verisi yok. Önce senkronize edin.</td></tr><?php endif; ?>
    </table>
  </div>

  <div class="card table-card" style="margin:16px 0">
    <h2>Bugün Sipariş Alan Ürünler — Maliyet Düzenle</h2>
    <p class="hint" style="margin-bottom:10px">Bugünkü siparişlerde yer alan ürünlerin alış maliyetini buradan hızlıca gir/güncelle.</p>
    <?php
    $todayProducts = [];
    foreach ($orders as $o) {
        if ((int)$o['order_date'] < $todayStart) continue;
        $lines = is_array($o['lines']) ? $o['lines'] : (json_decode($o['lines'] ?? '[]', true) ?: []);
        foreach ($lines as $l) {
            $bc = $l['barcode'] ?? '';
            if ($bc === '' || isset($todayProducts[$bc])) continue;
            $p = $byBarcode[$bc] ?? null;
            if ($p) $todayProducts[$bc] = $p;
        }
    }
    ?>
    <?php if (!$todayProducts): ?>
      <p class="hint">Bugün henüz sipariş yok.</p>
    <?php else: ?>
    <table>
      <tr><th>Ürün</th><th>Barkod</th><th>Mevcut Maliyet</th><th>Yeni alış maliyeti ve KDV</th></tr>
      <?php foreach ($todayProducts as $bc => $p): ?>
      <tr>
        <td><?= h($p['name']) ?></td>
        <td style="font-size:12px"><?= h($bc) ?></td>
        <td><?= (float)$p['cost'] > 0 ? tl((float)$p['cost']) : '<span class="hint">boş</span>' ?></td>
        <td>
          <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="save_cost"><input type="hidden" name="days" value="<?=$days?>"><input type="hidden" name="mp" value="<?=h($mp)?>">
            <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
            <label>KDV dahil TL <input inputmode="decimal" name="cost" value="<?= (float)$p['cost'] > 0 ? h((string)$p['cost']) : '' ?>" style="width:110px" required></label>
            <label>Alış KDV <select name="vat" required><option value="">Seçin</option><?php foreach([1,10,20] as $v):?><option value="<?=$v?>" <?=(float)$p['cost']>0&&(float)($p['cost_vat']??-1)===(float)$v?'selected':''?>>%<?=$v?></option><?php endforeach;?></select></label>
            <label>Geçerlilik <select name="scope" required><option value="">Seçin</option><option value="today">Bugünden itibaren</option><option value="first_sale">İlk satıştan itibaren</option></select></label>
            <button type="submit" class="btn-primary" style="padding:6px 12px">Kaydet</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>


  </div></details>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
