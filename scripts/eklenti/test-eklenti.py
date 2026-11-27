"""
Chrome eklentisi uçtan uca testi: eklentiyi Chromium'a yükler, Trendyol tarife sayfasını gerçek DOM yapısındaki
test kopyasıyla taklit eder, yerel Kâr&Fiyat sunucusuna bağlanıp rozetleri ve açılır pencere hesabını doğrular.
Çalıştır: python3 scripts/eklenti/test-eklenti.py   (playwright + chromium gerekli)
"""
import asyncio, json, os, shutil, subprocess, sys, tempfile, time
from playwright.async_api import async_playwright

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
HERE = os.path.dirname(os.path.abspath(__file__))
PORT = 8197
tmp = tempfile.mkdtemp(prefix='kf-eklenti-')
db = os.path.join(tmp, 'app.sqlite')
env = dict(os.environ, KARFIYAT_DB=db)

seed = r'''
require "%s/includes/functions.php"; require "%s/includes/api_tokens.php";
$pdo = db();
$pdo->prepare("INSERT INTO users (email,name,password_hash,plan,plan_expires) VALUES ('e@e','Test',?,'pro','2030-01-01')")->execute([password_hash('x', PASSWORD_DEFAULT)]);
$uid = (int)$pdo->lastInsertId();
$ins = $pdo->prepare("INSERT INTO products (user_id,name,barcode,marketplace,category,rate,cost,price,cargo,kdv,desi,en,boy,yukseklik) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$ins->execute([$uid,'8 Adet Bambu Yastık Alezi','bamboyastik8li','trendyol','Yastık Alezi',13.1,300,799.05,0,10,3,0,0,0]);
$ins->execute([$uid,'2 Adet Bambu Yastık Alezi','yastikalezi50x70','trendyol','Yastık Alezi',21,90,299.9,0,10,1,0,0,0]);
$t = $pdo->prepare("INSERT INTO cargo_tariffs (user_id,carrier,desi_from,desi_to,price_ex_vat) VALUES (?,?,?,?,?)");
foreach ([[1,1,70],[2,2,80],[3,3,95],[4,10,120]] as [$f,$to,$p]) $t->execute([$uid,'TEX',$f,$to,$p]);
$pdo->prepare("INSERT INTO cargo_barem (user_id,carrier,min_price,max_price,max_desi,price_ex_vat) VALUES (?,'TEX',0,300,10,40)")->execute([$uid]);
echo api_token_create($uid);
''' % (ROOT, ROOT)
token = subprocess.check_output(['php', '-r', seed], env=env, text=True).strip()
srv = subprocess.Popen(['php', '-S', f'127.0.0.1:{PORT}', '-t', os.path.join(ROOT, 'public_html')], env=env,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1)

ext = os.path.join(tmp, 'ext')
shutil.copytree(os.path.join(ROOT, 'chrome-eklenti'), ext)
m = json.load(open(os.path.join(ext, 'manifest.json')))
m['host_permissions'].append('http://127.0.0.1/*')     # testte izin penceresi yok
json.dump(m, open(os.path.join(ext, 'manifest.json'), 'w'), ensure_ascii=False)

fail = 0
def ok(c, msg):
    global fail
    print(('  ✓ ' if c else '  ✗ ') + msg)
    if not c: fail += 1

async def main():
    async with async_playwright() as p:
        ctx = await p.chromium.launch_persistent_context(os.path.join(tmp, 'profile'), headless=True, channel='chromium',
            args=[f'--disable-extensions-except={ext}', f'--load-extension={ext}'])
        sw = ctx.service_workers[0] if ctx.service_workers else await ctx.wait_for_event('serviceworker')
        await sw.evaluate("t => chrome.storage.sync.set({url: 'http://127.0.0.1:%d/api-eklenti.php', token: t})" % PORT, token)
        page = await ctx.new_page()
        html = open(os.path.join(HERE, 'mock-trendyol.html'), encoding='utf-8').read()
        await page.route('https://partner.trendyol.com/**', lambda r: r.fulfill(body=html, content_type='text/html; charset=utf-8'))
        await page.goto('https://partner.trendyol.com/pricing/rate-card')
        await page.wait_for_selector('.kf-badge.kf-pos', timeout=10000)
        await page.wait_for_timeout(800)
        b = await page.evaluate("""[...document.querySelectorAll('tr.content-row')].map(tr => [...tr.children].map(td => {
            const k = td.querySelector(':scope > .kf-badge'); return k ? k.className + ' :: ' + k.innerText.replace(/\\n/g, ' / ') : ''; }))""")
        ok('246,88' in b[0][0], 'bugünkü kâr (geçerli aralık komisyonuyla): ' + b[0][0])
        ok('253,20' in b[0][2] and 'kf-best' in b[0][2], 'en kârlı aralık işaretli: ' + b[0][2])
        ok('196,80' in b[0][1] and '%21' in b[0][1], '1. aralık %21 komisyonla: ' + b[0][1])
        ok(b[0][5] == '', 'Fiyat Güncelle hücresine rozet eklenmedi')
        ok('kayıtlı değil' in b[1][0], 'kayıtsız barkod bildirildi')
        ok('Net' in b[2][1], 'flaş satırı barkodsuz, ürün adıyla eşleşti: ' + b[2][1])
        ok(b[2][2] == '', 'flaşta "Maks. … girebilirsin" hücresi atlandı')
        # Sekme değişimi gibi DOM güncellemesi: rozet yenilenmeli
        await page.evaluate("document.querySelectorAll('tr.content-row')[0].children[2].querySelectorAll('div')[3].textContent = '%17,6'")
        await page.wait_for_timeout(1500)
        t = await page.evaluate("document.querySelectorAll('tr.content-row')[0].children[2].querySelector('.kf-badge').innerText")
        ok('223,23' in t, 'komisyon değişince rozet güncellendi (%17,6 → 223,23)')
        await page.screenshot(path=os.path.join(tmp, 'panel.png'), full_page=True)

        ext_id = sw.url.split('/')[2]
        pp = await ctx.new_page()
        await pp.goto(f'chrome-extension://{ext_id}/popup.html')
        await pp.wait_for_timeout(600)
        for k, v in {'cost': '1204', 'price': '2585,54', 'rate': '11,349', 'kdv': '10', 'cargo': '128,64'}.items():
            await pp.fill('#' + k, v)
        await pp.click('#calc'); await pp.wait_for_timeout(800)
        ok('Bağlı: Test' in await pp.inner_text('#st'), 'açılır pencere sunucuya bağlı')
        ok('869,72' in await pp.inner_text('#res'), 'Melontik referans siparişi açılır pencerede 869,72 ₺')

        await sw.evaluate("() => chrome.storage.sync.set({token: 'kf_yanlis'})")
        p2 = await ctx.new_page()
        await p2.route('https://partner.trendyol.com/**', lambda r: r.fulfill(body=html, content_type='text/html; charset=utf-8'))
        await p2.goto('https://partner.trendyol.com/pricing/rate-card')
        await p2.wait_for_timeout(2500)
        ok('geçersiz' in (await p2.inner_text('body')), 'yanlış anahtarda panelde anlaşılır uyarı')
        await ctx.close()

try:
    asyncio.run(main())
finally:
    srv.terminate()
print(f"\n{'TÜMÜ GEÇTİ' if not fail else str(fail) + ' HATA'} · ekran görüntüsü: {tmp}/panel.png")
sys.exit(1 if fail else 0)
