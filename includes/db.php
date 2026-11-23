<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        @chmod(DB_PATH,0600);
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS checkout_sessions (
        token TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL REFERENCES users(id),
        basket_id TEXT NOT NULL UNIQUE,
        amount TEXT NOT NULL,
        currency TEXT NOT NULL DEFAULT 'TRY',
        payment_id TEXT UNIQUE,
        status TEXT NOT NULL DEFAULT 'pending',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
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
        'auth_version' => "ALTER TABLE users ADD COLUMN auth_version INTEGER NOT NULL DEFAULT 0",
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
        'stopaj_mode' => "ALTER TABLE products ADD COLUMN stopaj_mode TEXT NOT NULL DEFAULT 'manual'",
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

    foreach ([
        'revision' => 'INTEGER NOT NULL DEFAULT 0',
        'last_settlements_sync' => 'INTEGER DEFAULT NULL',
        'last_service_fees_sync' => 'INTEGER DEFAULT NULL',
        'orders_coverage_from' => 'INTEGER DEFAULT NULL',
        'last_sync_error' => 'TEXT DEFAULT NULL',
        'last_sync_error_scope' => 'TEXT DEFAULT NULL',
    ] as $column => $definition) {
        if (!in_array($column, $icols, true)) $pdo->exec("ALTER TABLE integrations ADD COLUMN $column $definition");
    }
    $scols = array_column($pdo->query('PRAGMA table_info(settlements)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('provider_transaction_type', $scols, true)) $pdo->exec("ALTER TABLE settlements ADD COLUMN provider_transaction_type TEXT NOT NULL DEFAULT ''");
    if (!in_array('provider_id', $scols, true)) $pdo->exec('ALTER TABLE settlements ADD COLUMN provider_id TEXT DEFAULT NULL');
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_settlements_provider ON settlements(user_id, marketplace, provider_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_settlements_barcode ON settlements(user_id, marketplace, barcode, transaction_type, transaction_date DESC)");
    // Eski platform_service_fees şeması (Tur 7, seller_id/stage yok) → kaynak mağaza/ortam
    // güvenle belirlenemediği için yeni kapsamlı sorguya dahil edilmez; ancak kayıtlar veri
    // kaybı olmadan arşivlenir (platform_service_fees_legacy).
    $psfcols = array_column($pdo->query('PRAGMA table_info(platform_service_fees)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (count($psfcols) > 0 && !in_array('seller_id', $psfcols, true)) {
        $pdo->exec('DROP INDEX IF EXISTS idx_psf_user_date');
        $pdo->exec('ALTER TABLE platform_service_fees RENAME TO platform_service_fees_legacy');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_service_fees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        marketplace TEXT NOT NULL DEFAULT 'trendyol',
        seller_id TEXT NOT NULL DEFAULT '',
        stage INTEGER NOT NULL DEFAULT 0,
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
        UNIQUE(user_id, marketplace, seller_id, stage, provider_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_psf_user_date ON platform_service_fees(user_id, marketplace, seller_id, stage, transaction_date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_products_market_barcode ON products(user_id, marketplace, barcode)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settlement_legacy_archive (
        source_id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, marketplace TEXT NOT NULL,
        payload TEXT NOT NULL, archived_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sync_locks (
        user_id INTEGER NOT NULL, marketplace TEXT NOT NULL, token TEXT NOT NULL,
        expires_at INTEGER NOT NULL, PRIMARY KEY(user_id, marketplace)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS price_updates (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
        marketplace TEXT NOT NULL, integration_id INTEGER NOT NULL, integration_revision INTEGER NOT NULL,
        barcode TEXT NOT NULL, batch_id TEXT NOT NULL, request_hash TEXT NOT NULL,
        sale_price REAL NOT NULL, list_price REAL NOT NULL, status TEXT NOT NULL DEFAULT 'pending',
        failure_reason TEXT DEFAULT NULL, created_at INTEGER NOT NULL, checked_at INTEGER DEFAULT NULL,
        UNIQUE(user_id,marketplace,batch_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_price_updates_user ON price_updates(user_id,marketplace,status)");
    $pcols3=array_column($pdo->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC),'name');
    if (!in_array('list_price',$pcols3,true)) $pdo->exec('ALTER TABLE products ADD COLUMN list_price REAL DEFAULT NULL');
    $checkoutColumns=array_column($pdo->query('PRAGMA table_info(checkout_sessions)')->fetchAll(),'name');
    foreach(['provider_context'=>'TEXT DEFAULT NULL','checked_at'=>'INTEGER DEFAULT NULL','attempts'=>'INTEGER NOT NULL DEFAULT 0','error_code'=>"TEXT NOT NULL DEFAULT ''"] as $column=>$type) {
        if(!in_array($column,$checkoutColumns,true))$pdo->exec("ALTER TABLE checkout_sessions ADD COLUMN $column $type");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS checkout_events (id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT NOT NULL REFERENCES checkout_sessions(token), code TEXT NOT NULL, created_at INTEGER NOT NULL)");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_checkout_events_token ON checkout_events(token,created_at)');
    $orderColumns=array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(),'name');
    if(!in_array('profit_inputs',$orderColumns,true))$pdo->exec('ALTER TABLE orders ADD COLUMN profit_inputs TEXT DEFAULT NULL');

    // Eski sürümün bilinen kurulum şifresiyle kalan hesabını devre dışı bırak.
    // Şifresi değiştirilmiş yöneticilere ve normal kullanıcılara dokunma.
    if (!$pdo->query("SELECT 1 FROM settings WHERE key = 'bootstrap_cleanup_v1'")->fetchColumn()) {
        $legacy = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = ? AND is_admin = 1");
        $legacy->execute(['admin@karfiyat.local']);
        $bootstrap = $legacy->fetch();
        if ($bootstrap && password_verify('1234', $bootstrap['password_hash'])) {
            $pdo->prepare("UPDATE users SET password_hash = '!disabled-bootstrap', is_admin = 0, auth_version = auth_version + 1 WHERE id = ? AND password_hash = ?")
                ->execute([$bootstrap['id'], $bootstrap['password_hash']]);
        }
        $pdo->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('bootstrap_cleanup_v1', '1')");
    }

    // Varsayılan ayarlar (yoksa)
    $defaults = [
        'iban' => '',
        'account_name' => '',
        'pro_price' => (string) PRO_PRICE,
        'iyzico_api_key' => '',
        'iyzico_secret_key' => '',
        'iyzico_sandbox' => '1',
        'app_url' => '',
    ];
    $ins = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    foreach ($defaults as $k => $v) {
        $ins->execute([$k, $v]);
    }
}

function setting(string $key): string
{
    $stmt = db()->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$key]);
    $r = $stmt->fetch();
    $value=$r ? $r['value'] : '';
    if(in_array($key,['iyzico_api_key','iyzico_secret_key'],true)&&str_starts_with($value,'secret:'))return decrypt_string(substr($value,7));
    return $value;
}

function set_setting(string $key, string $value): void
{
    if(in_array($key,['iyzico_api_key','iyzico_secret_key'],true)&&$value!=='')$value='secret:'.encrypt_string($value);
    db()->prepare("INSERT INTO settings (key, value) VALUES (?, ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value")
        ->execute([$key, $value]);
}

// Her istekte kurulumun hazır olduğundan emin ol
migrate();
