<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = require_login();
$pro = is_pro($u);

$mps = array_column(db()->query("SELECT marketplace FROM integrations WHERE user_id = {$u['id']}")->fetchAll(), 'marketplace');
if (!$mps) $mps = ['trendyol'];
$mp = $_GET['mp'] ?? $mps[0];
if (!in_array($mp, $mps, true)) $mp = $mps[0];

$ps = db()->prepare("SELECT * FROM products WHERE user_id = ? AND marketplace = ?");
$ps->execute([$u['id'], $mp]);
$products = $ps->fetchAll();

$fromTs = time() - 30 * 86400;
$os = db()->prepare("SELECT * FROM orders WHERE user_id = ? AND marketplace = ? AND order_date >= ?");
$os->execute([$u['id'], $mp, $fromTs]);
$orders = $os->fetchAll();
foreach ($orders as &$o) $o['lines'] = json_decode($o['lines'] ?? '[]', true) ?: [];
unset($o);

$byBarcode = [];
foreach ($products as $p) if ($p['barcode'] !== '') $byBarcode[$p['barcode']] = $p;

$rules=target_rules(db(),(int)$u['id']);$now=time();
$labels=alert_catalog_labels();$alerts=array_fill_keys(array_keys($labels),[]);
$summary=['products'=>0,'evaluated'=>0,'flagged'=>0,'counts'=>array_fill_keys(array_keys($labels),0)];
foreach($products as $p){
    $summary['products']++;$a=alert_catalog_row($p,target_for_product($rules,$p),$now);
    if($a['profit']!==null)$summary['evaluated']++;
    if($a['issues'])$summary['flagged']++;
    foreach($a['issues'] as $key){
        $summary['counts'][$key]++;
        if(count($alerts[$key])<50)$alerts[$key][]=array_merge($p,['_profit'=>$a['profit'],'_rule'=>$a['rule']]);
    }
}
$orders=cost_history_overlay(db(),(int)$u['id'],$orders);
$alerts['siparis']=[];$alerts['cari']=[];$orderAlertCount=0;
foreach($orders as $o){
    $a=alert_order_row($o,$byBarcode);
    if($a){$orderAlertCount++;if(count($alerts['siparis'])<50)$alerts['siparis'][]=array_merge($o,['_sale'=>(float)$o['total_price'],'_reason'=>$a['reason'],'_profit'=>$a['profit']]);}
}
$q=db()->prepare("SELECT job,state,last_success FROM sync_status WHERE user_id=? AND marketplace=?");
$q->execute([$u['id'],$mp]);$sync=[];foreach($q->fetchAll() as $r)$sync[$r['job']]=$r;
foreach(['products'=>'Ürün','orders'=>'Sipariş','settlements'=>'Finans','documents'=>'Belge','claims'=>'İade'] as $key=>$name){
    $r=$sync[$key]??null;
    if(!$r||!$r['last_success']||$r['state']!=='success'||!catalog_fresh($r['last_success'],$now))
        $alerts['cari'][]=['msg'=>$name.': son başarılı sorgu yok, eski veya son işlem başarısız.'];
}
$totalAlerts=array_sum($summary['counts'])+$orderAlertCount+count($alerts['cari']);
$selected=is_string($_GET['type']??null)?$_GET['type']:'all';
if($selected!=='all'&&!isset($labels[$selected]))$selected='all';
if(($_GET['export']??'')==='csv'){
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="kar-uyarilari.csv"');echo "\xEF\xBB\xBF";
    $out=fopen('php://output','w');fputcsv($out,['Barkod','Ürün','Kategori','Kayıtlı fiyat','Maliyet','Tahmini kâr','Uyarılar'],';','"','');
    foreach($products as $p){
        $a=alert_catalog_row($p,target_for_product($rules,$p),$now);
        if(!$a['issues']||($selected!=='all'&&!in_array($selected,$a['issues'],true)))continue;
        fputcsv($out,[csv_safe_text($p['barcode']),csv_safe_text($p['name']),csv_safe_text($p['category']),$p['price'],$p['cost'],$a['profit'],implode('; ',array_map(static fn($k)=>$labels[$k],$a['issues']))],';','"','');
    }fclose($out);exit;
}
$pageTitle = 'Uyarılar — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar">
    <h1>Uyarılar</h1>
    <form method="get" style="display:flex;gap:8px;align-items:center">
      <select name="mp" style="width:auto" onchange="this.form.submit()">
        <?php foreach ($mps as $c): ?>
          <option value="<?= $c ?>" <?= $mp === $c ? 'selected' : '' ?>><?= h(marketplace_label($c)) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <p class="hint">Kayıtlı ürün ayarlarıyla tahmini kâr kontrolü; gerçekleşmiş zarar değildir. Trendyol stok ve satış fiyatı için 24 saatlik API gözlemi aranır.</p>
  <div class="kpi-row">
    <div class="kpi"><div class="k-label">İncelenen ürün</div><div class="k-value"><?=$summary['products']?></div></div>
    <div class="kpi"><div class="k-label">Kârı hesaplanan</div><div class="k-value"><?=$summary['evaluated']?></div></div>
    <div class="kpi"><div class="k-label">Uyarılı benzersiz ürün</div><div class="k-value"><?=$summary['flagged']?></div></div>
    <div class="kpi"><div class="k-label">Tahmini zarar</div><div class="k-value"><?=$summary['counts']['loss']?></div></div>
    <div class="kpi"><div class="k-label">Hedef altı (zarar hariç)</div><div class="k-value"><?=$summary['counts']['below_target']?></div></div>
  </div>
  <form method="get"><input type="hidden" name="mp" value="<?=h($mp)?>"><select name="type"><option value="all">Tüm ürün uyarıları</option>
  <?php foreach($labels as $key=>$label):?><option value="<?=h($key)?>" <?=$selected===$key?'selected':''?>><?=h($label)?> (<?=$summary['counts'][$key]?>)</option><?php endforeach;?></select>
  <button>Filtrele</button><button name="export" value="csv">CSV indir</button> <a href="kar-hedefleri.php">Kâr hedefleri</a></form>
  <?php if(!$totalAlerts):?><p class="hint">Uyarı yok. Bu, verilerin eksiksiz olduğunu garanti etmez.</p><?php endif;?>
  <?php
  $sections=[];
  foreach($labels as $key=>$label)$sections[$key]=['title'=>$label,'desc'=>'Kayıtlı ürün ayarları ve son gözleme göre.'];
  $sections['siparis']=['title'=>'Son 30 gün: sipariş kontrolü','desc'=>'Eksik hesap, kapsam dışı kayıt veya tahmini zarar; iptal ve iadeler ayrı raporlanır.'];
  $sections['cari']=['title'=>'Senkronizasyon kontrolü','desc'=>'Başarılı sorgu, veri kapsamının eksiksiz olduğunu garanti etmez.'];
  foreach ($sections as $key => $sec):
    if($selected!=='all'&&isset($labels[$key])&&$key!==$selected)continue;
    $list = $alerts[$key];$count=$summary['counts'][$key]??($key==='siparis'?$orderAlertCount:count($list));
    if (!$list) continue;
  ?>
  <div class="card table-card" style="margin:16px 0">
    <h2><?= h($sec['title']) ?> <span class="hint" style="font-weight:400">(<?= $count ?>)</span></h2>
    <p class="hint" style="margin-bottom:10px"><?= h($sec['desc']) ?></p>
    <?php if ($key === 'cari'): ?>
      <?php foreach($list as $item):?><p><?= h($item['msg']) ?></p><?php endforeach;?> <a href="magaza.php">Mağaza bağlantısı →</a>
    <?php elseif ($key === 'siparis'): ?>
      <table>
        <tr><th>Sipariş No</th><th>Tarih</th><th>Uyarı</th><th>Tahmini kâr</th></tr>
        <?php foreach (array_slice($list, 0, 50) as $o): ?>
        <tr>
          <td style="font-size:12px"><?= h($o['order_number'] ?: $o['package_number'] ?: '—') ?></td>
          <td style="font-size:12px"><?= date('d.m.Y H:i', (int)$o['order_date']) ?></td>
          <td><?= h($o['_reason']) ?></td><td><?= $o['_profit']===null?'—':tl((float)$o['_profit']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php elseif (in_array($key,['loss','below_target'],true)): ?>
      <table>
        <tr><th>Barkod</th><th>Ürün</th><th>Satış</th><th>Maliyet</th><th>Tahmini Kâr</th></tr>
        <?php foreach (array_slice($list, 0, 50) as $p): ?>
        <tr>
          <td style="font-size:12px"><?= h($p['barcode']) ?></td>
          <td><?= h($p['name']) ?></td>
          <td><?= tl((float)$p['price']) ?></td>
          <td><?= tl((float)$p['cost']) ?></td>
          <td><?= $p['_profit']===null?'—':tl((float)$p['_profit']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <table>
        <tr><th>Barkod</th><th>Ürün</th><th>Kategori</th><th>Stok</th><th>Maliyet</th></tr>
        <?php foreach (array_slice($list, 0, 50) as $p): ?>
        <tr>
          <td style="font-size:12px"><?= h($p['barcode']) ?></td>
          <td><?= h($p['name']) ?></td>
          <td class="muted"><?= h($p['category'] ?: '—') ?></td>
          <td><?= catalog_fresh($p['stock_observed_at']??null,$now)?(int)$p['quantity']:'—' ?></td>
          <td><?= (float)$p['cost'] > 0 ? tl((float)$p['cost']) : '<span class="muted">boş</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <?php if ($count > 50): ?>
      <p class="hint" style="margin-top:8px">İlk 50 gösteriliyor (toplam <?= $count ?>).</p>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>