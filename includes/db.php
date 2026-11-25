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
    $orderCols=array_column($pdo->query("PRAGMA table_info(orders)")->fetchAll(PDO::FETCH_ASSOC),'name');
    foreach (['currency_code'=>'TEXT DEFAULT NULL','country_code'=>'TEXT DEFAULT NULL','last_modified_ms'=>'INTEGER NOT NULL DEFAULT 0'] as $col=>$definition) {
        if(!in_array($col,$orderCols,true)) $pdo->exec("ALTER TABLE orders ADD COLUMN $col $definition");
    }

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
    // A connected seller/environment owns imported data. Disconnect does not erase ownership.
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_bindings (
        user_id INTEGER NOT NULL, marketplace TEXT NOT NULL, seller_id TEXT NOT NULL,
        stage INTEGER NOT NULL, PRIMARY KEY(user_id,marketplace)
    )");
    $pdo->exec("INSERT OR IGNORE INTO integration_bindings(user_id,marketplace,seller_id,stage)
        SELECT user_id,marketplace,seller_id,stage FROM integrations");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sync_status (
        user_id INTEGER NOT NULL, marketplace TEXT NOT NULL, job TEXT NOT NULL,
        last_attempt INTEGER NOT NULL, last_success INTEGER,
        state TEXT NOT NULL, PRIMARY KEY(user_id,marketplace,job)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS trendyol_claim_items (
        user_id INTEGER NOT NULL,claim_id TEXT NOT NULL,item_id TEXT NOT NULL,order_number TEXT NOT NULL,
        outbound_package TEXT NOT NULL,return_package TEXT NOT NULL,line_id TEXT NOT NULL,barcode TEXT NOT NULL,
        product_name TEXT NOT NULL,status TEXT NOT NULL,reason TEXT NOT NULL,platform_reason TEXT NOT NULL,
        auto_accepted INTEGER,seller_accepted INTEGER,created_ms INTEGER NOT NULL,modified_ms INTEGER NOT NULL,
        PRIMARY KEY(user_id,item_id)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_claim_order ON trendyol_claim_items(user_id,order_number,outbound_package)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_claim_modified ON trendyol_claim_items(user_id,modified_ms,status)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sync_ranges (
        user_id INTEGER NOT NULL, marketplace TEXT NOT NULL, job TEXT NOT NULL,
        range_start INTEGER NOT NULL, range_end INTEGER NOT NULL,
        PRIMARY KEY(user_id,marketplace,job,range_start), CHECK(range_end>range_start)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts (
        scope TEXT PRIMARY KEY, window_start INTEGER NOT NULL, attempts INTEGER NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS checkout_intents (
        token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL, basket_id TEXT NOT NULL UNIQUE,
        conversation_id TEXT NOT NULL, amount_minor INTEGER NOT NULL, sandbox INTEGER NOT NULL,
        merchant_fingerprint TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending',
        provider_payment_key TEXT UNIQUE, created_at INTEGER NOT NULL, approved_at INTEGER
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS profit_targets (
        user_id INTEGER NOT NULL,scope TEXT NOT NULL,scope_key TEXT NOT NULL,
        kind TEXT NOT NULL,value REAL NOT NULL,updated_at INTEGER NOT NULL,
        PRIMARY KEY(user_id,scope,scope_key)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS analysis_jobs (
        user_id INTEGER NOT NULL, kind TEXT NOT NULL, module TEXT NOT NULL,
        headers_json TEXT NOT NULL, stats_json TEXT NOT NULL, created_at INTEGER NOT NULL,
        PRIMARY KEY(user_id,kind,module)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS analysis_rows (
        user_id INTEGER NOT NULL, kind TEXT NOT NULL,module TEXT NOT NULL,row_no INTEGER NOT NULL,
        payload_json TEXT NOT NULL,best_profit REAL,
        PRIMARY KEY(user_id,kind,module,row_no)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_analysis_profit ON analysis_rows(user_id,kind,module,best_profit)");
    $cols=array_column($pdo->query("PRAGMA table_info(analysis_rows)")->fetchAll(PDO::FETCH_ASSOC),'name');
    if(!in_array('meets_target',$cols,true))$pdo->exec("ALTER TABLE analysis_rows ADD COLUMN meets_target INTEGER DEFAULT NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_analysis_target ON analysis_rows(user_id,kind,module,meets_target)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS trendyol_buybox (
        user_id INTEGER NOT NULL, seller_id TEXT NOT NULL, barcode TEXT NOT NULL,
        buybox_rank INTEGER NOT NULL, buybox_price REAL NOT NULL, multiple_sellers INTEGER NOT NULL,
        second_price REAL, third_price REAL, checked_at INTEGER NOT NULL,
        PRIMARY KEY(user_id,seller_id,barcode)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS trendyol_payouts (
        user_id INTEGER NOT NULL, seller_id TEXT NOT NULL, payment_order_id TEXT NOT NULL,
        payout_date INTEGER NOT NULL, amount REAL NOT NULL, raw_json TEXT NOT NULL,
        summary_json TEXT DEFAULT NULL, checked_at INTEGER DEFAULT NULL,
        PRIMARY KEY(user_id,seller_id,payment_order_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS payout_receipts (
        user_id INTEGER NOT NULL,seller_id TEXT NOT NULL,payment_order_id TEXT NOT NULL,
        reference TEXT NOT NULL,received_day TEXT NOT NULL,amount_units INTEGER NOT NULL,created_at INTEGER NOT NULL,
        PRIMARY KEY(user_id,seller_id,reference)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_payout_receipts ON payout_receipts(user_id,seller_id,payment_order_id)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS product_cost_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,barcode TEXT NOT NULL,
        effective_at INTEGER NOT NULL,cost REAL NOT NULL,cost_vat REAL NOT NULL,source TEXT NOT NULL,recorded_at INTEGER NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cost_history_lookup ON product_cost_history(user_id,barcode,effective_at,id)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_cost_snapshots (
        user_id INTEGER NOT NULL, marketplace TEXT NOT NULL, package_number TEXT NOT NULL,
        line_key TEXT NOT NULL, barcode TEXT NOT NULL, cost REAL NOT NULL, cost_vat REAL NOT NULL,
        observed_at INTEGER NOT NULL, PRIMARY KEY(user_id,marketplace,package_number,line_key)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS trendyol_price_requests (
        user_id INTEGER NOT NULL, batch_id TEXT NOT NULL, seller_id TEXT NOT NULL,
        barcode TEXT NOT NULL, sale_price REAL NOT NULL, list_price REAL NOT NULL,
        status TEXT NOT NULL DEFAULT 'PENDING', reason TEXT NOT NULL DEFAULT '',
        created_at INTEGER NOT NULL, PRIMARY KEY(user_id,batch_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS trendyol_financial_documents (
        user_id INTEGER NOT NULL, external_id TEXT NOT NULL, query_type TEXT NOT NULL,
        transaction_type TEXT NOT NULL, payment_order_id TEXT, transaction_date INTEGER NOT NULL,
        raw_json TEXT NOT NULL, PRIMARY KEY(user_id, external_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS trendyol_cargo_items (
        user_id INTEGER NOT NULL, invoice_serial TEXT NOT NULL, row_number INTEGER NOT NULL,
        parcel_id TEXT NOT NULL, order_number TEXT NOT NULL, shipment_type TEXT NOT NULL,
        amount REAL NOT NULL, desi REAL NOT NULL,
        PRIMARY KEY(user_id, invoice_serial, row_number)
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cargo_order ON trendyol_cargo_items(user_id, order_number)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cargo_parcel ON trendyol_cargo_items(user_id,invoice_serial,parcel_id,shipment_type)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cargo_expectations (
        user_id INTEGER NOT NULL,invoice_serial TEXT NOT NULL,parcel_id TEXT NOT NULL,shipment_type TEXT NOT NULL,
        expected_desi REAL NOT NULL,expected_amount REAL,updated_at INTEGER NOT NULL,
        PRIMARY KEY(user_id,invoice_serial,parcel_id,shipment_type)
    )");
    // Legacy rows have no authoritative ID; keep them but do not count as verified finance.
    $settlementCols = array_column($pdo->query("PRAGMA table_info(settlements)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['external_id', 'query_type', 'payment_order_id','raw_json','finance_scope'] as $col) {
        if (!in_array($col, $settlementCols, true)) {
            $pdo->exec("ALTER TABLE settlements ADD COLUMN $col TEXT DEFAULT NULL");
        }
    }
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_settlements_external
        ON settlements(user_id, marketplace, external_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_settlements_scope ON settlements(user_id,marketplace,finance_scope,query_type,transaction_date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_settlements_package ON settlements(user_id,marketplace,shipment_package_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_settlements_order ON settlements(user_id,marketplace,order_number)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orders_number ON orders(user_id,marketplace,order_number)");

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
        'cost_vat' => "ALTER TABLE products ADD COLUMN cost_vat REAL DEFAULT NULL",
        'stopaj_mode' => "ALTER TABLE products ADD COLUMN stopaj_mode TEXT NOT NULL DEFAULT 'legacy'",
        'stopaj_rate' => "ALTER TABLE products ADD COLUMN stopaj_rate REAL NOT NULL DEFAULT 1",
        'allow_negative_vat' => "ALTER TABLE products ADD COLUMN allow_negative_vat INTEGER NOT NULL DEFAULT 0",
        'cargo_type' => "ALTER TABLE products ADD COLUMN cargo_type TEXT NOT NULL DEFAULT 'seller'",
        'extra_fee' => "ALTER TABLE products ADD COLUMN extra_fee REAL NOT NULL DEFAULT 0",
        'barcode' => "ALTER TABLE products ADD COLUMN barcode TEXT NOT NULL DEFAULT ''",
        'en' => "ALTER TABLE products ADD COLUMN en REAL NOT NULL DEFAULT 0",
        'boy' => "ALTER TABLE products ADD COLUMN boy REAL NOT NULL DEFAULT 0",
        'yukseklik' => "ALTER TABLE products ADD COLUMN yukseklik REAL NOT NULL DEFAULT 0",
        'kesilen_desi' => "ALTER TABLE products ADD COLUMN kesilen_desi REAL NOT NULL DEFAULT 0",
        'quantity' => "ALTER TABLE products ADD COLUMN quantity INTEGER NOT NULL DEFAULT 0",
        'stock_observed_at' => "ALTER TABLE products ADD COLUMN stock_observed_at INTEGER DEFAULT NULL",
        'sale_price_observed_at' => "ALTER TABLE products ADD COLUMN sale_price_observed_at INTEGER DEFAULT NULL",
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

    // Yönetici hesabı (yoksa oluştur; ilk girişte şifre değiştirmeye zorla)
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([ADMIN_EMAIL]);
    if (!$stmt->fetch()) {
        $pdo->prepare("INSERT INTO users (email, name, password_hash, plan, is_admin, must_change_password)
                       VALUES (?, 'Yönetici', ?, 'pro', 1, 1)")
            ->execute([ADMIN_EMAIL, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
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
