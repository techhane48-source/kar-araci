<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/order_accounting.php';

$user = require_login();
if (!is_pro($user)) e_redirect('odeme.php');

$prefill = $_GET['order'] ?? '';
$order = is_string($prefill) && preg_match('/^[0-9]{1,32}$/D', $prefill) ? $prefill : '';
$payment = '';
$invoice = '';
$settlements = $packages = $claims = $ledger = $cargo = [];
$cargo_total = 0;
$cargoFailed = false;
$cachedCargo = [];
$cargoProfit = null;
$claims_error = false;
$errors = [];
$searched = false;
$integration = get_integration((int)$user['id'], 'trendyol');
$invoicedOrders = $integration ? invoiced_orders_for_store($integration) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? null) === 'sync_invoiced_orders') {
    $token = $_POST['csrf'] ?? null;
    if (!is_string($token) || !csrf_verify($token)) {
        flash_set('error', 'Oturum süresi doldu.');
    } elseif (!$integration) {
        flash_set('error', 'Önce Trendyol mağazanı bağla.');
    } else {
        $pastOrders = sync_orders((int)$user['id'], 'trendyol', 30);
        $pastInvoices = sync_cargo_invoices((int)$user['id'], 'trendyol', 30);
        $message = $pastOrders['ok'] ? "{$pastOrders['count']} paket çekildi." : 'Siparişler: ' . $pastOrders['error'];
        $message .= $pastInvoices['ok'] ? " {$pastInvoices['invoices']} kargo faturası çekildi." : ' Kargo faturaları: ' . $pastInvoices['error'];
        flash_set($pastOrders['ok'] && $pastInvoices['ok'] ? 'success' : 'error', $message);
    }
    e_redirect('siparis-muhasebe.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order = is_string($_POST['order'] ?? null) ? trim($_POST['order']) : '';
    $payment = is_string($_POST['payment'] ?? null) ? trim($_POST['payment']) : '';
    $invoice = is_string($_POST['invoice'] ?? null) ? trim($_POST['invoice']) : '';
    if (!csrf_verify(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
        $errors[] = 'Oturum süresi doldu.';
    } elseif (!preg_match('/^[0-9]{1,32}$/D', $order) || ($payment !== '' && !preg_match('/^[1-9][0-9]{0,19}$/D', $payment)) ||
        ($invoice !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $invoice))) {
        $errors[] = 'Sipariş, ödeme emri veya fatura numarası geçersiz.';
    } else {
        $packages = order_accounting_packages((int)$user['id'], $order);
        if (!$packages || !$integration) {
            $errors[] = 'Bu sipariş bağlı Trendyol mağazanızın yerel kayıtlarında bulunamadı.';
        } else {
            $searched = true;
            $cachedCargo = order_cargo_records($integration, $order);
            $cargoProfit = order_cargo_profit_view($integration, $packages, $cachedCargo);
            $settlements = order_accounting_settlements((int)$user['id'], $order);
            require_once __DIR__ . '/../includes/trendyol.php';
            $client = new TrendyolClient($integration['seller_id'], $integration['api_key'],
                $integration['api_secret'], (bool)$integration['stage'], order_accounting_transport(), true);
            try {
                $result = $client->getOrderClaims($order);
                if (!$result['ok']) throw new RuntimeException($result['error']);
                $claims = order_accounting_claims($result['rows'], $order, $packages);
            } catch (RuntimeException $e) {
                $claims_error = true;
                $errors[] = 'İadeler: ' . $e->getMessage();
            }
            if ($payment !== '') {
                $result = $client->getPaymentOrderLedger($payment);
                if ($result['ok']) {
                    $ledger = order_accounting_ledger($result['rows'], $order);
                    $ledger['rows'] = order_accounting_flag_duplicates($ledger['rows'], $settlements);
                } else {
                    $errors[] = 'Ödeme emri: ' . $result['error'];
                }
            }
            if ($invoice !== '') {
                try {
                    $result = sync_cargo_invoice_for_order((int)$user['id'], $order, $invoice);
                    if (!$result['ok']) throw new RuntimeException($result['error']);
                    $cargo_total = count($result['rows'] ?? []);
                    $cargo = order_accounting_cargo($result['rows'] ?? [], $order);
                    $cachedCargo = order_cargo_records($integration, $order);
                    $cargoProfit = order_cargo_profit_view($integration, $packages, $cachedCargo);
                } catch (RuntimeException $e) {
                    $cargoFailed = true;
                    $errors[] = 'Kargo: ' . $e->getMessage();
                }
            }
        }
    }
}

$pageTitle = 'Sipariş Muhasebesi — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <h1>Sipariş Muhasebesi</h1>
  <?php foreach ($errors as $error): ?><p class="flash flash-error"><?= h($error) ?></p><?php endforeach; ?>
  <?php if ($flash = flash_get()): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div><?php endif; ?>
  <?php if ($integration): ?>
    <section class="card">
      <div class="toolbar">
        <h2>Kargo faturası bulunan siparişler</h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <button class="btn-ghost" name="action" value="sync_invoiced_orders">Son 30 günü çek</button>
        </form>
      </div>
      <?php if (!$invoicedOrders): ?>
        <p class="hint">Yerel sipariş geçmişinde kargo faturasıyla eşleşen teslim edilmiş sipariş bulunamadı.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>Sipariş</th><th>Tarih</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($invoicedOrders as $entry): ?>
            <tr><td><?= h($entry['order_number']) ?></td><td><?= date('d.m.Y', (int)$entry['order_date']) ?></td><td>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="order" value="<?= h($entry['order_number']) ?>">
                <button class="btn-ghost" type="submit">Aç</button>
              </form>
            </td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  <?php endif; ?>
  <form class="card" method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label for="order">Sipariş numarası</label>
    <input id="order" name="order" required inputmode="numeric" value="<?= h($order) ?>">
    <label for="payment">Ödeme emri ID (isteğe bağlı)</label>
    <input id="payment" name="payment" inputmode="numeric" value="<?= h($payment) ?>">
    <label for="invoice">Kargo faturası ID (isteğe bağlı)</label>
    <input id="invoice" name="invoice" value="<?= h($invoice) ?>">
    <button class="btn-primary" type="submit">Kayıtları getir</button>
  </form>
  <?php if ($searched): ?>
    <p>Kesin net kâr ve banka mutabakatı: Bekleniyor. Aşağıdaki tutarlar yalnızca listelenen kaynak kayıtlarını kapsar.</p>
    <section class="card">
      <h2>Paketler ve indirimler</h2>
      <?php foreach ($packages as $package): ?>
        <p><?= h($package['package_number']) ?> · <?= h($package['status']) ?> · <?= tl((float)$package['total_price']) ?></p>
        <ul><?php foreach (json_decode($package['lines'], true) ?: [] as $line): ?>
          <li><?= h((string)($line['barcode'] ?? '')) ?> · <?= order_accounting_discount_text(is_array($line) ? $line : []) ?></li>
        <?php endforeach; ?></ul>
      <?php endforeach; ?>
    </section>
    <section class="card"><h2>İade kalemleri</h2>
      <p>İade durumu talebi gösterir; finansal ters hareket ayrıca aşağıdaki cari/ödeme kayıtlarında aranır. Kabul edilen iade tek başına para hareketi kanıtı değildir.</p>
      <?php if ($claims_error): ?><p>İade sorgusu başarısız oldu (yukarıdaki hataya bakın); iade durumu bilinmiyor.</p>
      <?php elseif (!$claims): ?><p>Gösterilecek iade kalemi yok.</p><?php endif; ?>
      <ul><?php foreach ($claims as $claim): ?><li><?= h($claim['status']) ?> ·
        <?php if ($claim['match'] === 'matched'): ?>Paket <?= h($claim['package']) ?> · <?= h($claim['barcode']) ?>
        <?php elseif ($claim['match'] === 'ambiguous'): ?>Eşleşme belirsiz (satır kimliği birden fazla kayıtta veya paket bilgisi çelişkili)<?php
        else: ?>Satır eşleşmedi (yerelde kimlik bulunamadı)<?php endif; ?></li><?php endforeach; ?></ul>
    </section>
    <section class="card"><h2>Senkronize cari hareketler</h2>
      <?php if (!$settlements): ?><p>Eşleşen kimlikli cari hareket bulunamadı.</p><?php endif; ?>
      <ul><?php foreach ($settlements as $row): ?>
        <li><?= h($row['transaction_type']) ?> · Paket <?= h($row['shipment_package_id'] ?: 'Bilinmiyor') ?> · Borç <?= tl((float)$row['debt']) ?> · Alacak <?= tl((float)$row['credit']) ?></li>
      <?php endforeach; ?></ul>
    </section>
    <section class="card"><h2>Ödeme emri hareketleri</h2>
      <?php if (empty($ledger['rows'])): ?><p>Gösterilecek eşleşen hareket yok.</p><?php endif; ?>
      <ul><?php foreach ($ledger['rows'] ?? [] as $row): ?>
        <li><?= h($row['type']) ?> · Paket <?= h($row['package'] ?: 'Bilinmiyor') ?> · Borç <?= tl($row['debt']) ?> · Alacak <?= tl($row['credit']) ?> · Komisyon <?= $row['commission'] === null ? 'Bilinmiyor' : tl($row['commission']) ?><?= !empty($row['duplicate']) ? ' · Mükerrer (cari hesapta da var)' : '' ?></li>
      <?php endforeach; ?></ul>
      <?php if (!empty($ledger['invoices'])): ?><p>Ödeme emrindeki kargo faturası adayları: <?= h(implode(', ', $ledger['invoices'])) ?></p><?php endif; ?>
    </section>
    <section class="card"><h2>Kargo faturası kalemleri</h2>
      <?php if ($cargoProfit !== null): ?>
        <?php if ($cargoProfit['source'] === 'invoice'): ?>
          <p>Faturada görülen sipariş kargosu: <?= tl($cargoProfit['amount']) ?></p>
        <?php elseif ($cargoProfit['source'] === 'history'): ?>
          <p>Kargo faturası bekleniyor · Benzer gönderiden alınan geçici kargo: <?= tl($cargoProfit['amount']) ?></p>
        <?php else: ?>
          <p>Kargo gideri bekleniyor veya mevcut kayıt doğrulanamadı.</p>
        <?php endif; ?>
        <?php if ($cargoProfit['profit'] !== null): ?>
          <p><?= $cargoProfit['source'] === 'history' ? 'Geçici kâr' : 'Faturalı kargoyla hesaplanan kâr' ?>: <?= tl($cargoProfit['profit']) ?>. Diğer finans kalemleri ayrıca doğrulanır.</p>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($invoice === '' && ($cargoProfit['scope_matches'] ?? false)): $cargo = $cachedCargo; endif; ?>
      <?php if ($cargoFailed): ?><p>Fatura sorgulanamadı.</p>
      <?php elseif ($invoice === '' && !$cargo): ?><p>Bu sipariş için senkronize kargo faturası kalemi yok.</p>
      <?php elseif ($invoice !== '' && $cargo_total === 0): ?><p>Fatura boş (kalem içermiyor).</p>
      <?php elseif (!$cargo): ?><p>Faturada bu siparişe ait kalem yok.</p>
      <?php else: ?>
      <p>Kargo gideri sipariş düzeyinde eşleşir; resmî sözleşmede paket bağı bulunmadığı için paket eşleşmesi belirsizdir.</p>
      <ul><?php foreach ($cargo as $item): ?><li><?= h($item['type'] !== '' ? $item['type'] : 'Tür Bilinmiyor') ?> · Gönderi <?= h($item['parcel'] !== '' ? $item['parcel'] : 'Bilinmiyor') ?> · <?= $item['amount'] === null ? 'Bilinmiyor' : tl($item['amount']) ?><?= !empty($item['conflict']) ? ' · Çelişkili tutar (toplam dışı)' : '' ?></li><?php endforeach; ?></ul>
      <?php
        $cargoKnownTotal = 0.0; $cargoKnown = false; $cargoIncomplete = false;
        foreach ($cargo as $item) {
            if ($item['amount'] !== null && empty($item['conflict'])) { $cargoKnownTotal += $item['amount']; $cargoKnown = true; }
            else { $cargoIncomplete = true; }
        }
        if ($cargoKnown): ?><p>Bilinen kalemler toplamı: <?= tl($cargoKnownTotal) ?><?= $cargoIncomplete ? ' (bazı kalemler bilinmiyor veya çelişkili)' : '' ?></p><?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
