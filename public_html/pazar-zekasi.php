<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = require_login();
$pro = is_pro($u);
if (!$pro) {
    flash_set('error', 'Pazar Zekası Pro plan özelliğidir.');
    e_redirect('odeme.php');
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
    if (!preg_match('#^https?://#i', $url)) return ['ok' => false, 'error' => 'Geçersiz URL.'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
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
