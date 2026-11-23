<?php
declare(strict_types=1);
// Tur 9 — Hizmet bedeli şemasının veri kaybı olmadan yükseltilmesi (eski kayıt arşivlenir,
// belirsiz kapsam aktif bağlantıda gösterilmez; yeniden senkron yeni kapsamlı tabloya yazar).
$path = tempnam(sys_get_temp_dir(), 'kf-migr-');
putenv('KARFIYAT_DB=' . $path);
// Eski (f87efab) şemayı migrate() çalışmadan önce elle kur
$raw = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$raw->exec("CREATE TABLE platform_service_fees (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    marketplace TEXT NOT NULL DEFAULT 'trendyol',
    provider_id TEXT NOT NULL,
    transaction_date INTEGER DEFAULT NULL,
    receipt_id INTEGER DEFAULT NULL,
    invoice_serial TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    debt REAL NOT NULL DEFAULT 0,
    credit REAL NOT NULL DEFAULT 0,
    order_number TEXT NOT NULL DEFAULT '',
    shipment_package_id TEXT NOT NULL DEFAULT '',
    barcode TEXT NOT NULL DEFAULT '',
    payment_order_id INTEGER DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(user_id, marketplace, provider_id)
)");
$raw->exec('CREATE INDEX idx_psf_user_date ON platform_service_fees(user_id, marketplace, transaction_date)');
$raw->exec("INSERT INTO platform_service_fees(user_id, marketplace, provider_id, transaction_date, debt, credit) VALUES(1,'trendyol','LEGACY-1',1700000000,7.77,0)");
$raw = null;

require_once __DIR__ . '/../includes/functions.php'; // migrate() burada çalışır
register_shutdown_function(static function () use ($path): void {
    foreach ([$path, $path . '-wal', $path . '-shm'] as $f) if (is_file($f)) unlink($f);
});
$failed = 0;
function check(bool $ok, string $label): void { global $failed; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) $failed++; }

// 1. Eski kayıtlar arşivde korunur
check((int) db()->query('SELECT COUNT(*) FROM platform_service_fees_legacy')->fetchColumn() === 1, 'Eski kayıt platform_service_fees_legacy arşivinde korunur');
check((float) db()->query("SELECT debt FROM platform_service_fees_legacy WHERE provider_id='LEGACY-1'")->fetchColumn() === 7.77, 'Arşivdeki kaydın tutarı korunur');
// 2. Yeni tablo boş kurulur (kaynak mağaza/ortam belirsiz → aktif bağlantıya taşınmaz)
check((int) db()->query('SELECT COUNT(*) FROM platform_service_fees')->fetchColumn() === 0, 'Yeni platform_service_fees boş kurulur (belirsiz kayıt taşınmaz)');
// 3. Aktif bağlantı sorgusu (kapsam doğrulanmış) eski kaydı göstermez
$q = db()->prepare("SELECT COUNT(*) FROM platform_service_fees WHERE user_id = ? AND marketplace = 'trendyol' AND seller_id = ? AND stage = ?");
$q->execute([1, 'x', 0]);
check((int) $q->fetchColumn() === 0, 'Aktif bağlantı (seller_id/stage) sorgusu eski kaydı göstermez');
// 4. Migrasyonun ikinci çalıştırılması idempotent
migrate();
check((int) db()->query('SELECT COUNT(*) FROM platform_service_fees_legacy')->fetchColumn() === 1 && (int) db()->query('SELECT COUNT(*) FROM platform_service_fees')->fetchColumn() === 0, 'Migrasyonun ikinci çalıştırılması idempotent');
// 5. Yükseltme sonrası yeniden senkron yeni kapsamlı tabloya yazar, arşivi etkilemez
db()->prepare("INSERT INTO users(id, email, password_hash) VALUES(1, 'migr@example.test', 'unused')")->execute();
db()->prepare("INSERT INTO integrations(user_id, marketplace, seller_id, stage) VALUES(1, 'trendyol', 'seller1', 0)")->execute();
$client = new class {
    public array $serviceFees = [['id' => 'NEW-1', 'transactionType' => 'DeductionInvoices', 'transactionDate' => 1700000000000, 'debt' => 3.5, 'credit' => 0]];
    public function getPlatformServiceFees(...$a): array { return ['ok' => true, 'complete' => true, 'rows' => $this->serviceFees]; }
};
$r = sync_platform_service_fees(1, 'trendyol', 30, $client);
check($r['ok'] && (int) db()->query("SELECT COUNT(*) FROM platform_service_fees WHERE provider_id='NEW-1' AND seller_id='seller1' AND stage=0")->fetchColumn() === 1, 'Yükseltme sonrası yeniden senkron yeni kapsamlı tabloya yazar');
check((int) db()->query('SELECT COUNT(*) FROM platform_service_fees_legacy')->fetchColumn() === 1, 'Yeniden senkron arşiv kaydını etkilemez');

echo $failed ? "$failed test başarısız\n" : "TÜM TESTLER GEÇTİ\n";
exit($failed ? 1 : 0);
