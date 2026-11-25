<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = require_login();
$pro = is_pro($u);
if (!$pro) {
    flash_set('error', 'Pazar Zekası Pro plan özelliğidir.');
    e_redirect('odeme.php');
}

// Official Buybox: read-only requests, restricted to products owned by the current user.
$bbIntegration=get_integration((int)$u['id'],'trendyol');
$bbPage=max(1,(int)($_POST['bb_page']??$_GET['bb_page']??1));
$bbSearch=trim((string)($_GET['bb_q']??$_POST['bb_q']??''));
$bbRows=[];$bbMessage=null;$bbTotal=0;
if ($bbIntegration) {
    $filter="user_id=? AND marketplace='trendyol' AND barcode!=''";
    $args=[$u['id']];
    if ($bbSearch!=='') {$filter.=" AND (barcode LIKE ? OR name LIKE ?)";$args[]='%'.$bbSearch.'%';$args[]='%'.$bbSearch.'%';}
    $q=db()->prepare("SELECT COUNT(*) FROM products WHERE ".$filter);$q->execute($args);$bbTotal=(int)$q->fetchColumn();
    $bbPage=min($bbPage,max(1,(int)ceil($bbTotal/50)));$offset=($bbPage-1)*50;
    $q=db()->prepare("SELECT * FROM products WHERE ".$filter." ORDER BY id LIMIT 50 OFFSET ".$offset);
    $q->execute($args);$bbRows=$q->fetchAll();
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='buybox_refresh') {
        if (!csrf_verify($_POST['csrf']??'')) $bbMessage='Oturum süresi doldu.';
        else {
            $client=integration_client($bbIntegration);$count=0;$missing=0;
            $up=db()->prepare("INSERT INTO trendyol_buybox(user_id,seller_id,barcode,buybox_rank,buybox_price,multiple_sellers,second_price,third_price,checked_at)
              VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(user_id,seller_id,barcode) DO UPDATE SET
              buybox_rank=excluded.buybox_rank,buybox_price=excluded.buybox_price,multiple_sellers=excluded.multiple_sellers,
              second_price=excluded.second_price,third_price=excluded.third_price,checked_at=excluded.checked_at");
            $del=db()->prepare("DELETE FROM trendyol_buybox WHERE user_id=? AND seller_id=? AND barcode=?");
            foreach(array_chunk(array_column($bbRows,'barcode'),10) as $chunk) {
                $res=$client->getBuybox($chunk);
                if(!$res['ok']) {$bbMessage=$res['error'].' Önceki kayıtlar eski tarihleriyle gösterilir.';break;}
                foreach($res['items'] as $r) {
                    $up->execute([$u['id'],$bbIntegration['seller_id'],$r['barcode'],$r['rank'],$r['price'],(int)$r['multiple'],$r['second'],$r['third'],time()]);$count++;
                }
                foreach($res['missing'] as $bc) {$del->execute([$u['id'],$bbIntegration['seller_id'],$bc]);$missing++;}
            }
            if($bbMessage===null)$bbMessage="$count barkod güncellendi; $missing barkod için veri dönmedi.";
        }
    }
    $lookup=db()->prepare("SELECT * FROM trendyol_buybox WHERE user_id=? AND seller_id=? AND barcode=?");
    foreach($bbRows as &$row) {$lookup->execute([$u['id'],$bbIntegration['seller_id'],$row['barcode']]);$row['_buybox']=$lookup->fetch()?:null;}
    unset($row);
}

// --- Ekle ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_set('error', 'Oturum süresi doldu.');
    } else {
        $type = in_array($_POST['type'] ?? '', ['store', 'product'], true) ? $_POST['type'] : 'store';
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $note = trim($_POST['note'] ?? '');
        if ($name === '' || $url === '') {
            flash_set('error', 'Ad ve URL zorunludur.');
        } else {
            db()->prepare("INSERT INTO trackers (user_id, type, name, url, note) VALUES (?,?,?,?,?)")
                ->execute([$u['id'], $type, $name, $url, $note]);
            flash_set('success', 'Takip eklendi: ' . $name);
        }
    }
    e_redirect('pazar-zekasi.php');
}

// --- Sil ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'del') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_set('error', 'Oturum süresi doldu.');
    } else {
        db()->prepare("DELETE FROM trackers WHERE id = ? AND user_id = ?")->execute([(int)($_POST['id'] ?? 0), $u['id']]);
        flash_set('success', 'Takip silindi.');
    }
    e_redirect('pazar-zekasi.php');
}

// --- Best-effort kontrol ---
$checkResults = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        flash_set('error', 'Oturum süresi doldu.');
    } else {
        $tid = (int)($_POST['id'] ?? 0);
        $t = db()->prepare("SELECT * FROM trackers WHERE id = ? AND user_id = ?");
        $t->execute([$tid, $u['id']]);
        $tr = $t->fetch();
        if ($tr) {
            $res = fetch_tracker_price($tr['url']);
            if ($res['ok']) {
                db()->prepare("UPDATE trackers SET last_price = ?, last_checked = datetime('now') WHERE id = ?")
                    ->execute([$res['price'], $tid]);
                $checkResults[$tid] = ['ok' => true, 'price' => $res['price']];
            } else {
                $checkResults[$tid] = ['ok' => false, 'error' => $res['error']];
            }
        }
    }
}

// --- URL'den fiyat çekmeyi dene (best-effort; bot koruması olabilir) ---
function fetch_tracker_price(string $url): array
{
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, ['www.trendyol.com','trendyol.com'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
        return ['ok'=>false,'error'=>'Şimdilik yalnızca doğrudan HTTPS Trendyol ürün bağlantıları desteklenir.'];
    }
    $ip = gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
        return ['ok'=>false,'error'=>'Güvenli ürün adresi çözümlenemedi.'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RESOLVE => [$host . ':443:' . $ip],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            'Accept-Language: tr-TR,tr;q=0.9',
        ],
    ]);
    $html = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($html === false) return ['ok' => false, 'error' => 'Bağlantı hatası: ' . $err];
    if ($http !== 200) return ['ok' => false, 'error' => "HTTP $http — sayfa erişilemedi (bot koruması olabilir)."];

    // 1) JSON-LD Product/Offer fiyatı
    if (preg_match('/"price"\s*:\s*"?(\d+(?:[.,]\d+)?)"?/i', $html, $m)) {
        $price = (float) str_replace(',', '.', $m[1]);
        if ($price > 0) return ['ok' => true, 'price' => $price];
    }
    // 2) "salePrice"/"price" JSON alanı
    if (preg_match('/"(?:salePrice|price)"\s*:\s*"?(\d+(?:[.,]\d+)?)"?/i', $html, $m)) {
        $price = (float) str_replace(',', '.', $m[1]);
        if ($price > 0) return ['ok' => true, 'price' => $price];
    }
    return ['ok' => false, 'error' => 'Fiyat sayfada bulunamadı (sayfa JS ile yükleniyor olabilir).'];
}

$trs = db()->prepare("SELECT * FROM trackers WHERE user_id = ? ORDER BY id DESC");
$trs->execute([$u['id']]);
$trackers = $trs->fetchAll();

// Fiyat geçmişi (her takip için son 3 kayıt)
$history = [];
foreach ($trackers as $t) {
    $h = db()->prepare("SELECT price, checked_at FROM tracker_snapshots WHERE tracker_id = ? ORDER BY id DESC LIMIT 3");
    $h->execute([$t['id']]);
    $history[$t['id']] = array_reverse($h->fetchAll());
}

$pageTitle = 'Pazar Zekası — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar"><h1>Pazar Zekası</h1></div>
<div class="card table-card" style="margin:16px 0">
<h2>Trendyol Buybox — resmî API</h2>
<p class="hint">Sıra, ilk üç fiyat ve çoklu satıcı bilgisi Trendyol'dan gelir. Kâr ve taban fiyat tahminidir. Fiyatı otomatik değiştirmez; Buybox kazanma garantisi vermez.</p>
<?php if(!$bbIntegration): ?><p>Önce <a href="magaza.php">Trendyol mağazanızı bağlayın</a>.</p>
<?php else: ?>
<?php if($bbMessage!==null): ?><p><?= h($bbMessage) ?></p><?php endif; ?>
<form method="get"><input name="bb_q" value="<?= h($bbSearch) ?>" placeholder="Barkod veya ürün"><button>Ara</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="buybox_refresh"><input type="hidden" name="bb_page" value="<?= $bbPage ?>"><input type="hidden" name="bb_q" value="<?= h($bbSearch) ?>"><button>Bu sayfanın Buybox verisini yenile</button></form>
<table><tr><th>Ürün / barkod</th><th>Bizim fiyat</th><th>Sıra</th><th>Buybox fiyatı</th><th>2. / 3.</th><th>Çoklu satıcı</th><th>Buybox fiyatında tahmini kâr</th><th>Başabaş fiyatı</th><th>Son sorgu</th></tr>
<?php foreach($bbRows as $row): $bb=$row['_buybox']; $hasCost=(float)$row['cost']>0; $floor=$hasCost?product_target_price($row,0,0):null; $estimate=$bb&&$hasCost&&(float)$bb['buybox_price']>0?product_profit($row,(float)$bb['buybox_price']):null; ?>
<tr><td><?= h($row['name']) ?><br><?= h($row['barcode']) ?></td><td><?= tl((float)$row['price']) ?></td><td><?= $bb&&(int)$bb['buybox_rank']>0?(int)$bb['buybox_rank']:'—' ?></td>
<td><?= $bb&&(float)$bb['buybox_price']>0?tl((float)$bb['buybox_price']):'—' ?></td>
<td><?= $bb&&$bb['second_price']!==null?tl((float)$bb['second_price']):'—' ?> / <?= $bb&&$bb['third_price']!==null?tl((float)$bb['third_price']):'—' ?></td><td><?= $bb?((int)$bb['multiple_sellers']?'Evet':'Hayır'):'—' ?></td>
<td><?= $estimate?tl($estimate['profit']):'—' ?></td><td><?= $floor&&$floor['ok']?tl(ceil($floor['price']*100)/100):'—' ?></td><td><?= $bb?date('d.m H:i',(int)$bb['checked_at']):'Veri yok' ?></td></tr>
<?php endforeach; ?></table>
<p>Sayfa <?= $bbPage ?> / <?= max(1,(int)ceil($bbTotal/50)) ?> · <?= $bbTotal ?> ürün
<?php if($bbPage>1): ?><a href="?bb_page=<?= $bbPage-1 ?>&bb_q=<?= urlencode($bbSearch) ?>">Önceki</a><?php endif; ?>
<?php if($bbPage*50<$bbTotal): ?><a href="?bb_page=<?= $bbPage+1 ?>&bb_q=<?= urlencode($bbSearch) ?>">Sonraki</a><?php endif; ?></p>
<?php endif; ?></div>


  <?php $flash = flash_get(); if ($flash): ?><div class="alert alert-<?= $flash['type'] ?>"><?= h($flash['msg']) ?></div><?php endif; ?>

  <div class="alert" style="margin:16px 0;border-left:3px solid var(--accent)">
    <b>Tarayıcı servisi:</b> Bu liste <b>ayrı bir headless-Chrome servisi</b> (<code>crawler/</code>) tarafından düzenli taranır — fiyat ve ürün sayısı otomatik güncellenir, geçmiş kaydedilir. Servisi başlatmak için: <code>cd crawler && npm install && node crawl.js</code> (sürekli döngü) ya da <code>node crawl.js --once</code> (tek tur, Görev Zamanlayıcı için). "Kontrol Et" düğmesi ise sayfadan tek seferlik hızlı bir denemedir (bot korumasına takılabilir; servis daha güvenilirdir).
  </div>

  <div class="card" style="margin:16px 0">
    <h2>Takip Ekle</h2>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="add">
      <div class="field" style="margin:0"><label>Tür</label>
        <select name="type" style="width:auto">
          <option value="store">Rakip Mağaza</option>
          <option value="product">Rakip Ürün</option>
        </select>
      </div>
      <div class="field" style="margin:0"><label>Ad</label><input type="text" name="name" required placeholder="örn. Rakip Mağaza A" style="width:180px"></div>
      <div class="field" style="margin:0;flex:1;min-width:240px"><label>URL</label><input type="url" name="url" required placeholder="https://www.trendyol.com/..." style="width:100%"></div>
      <div class="field" style="margin:0"><label>Not (ops.)</label><input type="text" name="note" placeholder="örn. aynı ürün grubu" style="width:160px"></div>
      <button type="submit" class="btn-primary">Ekle</button>
    </form>
  </div>

  <div class="card table-card" style="margin:16px 0">
    <h2>Takip Listesi (<?= count($trackers) ?>)</h2>
    <?php if (!$trackers): ?>
      <p class="hint">Henüz takip eklenmedi.</p>
    <?php else: ?>
      <table>
        <tr><th>Ad</th><th>Tür</th><th>URL</th><th>Son Fiyat</th><th>Geçmiş</th><th>Son Kontrol</th><th></th></tr>
        <?php foreach ($trackers as $t): ?>
        <tr>
          <td><?= h($t['name']) ?></td>
          <td><?= $t['type'] === 'store' ? 'Mağaza' : 'Ürün' ?></td>
          <td style="font-size:12px"><a href="<?= h($t['url']) ?>" target="_blank" rel="noopener"><?= h(mb_strimwidth($t['url'], 0, 48, '…')) ?></a></td>
          <td><?= $t['last_price'] !== null ? tl((float)$t['last_price']) : '<span class="hint">—</span>' ?></td>
          <td style="font-size:12px">
            <?php $h3 = $history[$t['id']] ?? []; ?>
            <?php if ($h3): ?>
              <?php foreach ($h3 as $i => $sn): ?>
                <?php $prev = $i > 0 ? $h3[$i - 1]['price'] : null; ?>
                <span class="<?= ($prev !== null && $sn['price'] > $prev) ? 'neg' : (($prev !== null && $sn['price'] < $prev) ? 'pos' : '') ?>" title="<?= h($sn['checked_at']) ?>"><?= number_format((float)$sn['price'], 2, ',', '.') ?>₺</span><?= $i < count($h3) - 1 ? ' → ' : '' ?>
              <?php endforeach; ?>
            <?php else: ?><span class="hint">—</span><?php endif; ?>
          </td>
          <td style="font-size:12px"><?= $t['last_checked'] ?: '—' ?></td>
          <td style="display:flex;gap:8px;justify-content:flex-end">
            <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="check"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn-ghost">Kontrol Et</button></form>
            <form method="post" onsubmit="return confirm('Bu takibi silmek istediğine emin misin?')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn-ghost">Sil</button></form>
          </td>
        </tr>
        <?php if (isset($checkResults[$t['id']])): ?>
          <tr><td colspan="6">
            <?php if ($checkResults[$t['id']]['ok']): ?>
              <span class="pos">Fiyat bulundu: <?= tl($checkResults[$t['id']]['price']) ?></span>
            <?php else: ?>
              <span class="neg"><?= h($checkResults[$t['id']]['error']) ?></span>
            <?php endif; ?>
          </td></tr>
        <?php endif; ?>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
