<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
$u = require_login();

$mps = array_column(db()->query("SELECT marketplace FROM integrations WHERE user_id = {$u['id']}")->fetchAll(), 'marketplace');
if (!$mps) $mps = ['trendyol'];
$mp = $_GET['mp'] ?? $mps[0];
if (!in_array($mp, $mps, true)) $mp = $mps[0];

$rapor = $_GET['r'] ?? 'order';
$allowed = ['order', 'product', 'category', 'return', 'ads', 'campaign', 'buybox', 'claims'];
if (!in_array($rapor, $allowed, true)) $rapor = 'order';

$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 15, 30, 90], true)) $days = 30;
$fromTs = strtotime('today') - ($days - 1) * 86400;

// Ürünler (barkod -> ürün)
$byBarcode = [];
foreach (db()->query("SELECT * FROM products WHERE user_id = {$u['id']} AND marketplace = " . db()->quote($mp))->fetchAll() as $p) {
    if ($p['barcode'] !== '') $byBarcode[$p['barcode']] = $p;
}

// Siparişler
$orders = db()->query("SELECT * FROM orders WHERE user_id = {$u['id']} AND marketplace = " . db()->quote($mp) . " AND order_date >= $fromTs ORDER BY order_date DESC")->fetchAll();
foreach ($orders as &$o) $o['lines'] = json_decode($o['lines'] ?? '[]', true) ?: [];
unset($o);

// Report semantics: only verified inputs; no fabricated advertising attribution or return loss.
$reportRows=[];$reportCols=[];$summary=[];$reportNote='';
$excludedCurrency=count(array_filter($orders,static fn($o)=>!order_is_domestic_try($o)));
$money=static fn(float $n):string=>tl($n);
if($rapor==='order'){
    $reportCols=['Sipariş No','Tarih','Tutar','Tahmini Kâr','Kâr Oranı %','Kâr Marjı %','Durum'];
    $sale=0.0;$knownSale=0.0;$profit=0.0;$count=0;$known=0;
    foreach($orders as $o){
        if(!order_is_active_sale($o))continue;
        $pr=calc_order_profit($o,$byBarcode);$amount=(float)$o['total_price'];$sale+=$amount;$count++;
        if($pr['ok']){$known++;$knownSale+=$amount;$profit+=$pr['profit'];}
        $reportRows[]=[$o['order_number'],date('d.m.Y',(int)$o['order_date']),$amount,
            $pr['ok']?$pr['profit']:null,$pr['ok']?$pr['ratio']:null,$pr['ok']?$pr['margin']:null,
            $pr['ok']?'Tahmini':($pr['reason']??'Veri eksik')];
    }
    $summary=['Ciro'=>$money($sale),'Hesaplanan ciro'=>$money($knownSale),'Tahmini kâr'=>$known?$money($profit):'—','Kapsam'=>"$known / $count"];
    $reportNote='Sipariş maliyeti, komisyon ve kargo tahminleri kullanılır. Gerçek finansal mutabakat değildir.';
}elseif(in_array($rapor,['product','category'],true)){
    $category=$rapor==='category';
    $reportCols=$category?['Kategori','Adet','Ciro','Maliyeti bilinen ciro','Gözlemlenen maliyet','Kesintiler öncesi fark','Maliyeti eksik adet']:
        ['Barkod','Ürün','Adet','Ciro','Maliyeti bilinen ciro','Gözlemlenen maliyet','Kesintiler öncesi fark','Maliyeti eksik adet'];
    $agg=aggregate_report_lines($orders,$byBarcode,$category);$total=0.0;$covered=0.0;$cost=0.0;$missing=0;
    foreach($agg['groups'] as $key=>$g){
        $prefix=$category?[(string)$key]:[(string)$key,$g['name']];
        $reportRows[]=array_merge($prefix,[$g['qty'],$g['sale'],$g['known_sale'],$g['qty']>$g['missing_qty']?$g['cost']:null,
            $g['qty']>$g['missing_qty']?$g['known_sale']-$g['cost']:null,$g['missing_qty']]);
        $total+=$g['sale'];$covered+=$g['known_sale'];$cost+=$g['cost'];$missing+=$g['missing_qty'];
    }
    $summary=['Eşleşen ciro'=>$money($total),'Maliyeti bilinen ciro'=>$money($covered),'Gözlemlenen maliyet'=>$money($cost),'Eksik maliyet adedi'=>$missing];
    $reportNote='Net kâr değildir; komisyon ve paket kargosu ürünlere dağıtılmadı. İlk gözlemlenen maliyet kullanılır. Tutarı eşleşmeyen '.$agg['excluded'].' paket dışarıda.';
}elseif($rapor==='claims'){
    $reportCols=['İade talebi','Sipariş','Barkod','Ürün','Statü','İade sebebi','Son güncelleme','Eşleşme','Gözlemlenen birim maliyet'];
    $q=db()->prepare("SELECT c.*,o.package_number AS matched_package,o.order_number AS matched_order,o.currency_code,o.country_code,o.lines
        FROM trendyol_claim_items c LEFT JOIN orders o ON o.user_id=c.user_id AND o.marketplace='trendyol'
        AND o.package_number=c.outbound_package AND o.order_number=c.order_number
        WHERE c.user_id=? AND c.modified_ms>=? ORDER BY c.modified_ms DESC,c.item_id");
    $q->execute([$u['id'],$fromTs*1000]);$states=[];$matched=0;$known=0;
    if($mp==='trendyol')foreach($q->fetchAll() as $row){
        $order=$row['matched_package']===null?null:['package_number'=>$row['matched_package'],'order_number'=>$row['matched_order'],'currency_code'=>$row['currency_code'],'country_code'=>$row['country_code'],'lines'=>$row['lines']];
        $match=claim_order_match($row,$order);
        $states[$row['status']]=($states[$row['status']]??0)+1;
        if($match['state']==='Paket ve ürün satırı eşleşti')$matched++;
        if($match['cost']!==null)$known++;
        $reportRows[]=[$row['claim_id'],$row['order_number'],$row['barcode'],$row['product_name'],$row['status'],$row['reason'],date('d.m.Y H:i',intdiv((int)$row['modified_ms'],1000)),$match['state'].' / '.$match['scope'],$match['cost']];
    }
    $summary=['İade kalemi'=>count($reportRows),'Kabul edilmiş'=>$states['Accepted']??0,'Aksiyon bekleyen'=>$states['WaitingInAction']??0,'Eşleşen ürün satırı'=>$matched,'Maliyeti bilinen'=>$known];
    $reportNote='İade kalemi durumu, finansal geri ödeme değildir. Tarih filtresi son güncelleme tarihine göre uygulanır. Maliyet ilk gözlemlenen tutardır; stok durumu, geri ödeme ve kargo payı doğrulanmadan zarar hesaplanmaz. '.sync_coverage_label(db(),(int)$u['id'],$mp,'claims_query',$fromTs,time());
}elseif($rapor==='return'||$rapor==='campaign'){
    $types=$rapor==='return'?['Return','ManualRefund','ManualRefundCancel']:['Discount','DiscountCancel','Coupon','CouponCancel','TyDiscount','TyDiscountCancel','TyCoupon','TyCouponCancel'];
    $reportCols=['Barkod','Ürün','İşlem türü','İşlem kaydı adedi','Alacak','Borç','Borç − alacak'];
    $q=db()->prepare("SELECT barcode,query_type,COUNT(*) AS n,SUM(credit) AS credit,SUM(debt) AS debt FROM settlements
        WHERE user_id=? AND marketplace=? AND external_id IS NOT NULL AND finance_scope='domestic' AND transaction_date>=? AND query_type IN (".implode(',',array_fill(0,count($types),'?')).") GROUP BY barcode,query_type ORDER BY barcode,query_type");
    $q->execute(array_merge([$u['id'],$mp,$fromTs],$types));
    $credit=0.0;$debt=0.0;$n=0;
    foreach($q->fetchAll() as $row){$bc=(string)$row['barcode'];$c=(float)$row['credit'];$d=(float)$row['debt'];$count=(int)$row['n'];
        $reportRows[]=[$bc,$byBarcode[$bc]['name']??$bc,(string)$row['query_type'],$count,$c,$d,$d-$c];$credit+=$c;$debt+=$d;$n+=$count;}
    $summary=['Finansal işlem kaydı'=>$n,'Alacak'=>$money($credit),'Borç'=>$money($debt),'Borç − alacak'=>$money($debt-$credit)];
    $reportNote=$rapor==='return'?'Finansal işlem adedi ürün iade adedi değildir. İade tutarı, yeniden satılabilirlik ve gerçek kargo maliyeti eşleşmeden iade zararı hesaplanmaz.':'Satıcı ve Trendyol kaynaklı indirim/kuponlar ayrı işlem türleridir; iptaller dahil. Tarih finansal işlem tarihidir; kampanya kârlılığı değildir.';
}elseif($rapor==='buybox'){
    $reportCols=['Barkod','Ürün','Bizim Fiyat','Buybox Sırası','Buybox Fiyatı','Fiyat Farkı','Son Sorgu'];
    $integration=get_integration((int)$u['id'],$mp);
    $data=[];
    if($mp==='trendyol'&&$integration){
        $q=db()->prepare("SELECT * FROM trendyol_buybox WHERE user_id=? AND seller_id=?");
        $q->execute([$u['id'],$integration['seller_id']]);foreach($q->fetchAll() as $row)$data[$row['barcode']]=$row;
    }
    $known=0;$first=0;
    foreach($byBarcode as $bc=>$product){
        $bb=$data[$bc]??null;$price=(float)$product['price'];$bp=$bb&&(float)$bb['buybox_price']>0?(float)$bb['buybox_price']:null;
        $rank=$bb&&(int)$bb['buybox_rank']>0?(int)$bb['buybox_rank']:null;
        if($rank!==null){$known++;if($rank===1)$first++;}
        $reportRows[]=[(string)$bc,$product['name'],$price,$rank,$bp,$bp===null?null:$price-$bp,$bb?date('d.m.Y H:i',(int)$bb['checked_at']):null];
    }
    $summary=['Ürün'=>count($byBarcode),'Sırası bilinen'=>$known,'Birinci sırada'=>$first];
    $reportNote='Pazar Zekası ekranında sorgulanan resmî Buybox verilerinin son görüntüsü. Tarih filtresi uygulanmaz; sorgu zamanı gösterilir.';
}else{
    $reportCols=['İşlem tarihi','Belge kimliği','İşlem açıklaması','Alacak','Borç'];
    $q=db()->prepare("SELECT transaction_date,external_id,transaction_type,raw_json FROM trendyol_financial_documents WHERE user_id=? AND transaction_date>=? ORDER BY transaction_date DESC");
    $q->execute([$u['id'],$fromTs]);$credit=0.0;$debt=0.0;
    if($mp==='trendyol')foreach($q->fetchAll() as $row){
        $raw=json_decode($row['raw_json'],true)?:[];$label=(string)$row['transaction_type'].' '.(string)($raw['description']??'');
        if(!preg_match('/reklam|influencer|advertis/i',$label))continue;
        $c=is_numeric($raw['credit']??null)?(float)$raw['credit']:null;$d=is_numeric($raw['debt']??null)?(float)$raw['debt']:null;
        $reportRows[]=[date('d.m.Y',(int)$row['transaction_date']),(string)$row['external_id'],$label,$c,$d];
        $credit+=$c??0;$debt+=$d??0;
    }
    $summary=['Eşleşen belge'=>count($reportRows),'Bilinen alacak'=>$money($credit),'Bilinen borç'=>$money($debt)];
    $reportNote='Belge açıklamasında reklam veya influencer ifadesi bulunan finansal kayıtlar. Reklam kaynaklı satış ve ROAS için ayrı doğrulanmış veri gerekir; tüm satışlar reklam satışı kabul edilmez.';
}
if(in_array($rapor,['return','campaign','ads'],true)){
    $job=$rapor==='ads'?'documents':'settlements';
    $q=db()->prepare("SELECT state,last_success FROM sync_status WHERE user_id=? AND marketplace=? AND job=?");$q->execute([$u['id'],$mp,$job]);$st=$q->fetch();
    $reportNote.=' '.sync_coverage_label(db(),(int)$u['id'],$mp,$job.'_transaction',$fromTs,time()).' Son başarılı senkron: '.($st&&!empty($st['last_success'])?date('d.m.Y H:i',(int)$st['last_success']):'yok').'. Son işlem: '.($st['state']??'bilinmiyor').'. Kapsamı doğrulanmamış eski finans kayıtları tekrar çekilmeli.';
}

// CSV indir
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rapor-' . $rapor . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $reportCols);
    foreach ($reportRows as $r) fputcsv($out, array_map(fn($v) => is_float($v) ? number_format($v, 2, '.', '') : (is_string($v)?csv_safe_text($v):$v), $r));
    fclose($out);
    exit;
}

$raporLabels = [
    'order' => 'Sipariş Kârlılık Analizi',
    'product' => 'Ürün Kârlılık Analizi',
    'category' => 'Kategori Kârlılık Analizi',
    'claims' => 'İade Talepleri ve Durumları',
    'return' => 'İade Finansal Hareketleri',
    'ads' => 'Reklam Finansal Belgeleri',
    'campaign' => 'Kampanya Finansal Hareketleri',
    'buybox' => 'Fiyat Pozisyonu (Buybox)',
];

$per = 50;
$totalRows = count($reportRows);
$totalPages = max(1, (int)ceil($totalRows / $per));
$page = max(1, min((int)($_GET['page'] ?? 1), $totalPages));
$pageRows = array_slice($reportRows, ($page - 1) * $per, $per);

$pageTitle = 'Raporlar — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1>Raporlar</h1>
  <p class="muted">Kârlılık analizleri · <a href="raporlar.php?r=<?= h($rapor) ?>&mp=<?= urlencode($mp) ?>&days=<?= $days ?>&export=1">⬇ Raporu İndir (CSV)</a></p>
</div>

<div class="card">
  <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
    <input type="hidden" name="mp" value="<?= h($mp) ?>">
    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px">Rapor Seçin
      <select name="r" onchange="this.form.submit()" style="padding:8px">
        <?php foreach ($raporLabels as $k => $lbl): ?>
          <option value="<?= $k ?>" <?= $rapor === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="display:flex;flex-direction:column;gap:4px;font-size:13px">Tarih
      <select name="days" onchange="this.form.submit()" style="padding:8px">
        <?php foreach ([7 => 'Son 7 gün', 15 => 'Son 15 gün', 30 => 'Son 30 gün', 90 => 'Son 90 gün'] as $d => $lbl): ?>
          <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
</div>

<div class="card">
  <p class="hint"><?= h($reportNote) ?></p>
  <?php if(in_array($rapor,['order','product','category'],true)&&$excludedCurrency): ?><p class="hint"><?= $excludedCurrency ?> paket ülke/para birimi doğrulanmadığından kapsam dışı.</p><?php endif; ?>
  <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px">
  <?php foreach($summary as $label=>$value): ?><div><div class="muted"><?= h($label) ?></div><b><?= h((string)$value) ?></b></div><?php endforeach; ?>
  </div>

    <table>
      <tr><?php foreach ($reportCols as $c): ?><th><?= h($c) ?></th><?php endforeach; ?></tr>
      <?php if (!$reportRows): ?><tr><td colspan="<?= count($reportCols) ?>" class="muted">Kapsamda kayıt yok veya henüz senkronize edilmedi.</td></tr><?php endif; ?>
      <?php foreach ($pageRows as $r): ?>
        <tr>
          <?php foreach ($r as $i => $v): ?>
            <?php if ($v === null): ?><td class="muted">—</td>
            <?php elseif (is_float($v)): ?><td class="tar"><?= str_ends_with($reportCols[$i],'%') ? '%'.number_format($v,2,',','.') : tl($v) ?></td>
            <?php elseif ($i === 0): ?><td style="font-family:monospace;font-size:12px"><?= h((string)$v) ?></td>
            <?php else: ?><td><?= h((string)$v) ?></td><?php endif; ?>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($totalPages > 1): ?>
      <div class="pager" style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <span class="muted" style="font-size:13px"><?= $totalRows ?> kayıt · sayfa <?= $page ?>/<?= $totalPages ?></span>
        <?php
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
        for ($i = $start; $i <= $end; $i++):
        ?>
          <a href="raporlar.php?r=<?= h($rapor) ?>&mp=<?= urlencode($mp) ?>&days=<?= $days ?>&page=<?= $i ?>" class="<?= $i === $page ? 'btn-primary' : 'btn-ghost' ?>" style="padding:4px 10px"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
