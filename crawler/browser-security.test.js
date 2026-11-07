'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const https = require('node:https');
const net = require('node:net');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { createPinnedProxy } = require('./pinned-proxy');
const { guardPage } = require('./tracker-policy');

test('Chrome izinli sayfayı açar; doğrudan IP, WebSocket ve redirect engellenir', {
  skip: !process.env.CHROME_PATH, timeout: 30000,
}, async () => {
  const puppeteer = require('puppeteer-core');
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'kf-browser-'));
  let fixture, proxy, browser;
  try {
    execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
      '-keyout', path.join(temp, 'key.pem'), '-out', path.join(temp, 'cert.pem'), '-subj', '/CN=www.trendyol.com'], { stdio: 'ignore' });
    let hits = 0;
    fixture = https.createServer({key:fs.readFileSync(path.join(temp, 'key.pem')), cert:fs.readFileSync(path.join(temp, 'cert.pem'))}, (req, res) => {
      hits++;
      if (req.url === '/redirect') { res.writeHead(302, {Location:'https://127.0.0.1/private'}); res.end(); }
      else { res.writeHead(200, {'Content-Type':'text/html; charset=utf-8'}); res.end('<title>Yerel test ürünü</title><script type="application/ld+json">{"@type":"Product","offers":{"price":"200.00"}}</script>'); }
    });
    await new Promise((resolve) => fixture.listen(0, '127.0.0.1', resolve));
    const pins = new Map([['www.trendyol.com', '93.184.216.34']]);
    let connects = 0;
    proxy = await createPinnedProxy(pins, (options) => {
      connects++;
      assert.equal(options.host, '93.184.216.34');
      return net.connect({host:'127.0.0.1', port:fixture.address().port});
    });
    browser = await puppeteer.launch({
      executablePath:process.env.CHROME_PATH, headless:true, ignoreHTTPSErrors:true,
      // Yalnızca yerel test fixture'ı için isteğe bağlıdır; üretim crawler'ında yoktur.
      args:[`--proxy-server=${proxy.url}`, '--proxy-bypass-list=<-loopback>', '--disable-quic',
        ...(process.env.KARFIYAT_TEST_NO_SANDBOX === '1' ? ['--no-sandbox'] : [])],
    });
    const page = await browser.newPage();
    await guardPage(page, pins);
    await page.goto('https://www.trendyol.com/fixture');
    assert.equal(await page.title(), 'Yerel test ürünü');
    // İstek yakalama kapalıyken bile proxy IP'ye giden bağlantıyı durdurmalı.
    const unguarded = await browser.newPage();
    const before = hits;
    await assert.rejects(unguarded.goto('https://127.0.0.1/private', {timeout:5000}));
    await assert.rejects(unguarded.goto('https://www.trendyol.com/redirect', {timeout:5000}));
    assert.equal(hits, before + 1, 'Yalnızca izinli redirect kaynağına erişildi');
    const ws = await page.evaluate(() => new Promise((resolve) => {
      const socket = new WebSocket('wss://127.0.0.1/private');
      socket.onerror = () => resolve('blocked');
      socket.onopen = () => {socket.close();resolve('opened');};
      setTimeout(() => {socket.close();resolve('timeout');}, 3000);
    }));
    assert.equal(ws, 'blocked');
    assert.ok(connects > 0);
    assert.equal(await page.$eval('script[type="application/ld+json"]', e => JSON.parse(e.textContent).offers.price), '200.00');
  } finally {
    if (browser) await browser.close();
    if (proxy) await proxy.close();
    if (fixture) {
      fixture.closeAllConnections();
      await new Promise((resolve) => fixture.close(resolve));
    }
    fs.rmSync(temp, {recursive:true, force:true});
  }
});
