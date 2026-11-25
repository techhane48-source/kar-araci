// Kâr&Fiyat — Pazar Zekası tarayıcı servisi (rakip fiyat takibi).
//
// kar-araci'nin trackers tablosunu okur, her takibi headless Chrome ile ziyaret eder,
// fiyat/ürün sayısını çıkarır ve sonucu trackers + tracker_snapshots tablolarına yazar.
// PHP uygulamasından (pazar-zekasi.php) bağımsız, ayrı bir süreç olarak çalışır.
//
// Kullanım:
//   node crawl.js            → sürekli döngü (INTERVAL_MS, varsayılan 15 dk)
//   node crawl.js --once     → tek tur çalışıp çık (Windows Görev Zamanlayıcı / cron için)
//
// Ortam değişkenleri (hepsi opsiyonel):
//   KARFIYAT_DB   → app.sqlite yolu (varsayılan ../data/app.sqlite)
//   CHROME_PATH   → Chrome/Edge yolu (varsayılan Chrome, yoksa Edge)
//   INTERVAL_MS   → tur aralığı ms (varsayılan 900000)
//   HEADLESS=0    → görünür tarayıcı (debug)

'use strict';

const path = require('path');
const fs = require('fs');
const puppeteer = require('puppeteer-core');
const { DatabaseSync } = require('node:sqlite');

const ROOT = __dirname;
const DB_PATH = process.env.KARFIYAT_DB || path.join(ROOT, '..', 'data', 'app.sqlite');
const INTERVAL_MS = parseInt(process.env.INTERVAL_MS || '900000', 10);
const HEADLESS = process.env.HEADLESS !== '0';
const ONCE = process.argv.includes('--once');

function findChrome() {
  const candidates = [
    process.env.CHROME_PATH,
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  ];
  for (const c of candidates) {
    if (c && fs.existsSync(c)) return c;
  }
  return null;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function openDb() {
  const db = new DatabaseSync(DB_PATH);
  db.exec('PRAGMA journal_mode=WAL;');
  db.exec('PRAGMA busy_timeout=5000;');
  return db;
}

// Sayfadan fiyat + ürün sayısı + başlık çıkarır.
async function extract(page, trackerType) {
  return page.evaluate((type) => {
    const toNum = (s) => {
      if (s == null) return 0;
      const m = String(s).replace(/\s/g, '').replace(/[^\d.,]/g, '');
      if (!m) return 0;
      return parseFloat(m.replace(',', '.'));
    };

    // 1) JSON-LD Product/Offer/ItemList fiyatı (en güvenilir)
    const scripts = document.querySelectorAll('script[type="application/ld+json"]');
    for (const s of scripts) {
      try {
        const j = JSON.parse(s.textContent);
        const arr = j['@graph'] || (j['@type'] ? [j] : []);
        for (const node of arr) {
          if (node['@type'] === 'Product' || node['@type'] === 'ProductModel') {
            const offers = node.offers || {};
            const price = offers.price || offers.lowPrice || offers.highPrice || node.price;
            if (price != null && toNum(price) > 0) {
              return { price: toNum(price), productCount: 1, title: node.name || document.title || '' };
            }
          }
          if (node['@type'] === 'ItemList' && Array.isArray(node.itemListElement)) {
            const ps = node.itemListElement
              .map((i) => (i && i.item && i.item.offers) ? (i.item.offers.price || i.item.offers.lowPrice) : null)
              .filter((p) => p != null && toNum(p) >= 5)
              .map(toNum);
            if (ps.length) {
              return { price: Math.min(...ps), productCount: ps.length, title: document.title || '' };
            }
          }
        }
      } catch (_) {}
    }

    // 2) DOM'dan fiyat listesi (Trendyol gerçek fiyat sınıfları). "0,01 TL" sepet-promo gürültüsü <1 TL olduğundan elenir.
    const priceEls = document.querySelectorAll('.sale-price, .discounted-price, .prc-box-dscntd, [data-price]');
    const prices = [];
    priceEls.forEach((el) => {
      const t = (el.getAttribute('data-price') || el.textContent || '').replace(/\s/g, '');
      const m = t.match(/\d+([.,]\d+)?/);
      if (m) {
        const v = parseFloat(m[0].replace(',', '.'));
        if (v >= 1 && v < 10000000) prices.push(v);
      }
    });

    if (prices.length > 0) {
      if (type === 'product') {
        return { price: prices[0], productCount: 1, title: document.title || '' };
      }
      const sorted = [...prices].sort((a, b) => a - b);
      const median = sorted[Math.floor(sorted.length / 2)];
      return { price: median, productCount: prices.length, title: document.title || '' };
    }

    return { price: 0, productCount: 0, title: document.title || '' };
  }, trackerType);
}

function allowedTrackerUrl(raw) {
  try { const u=new URL(raw);return u.protocol==='https:'&&['www.trendyol.com','trendyol.com'].includes(u.hostname)&&!u.username&&!u.password&&!u.port; } catch {return false;}
}
async function crawlTracker(db, browser, tracker) {
  if(!allowedTrackerUrl(tracker.url)){console.log('[skip] unsupported URL');return;}
  const page = await browser.newPage();
  await page.setRequestInterception(true);
  page.on('request',request=>{
    if(allowedTrackerUrl(request.url()))request.continue().catch(()=>{});
    else request.abort().catch(()=>{});
  });
  await page.setUserAgent(
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
  );
  await page.setViewport({ width: 1366, height: 900 });

  try {
    await page.goto(tracker.url, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await sleep(2500); // JS render için kısa bekle (networkidle2 bot korumalı sayfalarda takılabilir)

    // Bot meydan okuması yakalandı mı?
    const title = await page.title();
    const challenge = /captcha|doğrulama|robot|challenge|access denied/i.test(title + ' ' + (await page.content().then((h) => h.slice(0, 5000))));

    if (challenge) {
      console.log(`  [skip] ${tracker.name}: bot koruması (captcha/403)`);
      return;
    }

    const data = await extract(page, tracker.type);
    if (data.price > 0) {
      const upd = db.prepare(
        "UPDATE trackers SET last_price = ?, last_checked = datetime('now') WHERE id = ?"
      );
      upd.run(data.price, tracker.id);

      const ins = db.prepare(
        "INSERT INTO tracker_snapshots (tracker_id, price, product_count, title, checked_at) VALUES (?, ?, ?, ?, datetime('now'))"
      );
      ins.run(tracker.id, data.price, data.productCount, data.title);

      console.log(`  [ok] ${tracker.name}: ₺${data.price.toFixed(2)} (${data.productCount} ürün)`);
    } else {
      console.log(`  [empty] ${tracker.name}: fiyat bulunamadı (yapı değişmiş olabilir)`);
    }
  } catch (e) {
    console.log(`  [hata] ${tracker.name}: ${e.message}`);
  } finally {
    await page.close();
  }
}

async function crawlAll(db) {
  const trackers = db.prepare('SELECT * FROM trackers').all();
  console.log(`[crawler] ${trackers.length} takip, ${new Date().toISOString()}`);
  if (!trackers.length) return;

  const chrome = findChrome();
  if (!chrome) {
    console.error('[crawler] Chrome/Edge bulunamadı. CHROME_PATH ile yol verin.');
    return;
  }

  let browser;
  try {
    browser = await puppeteer.launch({
      executablePath: chrome,
      headless: HEADLESS ? 'new' : false,
      args: ['--disable-gpu', '--disable-dev-shm-usage', '--lang=tr-TR'],
    });
  } catch (e) {
    console.error('[crawler] tarayıcı başlatılamadı:', e.message);
    return;
  }

  try {
    for (const t of trackers) {
      await crawlTracker(db, browser, t);
      await sleep(2000); // rate-limit için tur arası
    }
  } finally {
    await browser.close();
  }
}

async function main() {
  const db = openDb();
  console.log(`[crawler] DB: ${DB_PATH}`);
  console.log(`[crawler] Chrome: ${findChrome() || '(bulunamadı)'}`);

  // Tablo yoksa oluştur (PHP migrate çalışmadıysa diye güvenlik)
  db.exec(`CREATE TABLE IF NOT EXISTS trackers (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
    type TEXT NOT NULL DEFAULT 'store', name TEXT NOT NULL DEFAULT '',
    url TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '',
    last_price REAL DEFAULT NULL, last_checked TEXT DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')))`);
  db.exec(`CREATE TABLE IF NOT EXISTS tracker_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tracker_id INTEGER NOT NULL,
    price REAL NOT NULL DEFAULT 0, product_count INTEGER NOT NULL DEFAULT 0,
    title TEXT NOT NULL DEFAULT '', checked_at TEXT NOT NULL DEFAULT (datetime('now')))`);

  do {
    await crawlAll(db);
    if (ONCE) break;
    console.log(`[crawler] ${Math.round(INTERVAL_MS / 1000)}s bekleniyor...`);
    await sleep(INTERVAL_MS);
  } while (true);

  db.close();
}

main().catch((e) => {
  console.error('[crawler] ölümcül hata:', e);
  process.exit(1);
});
