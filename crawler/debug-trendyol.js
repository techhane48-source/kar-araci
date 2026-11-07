'use strict';
const puppeteer = require('puppeteer-core');
const fs = require('fs');

function findChrome() {
  const candidates = [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  ];
  for (const c of candidates) if (fs.existsSync(c)) return c;
  return null;
}

(async () => {
  const browser = await puppeteer.launch({
    executablePath: findChrome(),
    headless: 'new',
    args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--lang=tr-TR'],
  });
  const page = await browser.newPage();
  await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36');
  await page.goto('https://www.trendyol.com/sr?q=perde', { waitUntil: 'domcontentloaded', timeout: 30000 });
  await new Promise((r) => setTimeout(r, 5000));

  const info = await page.evaluate(() => {
    const out = { title: document.title, url: location.href, jsonld: [], samples: [] };
    document.querySelectorAll('script[type="application/ld+json"]').forEach((s) => {
      out.jsonld.push(s.textContent.slice(0, 600));
    });
    // fiyat gibi görünen elemanları topla
    document.querySelectorAll('*').forEach((el) => {
      const cls = el.className && typeof el.className === 'string' ? el.className : '';
      const txt = (el.textContent || '').trim();
      if (/prc|price|fiyat/i.test(cls) && /\d/.test(txt) && txt.length < 60 && out.samples.length < 30) {
        out.samples.push({ cls: cls, txt: txt.slice(0, 40) });
      }
    });
    return out;
  });

  console.log('TITLE:', info.title);
  console.log('URL:', info.url);
  console.log('--- JSON-LD (ilk 600 char) ---');
  info.jsonld.forEach((j, i) => console.log(`[${i}]`, j));
  console.log('--- fiyat elemanları ---');
  info.samples.forEach((s) => console.log(JSON.stringify(s)));

  await browser.close();
})();
