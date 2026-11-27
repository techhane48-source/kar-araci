<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA foreign_keys=ON');
    }
    return $pdo;
}

/**
 * Şema kurulumu + yönetici seed. Tüm tablolar BURADA tanımlı olmalı
 * (paket DB içermez; temiz kurulumda ilk istekte kendini kurar).
 */
function migrate(): void
{
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL DEFAULT '',
        password_hash TEXT NOT NULL,
        plan TEXT NOT NULL DEFAULT 'free',          -- free | pro
        plan_expires TEXT DEFAULT NULL,             -- ISO date (pro bitişi)
        is_admin INTEGER NOT NULL DEFAULT 0,
        must_change_password INTEGER NOT NULL DEFAULT 0,
        totp_secret TEXT DEFAULT NULL,              -- 2FA (TOTP) sırrı, base32
        totp_enabled INTEGER NOT NULL DEFAULT 0,    -- 2FA aktif mi
        is_demo INTEGER NOT NULL DEFAULT 0,         -- demo hesabı mı
        referral_code TEXT DEFAULT NULL,            -- partner yönlendirme kodu
        referred_by INTEGER DEFAULT NULL,           -- hangi kullanıcı yönlendirdi
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        name TEXT NOT NULL DEFAULT '',
        barcode TEXT NOT NULL DEFAULT '',            -- pazaryeri barkodu (Excel eşleştirme)
        marketplace TEXT NOT NULL,                  -- trendyol | hepsiburada | amazon | n11
        category TEXT NOT NULL,                     -- kategori adı (görünen)
        rate REAL NOT NULL,                         -- komisyon oranı (%)
        cost REAL NOT NULL DEFAULT 0,               -- ürün maliyeti (alış)
        price REAL NOT NULL DEFAULT 0,              -- satış fiyatı
        cargo REAL NOT NULL DEFAULT 0,              -- kargo ücreti
        cargo_type TEXT NOT NULL DEFAULT 'seller',   -- seller | export | sameday
        extra_fee REAL NOT NULL DEFAULT 0,           -- ihracat bedeli / aynı gün bedeli (ek kesinti)
        kdv REAL NOT NULL DEFAULT 20,                -- ürün KDV oranı (%)
        service_fee REAL NOT NULL DEFAULT 0,        -- platform hizmet bedeli
        stopaj REAL NOT NULL DEFAULT 0,             -- stopaj kesintisi
        return_rate REAL NOT NULL DEFAULT 0,        -- iade oranı (%)
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_products_user ON products(user_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        amount REAL NOT NULL,
        reference TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'pending',      -- pending | approved | rejected
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        reviewed_at TEXT DEFAULT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        excerpt TEXT DEFAULT '',
        content TEXT NOT NULL DEFAULT '',
        published INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS partner_commissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        partner_id INTEGER NOT NULL,                 -- yönlendiren kullanıcı
        referred_user_id INTEGER NOT NULL,           -- yönlendirilen kullanıcı
        amount REAL NOT NULL DEFAULT 0,              -- hakediş (TL)
        month TEXT NOT NULL,                         -- YYYY-MM
        status TEXT NOT NULL DEFAULT 'pending',      -- pending | paid
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS integrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        marketplace TEXT NOT NULL,                   -- trendyol | hepsiburada | amazon | n11
        seller_id TEXT NOT NULL DEFAULT '',          -- satıcı ID (düz metin, gizli değil)
        api_key TEXT NOT NULL DEFAULT '',            -- API key (şifreli)
        api_secret TEXT NOT NULL DEFAULT '',         -- API secret (şifreli)
        stage INTEGER NOT NULL DEFAULT 0,            -- 1 = test ortamı
        last_sync TEXT DEFAULT NULL,                 -- son senkronizasyon
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE(user_id, marketplace)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_integrations_user ON integrations(user_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        marketplace TEXT NOT NULL DEFAULT 'trendyol',
        order_number TEXT NOT NULL DEFAULT '',
        package_number TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT '',
        customer_name TEXT NOT NULL DEFAULT '',
        total_price REAL NOT NULL DEFAULT 0,
        order_date INTEGER NOT NULL DEFAULT 0,       -- unix saniye
        lines TEXT NOT NULL DEFAULT '[]',            -- JSON: [{barcode, productName, quantity, price}]
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE(user_id, marketplace, package_number)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orders_user_date ON orders(user_id, order_date)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS trackers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        type TEXT NOT NULL DEFAULT 'store',          -- store | product
        name TEXT NOT NULL DEFAULT '',
        url TEXT NOT NULL DEFAULT '',
        note TEXT NOT NULL DEFAULT '',
        last_price REAL DEFAULT NULL,
        last_checked TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_trackers_user ON trackers(user_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tracker_snapshots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tracker_id INTEGER NOT NULL,
        price REAL NOT NULL DEFAULT 0,
        product_count INTEGER NOT NULL DEFAULT 0,
        title TEXT NOT NULL DEFAULT '',
        checked_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_snapshots_tracker ON tracker_snapshots(tracker_id, checked_at)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settlements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        marketplace TEXT NOT NULL DEFAULT 'trendyol',
        barcode TEXT NOT NULL DEFAULT '',
        transaction_type TEXT NOT NULL DEFAULT '',
        commission_rate REAL NOT NULL DEFAULT 0,
        commission_amount REAL NOT NULL DEFAULT 0,
        seller_revenue REAL NOT NULL DEFAULT 0,
        debt REAL NOT NULL DEFAULT 0,
        credit REAL NOT NULL DEFAULT 0,
        order_number TEXT NOT NULL DEFAULT '',
        shipment_package_id TEXT NOT NULL DEFAULT '',
        payment_period INTEGER NOT NULL DEFAULT 0,
        transaction_date INTEGER NOT NULL DEFAULT 0,
        order_date INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_settlements_user ON settlements(user_id, transaction_date)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT ''
    )");

    // Mevcut DB'ler için migration: eski users tablosunda yeni kolonlar yoksa ekle
    $existing = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
    $cols = array_column($existing, 'name');
    $add = [
        'totp_secret' => "ALTER TABLE users ADD COLUMN totp_secret TEXT DEFAULT NULL",
        'totp_enabled' => "ALTER TABLE users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0",
        'is_demo' => "ALTER TABLE users ADD COLUMN is_demo INTEGER NOT NULL DEFAULT 0",
        'referral_code' => "ALTER TABLE users ADD COLUMN referral_code TEXT DEFAULT NULL",
        'referred_by' => "ALTER TABLE users ADD COLUMN referred_by INTEGER DEFAULT NULL",
    ];
    foreach ($add as $col => $sql) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec($sql);
        }
    }

    // Migration: products'ta eski kolon adlarını yeni adlara çevir (Melontik modeli)
    // packing -> service_fee (platform hizmet bedeli), advert -> stopaj
    $pcols = array_column($pdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $renames = [
        'packing' => 'service_fee',
        'advert' => 'stopaj',
    ];
    foreach ($renames as $old => $new) {
        if (in_array($old, $pcols, true) && !in_array($new, $pcols, true)) {
            $pdo->exec("ALTER TABLE products RENAME COLUMN $old TO $new");
        }
    }

    // Migration: yeni ürün kolonları (kdv, cargo_type, extra_fee)
    $padd = [
        'kdv' => "ALTER TABLE products ADD COLUMN kdv REAL NOT NULL DEFAULT 20",
        'cargo_type' => "ALTER TABLE products ADD COLUMN cargo_type TEXT NOT NULL DEFAULT 'seller'",
        'extra_fee' => "ALTER TABLE products ADD COLUMN extra_fee REAL NOT NULL DEFAULT 0",
        'barcode' => "ALTER TABLE products ADD COLUMN barcode TEXT NOT NULL DEFAULT ''",
        'en' => "ALTER TABLE products ADD COLUMN en REAL NOT NULL DEFAULT 0",
        'boy' => "ALTER TABLE products ADD COLUMN boy REAL NOT NULL DEFAULT 0",
        'yukseklik' => "ALTER TABLE products ADD COLUMN yukseklik REAL NOT NULL DEFAULT 0",
        'kesilen_desi' => "ALTER TABLE products ADD COLUMN kesilen_desi REAL NOT NULL DEFAULT 0",
        'quantity' => "ALTER TABLE products ADD COLUMN quantity INTEGER NOT NULL DEFAULT 0",
        'marka' => "ALTER TABLE products ADD COLUMN marka TEXT NOT NULL DEFAULT ''",
        'model_kodu' => "ALTER TABLE products ADD COLUMN model_kodu TEXT NOT NULL DEFAULT ''",
        'renk' => "ALTER TABLE products ADD COLUMN renk TEXT NOT NULL DEFAULT ''",
        'beden' => "ALTER TABLE products ADD COLUMN beden TEXT NOT NULL DEFAULT ''",
    ];
    $pcols2 = array_column($pdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach ($padd as $col => $sql) {
        if (!in_array($col, $pcols2, true)) {
            $pdo->exec($sql);
        }
    }

    // integrations: siparişlerin son otomatik senkron zamanı (auto-sync throttle)
    $icols = array_column($pdo->query("PRAGMA table_info(integrations)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('last_orders_sync', $icols, true)) {
        $pdo->exec("ALTER TABLE integrations ADD COLUMN last_orders_sync TEXT DEFAULT NULL");
    }

    migrate_profit_engine($pdo);
    migrate_tariffs($pdo);

    // Yönetici hesabı (yoksa oluştur; ilk girişte şifre değiştirmeye zorla)
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([ADMIN_EMAIL]);
    if (!$stmt->fetch()) {
        $pdo->prepare("INSERT INTO users (email, name, password_hash, plan, is_admin, must_change_password)
                       VALUES (?, 'Yönetici', ?, 'pro', 1, 1)")
            ->execute([ADMIN_EMAIL, password_hash(ADMIN_DEFAULT_PASSWORD, PASSWORD_DEFAULT)]);
    }

    // Varsayılan ayarlar (yoksa)
    $defaults = [
        'iban' => '',
        'account_name' => '',
        'pro_price' => (string) PRO_PRICE,
        'iyzico_api_key' => '',
        'iyzico_secret_key' => '',
        'iyzico_sandbox' => '1',
    ];
    $ins = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    foreach ($defaults as $k => $v) {
        $ins->execute([$k, $v]);
    }
}

/**
 * Kâr motoru şeması: kullanıcı ayarları, kargo tarifesi ve barem, sipariş bazlı kâr kaydı
 * (tahmini → kesin), kargo faturası kalemleri ve ekstre tekilleştirme.
 */
function migrate_profit_engine(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_settings (
        user_id INTEGER PRIMARY KEY,
        zero_negative_vat INTEGER NOT NULL DEFAULT 1,   -- negatif net KDV 0 alınsın
        apply_stopaj INTEGER NOT NULL DEFAULT 1,        -- %1 stopaj düşülsün
        stopaj_rate REAL NOT NULL DEFAULT 1,
        fixed_cost_per_order REAL NOT NULL DEFAULT 0,   -- paketleme vb. sipariş başı sabit gider (₺, KDV dahil)
        apply_return_rate INTEGER NOT NULL DEFAULT 0,   -- tahminde varsayılan iade oranı kullanılsın
        default_return_rate REAL NOT NULL DEFAULT 0,    -- %
        default_carrier TEXT NOT NULL DEFAULT 'TEX',
        barem_enabled INTEGER NOT NULL DEFAULT 1,
        min_margin REAL NOT NULL DEFAULT 10,            -- uyarı eşiği (%)
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    // Desi aralığına göre kargo fiyatı (KDV hariç). user_id = 0 sistem varsayılanı.
    $pdo->exec("CREATE TABLE IF NOT EXISTS cargo_tariffs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL DEFAULT 0,
        carrier TEXT NOT NULL DEFAULT 'TEX',
        desi_from REAL NOT NULL,
        desi_to REAL NOT NULL,
        price_ex_vat REAL NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cargo_tariffs ON cargo_tariffs(user_id, carrier, desi_from)");

    // Barem: sepet tutarı aralığına göre sabit kargo (KDV hariç). Desi sınırı aşılırsa tarife geçerli.
    $pdo->exec("CREATE TABLE IF NOT EXISTS cargo_barem (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL DEFAULT 0,
        carrier TEXT NOT NULL DEFAULT 'TEX',
        min_price REAL NOT NULL,
        max_price REAL NOT NULL,
        max_desi REAL NOT NULL DEFAULT 10,
        price_ex_vat REAL NOT NULL
    )");

    // Kargo faturası kalemleri (Trendyol cargo-invoice items): gerçek kargo bedeli.
    $pdo->exec("CREATE TABLE IF NOT EXISTS cargo_invoice_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        marketplace TEXT NOT NULL DEFAULT 'trendyol',
        invoice_serial TEXT NOT NULL DEFAULT '',
        order_number TEXT NOT NULL DEFAULT '',
        parcel_id TEXT NOT NULL DEFAULT '',
        item_type TEXT NOT NULL DEFAULT '',            -- 'Gönderi Kargo Bedeli' | 'İade Kargo Bedeli' ...
        is_return INTEGER NOT NULL DEFAULT 0,
        amount REAL NOT NULL DEFAULT 0,                -- faturadaki tutar (KDV hariç)
        desi REAL NOT NULL DEFAULT 0,
        invoice_date INTEGER NOT NULL DEFAULT 0,
        UNIQUE(user_id, marketplace, invoice_serial, parcel_id, item_type)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cargo_items_order ON cargo_invoice_items(user_id, marketplace, order_number)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cargo_invoices_seen (
        user_id INTEGER NOT NULL,
        marketplace TEXT NOT NULL,
        invoice_serial TEXT NOT NULL,
        fetched_at TEXT NOT NULL DEFAULT (datetime('now')),
        PRIMARY KEY(user_id, marketplace, invoice_serial)
    )");

    // Sipariş (paket) bazlı kâr kaydı. Her kalemin kaynağı: tahmini | kesin.
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_profits (
        user_id INTEGER NOT NULL,
        marketplace TEXT NOT NULL,
        package_number TEXT NOT NULL,
        order_number TEXT NOT NULL DEFAULT '',
        order_date INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT '',
        item_count INTEGER NOT NULL DEFAULT 0,
        sale REAL NOT NULL DEFAULT 0,
        cost REAL NOT NULL DEFAULT 0,
        commission REAL NOT NULL DEFAULT 0,
        commission_src TEXT NOT NULL DEFAULT 'tahmini',
        cargo REAL NOT NULL DEFAULT 0,
        cargo_src TEXT NOT NULL DEFAULT 'tahmini',     -- tahmini | kesin | yok
        cargo_note TEXT NOT NULL DEFAULT '',           -- 'desi 4 · TEX tarifesi', 'barem', 'fatura' ...
        service_fee REAL NOT NULL DEFAULT 0,
        stopaj REAL NOT NULL DEFAULT 0,
        fixed_cost REAL NOT NULL DEFAULT 0,
        return_loss REAL NOT NULL DEFAULT 0,
        return_src TEXT NOT NULL DEFAULT 'tahmini',
        sale_vat REAL NOT NULL DEFAULT 0,
        cost_vat REAL NOT NULL DEFAULT 0,
        fee_vat REAL NOT NULL DEFAULT 0,
        net_vat REAL NOT NULL DEFAULT 0,
        profit REAL DEFAULT NULL,                      -- NULL: maliyet eksik, hesaplanamadı
        margin REAL DEFAULT NULL,
        missing_cost INTEGER NOT NULL DEFAULT 0,
        is_final INTEGER NOT NULL DEFAULT 0,           -- komisyon ve kargo kesinleşti
        is_cancelled INTEGER NOT NULL DEFAULT 0,
        computed_at INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY(user_id, marketplace, package_number)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_order_profits_date ON order_profits(user_id, marketplace, order_date)");

    // Ekstre tekilleştirme: Trendyol kayıt id'si
    $scols = array_column($pdo->query("PRAGMA table_info(settlements)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('ext_id', $scols, true)) {
        $pdo->exec("ALTER TABLE settlements ADD COLUMN ext_id TEXT DEFAULT NULL");
    }
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_settlements_ext ON settlements(user_id, marketplace, ext_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_settlements_pkg ON settlements(user_id, marketplace, shipment_package_id)");

    $pcols = array_column($pdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach ([
        'desi' => "ALTER TABLE products ADD COLUMN desi REAL NOT NULL DEFAULT 0",          // elle girilen / Excel'den
        'cost_kdv' => "ALTER TABLE products ADD COLUMN cost_kdv REAL DEFAULT NULL",         // alış KDV oranı (boş: satışla aynı)
        'cargo_override' => "ALTER TABLE products ADD COLUMN cargo_override INTEGER NOT NULL DEFAULT 0", // 1: kargo alanı tarifenin yerine geçer
    ] as $col => $sql) {
        if (!in_array($col, $pcols, true)) $pdo->exec($sql);
    }
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_products_barcode ON products(user_id, marketplace, barcode)");
}

function setting(string $key): string
{
    $stmt = db()->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$key]);
    $r = $stmt->fetch();
    return $r ? $r['value'] : '';
}

function set_setting(string $key, string $value): void
{
    db()->prepare("INSERT INTO settings (key, value) VALUES (?, ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value")
        ->execute([$key, $value]);
}

// Her istekte kurulumun hazır olduğundan emin ol
migrate();


/** Trendyol komisyon tarifesi aralıkları (son yüklenen Ürün Komisyon Tarifesi Excel'inden). */
function migrate_tariffs(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS tariff_bands (
        user_id INTEGER NOT NULL,
        marketplace TEXT NOT NULL,
        barcode TEXT NOT NULL,
        bands TEXT NOT NULL,          -- JSON: [{min, max, rates: {dönem: oran}}]
        updated_at INTEGER NOT NULL,
        PRIMARY KEY (user_id, marketplace, barcode)
    )");
}
