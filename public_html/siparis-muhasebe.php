<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/order_accounting.php';

$user = require_login();
if (!is_pro($user)) e_redirect('odeme.php');

$order = '';
$payment = '';
$invoice = '';
$settlements = $packages = $claims = $ledger = $cargo = [];
$errors = [];
$searched = false;

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
        $integration = get_integration((int)$user['id'], 'trendyol');
        if (!$packages || !$integration) {
            $errors[] = 'Bu sipariş bağlı Trendyol mağazanızın yerel kayıtlarında bulunamadı.';
        } else {
            $searched = true;
            $settlements = order_accounting_settlements((int)$user['id'], $order);
            require_once __DIR__ . '/../includes/trendyol.php';
            $client = new TrendyolClient($integration['seller_id'], $integration['api_key'],
                $integration['api_secret'], (bool)$integration['stage'], order_accounting_transport(), true);
            try {
                $result = $client->getOrderClaims($order);
                if (!$result['ok']) throw new RuntimeException($result['error']);
                $claims = order_accounting_claims($result['rows'], $order, $packages);
            } catch (RuntimeException $e) {
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
                    $result = $client->getCargoInvoiceItems($invoice);
                    if (!$result['ok']) throw new RuntimeException($result['error']);
                    $cargo = order_accounting_cargo($result['rows'], $order);
                } catch (RuntimeException $e) {
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
      <?php if (!$claims): ?><p>Gösterilecek iade kalemi yok.</p><?php endif; ?>
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
      <?php if (!$cargo): ?><p>Gösterilecek eşleşen kargo kalemi yok.</p><?php endif; ?>
      <ul><?php foreach ($cargo as $item): ?><li><?= h($item['type']) ?> · Gönderi <?= h($item['parcel']) ?> · <?= tl($item['amount']) ?></li><?php endforeach; ?></ul>
    </section>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
