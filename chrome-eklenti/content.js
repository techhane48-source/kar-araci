// Kâr&Fiyat içerik betiği: Trendyol fiyat/tarife tablolarındaki her seçeneğe net kâr rozeti ekler.
// Yalnızca okur ve rozet ekler; sayfadaki hiçbir alanı doldurmaz, hiçbir düğmeye basmaz.
(function () {
  'use strict';
  const cache = new Map();          // barkod|fiyat|oran → sonuç
  let notice = null;                // ayar/ağ hatası (bir kez göster)
  let timer = null;

  const fmt = (v) => v.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';
  const num = (s) => { // "1.234,56" → 1234.56
    if (s == null) return null;
    const t = String(s).replace(/[^\d.,]/g, '');
    if (!t) return null;
    const v = parseFloat(t.replace(/\./g, '').replace(',', '.'));
    return isFinite(v) ? v : null;
  };

  // Rozetlerimizi hariç tutarak hücre metni
  function textOf(el) {
    let out = '';
    el.childNodes.forEach((n) => {
      if (n.nodeType === 3) out += n.textContent + '\n';
      else if (n.nodeType === 1 && !n.classList.contains('kf-badge')) out += (n.innerText != null ? n.innerText : n.textContent) + '\n';
    });
    return out;
  }
  const lines = (s) => s.split('\n').map((x) => x.trim()).filter(Boolean);

  // Metin parçaları sırayla: Trendyol "Barkod: <span>değer</span>" ya da "Barkod:" + ayrı satır kullanıyor
  function tokens(td) {
    const out = [];
    const w = document.createTreeWalker(td, NodeFilter.SHOW_TEXT, { acceptNode: (n) =>
      n.parentElement && n.parentElement.closest('.kf-badge') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT });
    for (let n = w.nextNode(); n; n = w.nextNode()) { const t = n.textContent.trim(); if (t) out.push(t); }
    return out;
  }

  function barcodeOf(td) {
    const ts = tokens(td);
    for (let i = 0; i < ts.length; i++) {
      const m = ts[i].match(/^Barkod\s*:\s*(.*)$/i) || (/^Barkod$/i.test(ts[i]) ? [null, ''] : null);
      if (!m) continue;
      const v = m[1].trim();
      if (v) return v.split(/\s+/)[0];
      const next = (ts[i + 1] || '').replace(/^:\s*/, '').trim();
      if (next) return next.split(/\s+/)[0];
    }
    return null;
  }

  function currentOf(td) {
    const ls = lines(textOf(td));
    const i = ls.findIndex((l) => /^Müşterinin Gördüğü Fiyat$/i.test(l));
    if (i >= 0) for (let j = i + 1; j < Math.min(i + 3, ls.length); j++) { const v = num(ls[j]); if (v) return v; }
    return null;
  }

  function parseRow(tr) {
    const tds = Array.from(tr.children);
    if (tds.length < 2) return null;
    let barcode = barcodeOf(tds[0]);
    let name = null;
    if (!barcode) {
      // Flaş sayfasında barkod açılır listede; ürün adıyla eşleştir (sunucu tek eşleşmeyi kabul eder)
      const ts = tokens(tds[0]);
      const stop = ts.findIndex((t) => /^Model Kodu/i.test(t));
      name = (stop > 0 ? ts.slice(0, stop) : ts).reduce((a, b) => (b.length > a.length ? b : a), '');
      if (name.length >= 12) barcode = 'ad:' + name; else name = null;
    }
    const item = { tr, barcode, name, current: currentOf(tds[0]), currentRate: null, cells: [] };
    tds.slice(1).forEach((td) => {
      const t = textOf(td);
      if (/Fiyat Güncelle|Maks\.|girebilirsin/i.test(t) && !/Komisyon/i.test(t)) return;
      const pm = t.match(/(\d{1,3}(?:\.\d{3})*,\d{2})\s*₺/);
      if (!pm) return;
      const rm = t.match(/Komisyon\s*%\s*([\d,]+)/i);
      const cell = { td, price: num(pm[1]), rate: rm ? num(rm[1]) : null, valid: /Geçerli aralık/i.test(t) };
      if (cell.valid && cell.rate != null) item.currentRate = cell.rate;
      item.cells.push(cell);
    });
    return item.cells.length ? item : null;
  }

  function key(bc, price, rate) { return bc + '|' + price + '|' + (rate == null ? '' : rate); }

  function badge(td, html, cls) {
    let b = td.querySelector(':scope > .kf-badge');
    if (!b) { b = document.createElement('div'); td.appendChild(b); }
    b.className = 'kf-badge ' + (cls || '');
    b.innerHTML = html;
  }

  function render(item, res, minMargin) {
    const td0 = item.tr.children[0];
    if (!res || !res.found) { badge(td0, 'Kâr&amp;Fiyat: bu barkod kayıtlı değil', 'kf-info'); item.cells.forEach((c) => c.td.querySelector(':scope > .kf-badge')?.remove()); return; }
    if (!res.has_cost) { badge(td0, 'Kâr&amp;Fiyat: maliyet girilmemiş', 'kf-info'); return; }
    if (res.current && res.current.profit != null) {
      badge(td0, 'Bugün net kâr <b>' + fmt(res.current.profit) + '</b>' + (res.current.margin != null ? ' · %' + res.current.margin.toFixed(1).replace('.', ',') : ''), 'kf-info');
    } else td0.querySelector(':scope > .kf-badge')?.remove();
    let best = null;
    item.cells.forEach((c, i) => { const o = res.options[i]; if (o && o.profit != null && (best == null || o.profit > best.profit)) best = { i, profit: o.profit }; });
    item.cells.forEach((c, i) => {
      const o = res.options[i];
      if (!o || o.profit == null) { c.td.querySelector(':scope > .kf-badge')?.remove(); return; }
      const cls = o.profit < 0 ? 'kf-neg' : (o.margin != null && o.margin < minMargin ? 'kf-low' : 'kf-pos');
      const diff = res.current && res.current.profit != null ? o.profit - res.current.profit : null;
      badge(c.td,
        'Net <b>' + fmt(o.profit) + '</b>' +
        '<small>%' + (o.margin != null ? o.margin.toFixed(1).replace('.', ',') : '—') + ' marj · %' + String(o.rate).replace('.', ',') + ' kom.' +
        (diff != null && Math.abs(diff) >= 0.01 ? ' · ' + (diff > 0 ? '+' : '') + fmt(diff) : '') + '</small>',
        cls + (best && best.i === i && item.cells.length > 1 ? ' kf-best' : ''));
    });
  }

  function showNotice(msg) {
    if (notice === msg) return;
    notice = msg;
    const first = document.querySelector('tr.content-row > td');
    if (!first) return;
    badge(first, 'Kâr&amp;Fiyat: ' + msg.replace(/</g, '&lt;') + ' <a data-kf-opt>Ayarları aç</a>', 'kf-info');
    first.querySelector('[data-kf-opt]')?.addEventListener('click', () => chrome.runtime.sendMessage({ kf: 'options' }));
  }

  async function scan() {
    const rows = Array.from(document.querySelectorAll('tr.content-row')).map(parseRow).filter((r) => r && r.barcode);
    if (!rows.length) return;
    const need = rows.filter((r) => !r.cells.every((c) => cache.has(key(r.barcode, c.price, c.rate))) || !cache.has(key(r.barcode, r.current, r.currentRate) + '|cur'));
    if (need.length) {
      const body = { action: 'profit', marketplace: 'trendyol', items: need.map((r) => ({
        barcode: r.name ? '' : r.barcode, name: r.name || undefined, current: r.current, current_rate: r.currentRate,
        options: r.cells.map((c) => ({ price: c.price, rate: c.rate })),
      })) };
      const data = await chrome.runtime.sendMessage({ kf: 'api', body });
      if (!data || !data.ok) { showNotice((data && data.message) || 'bağlantı kurulamadı'); return; }
      notice = null;
      window.__kfMinMargin = data.min_margin;
      data.items.forEach((res, i) => {
        const r = need[i];
        cache.set(key(r.barcode, r.current, r.currentRate) + '|cur', res);
        r.cells.forEach((c, j) => cache.set(key(r.barcode, c.price, c.rate), { found: res.found, has_cost: res.has_cost, opt: res.options[j] }));
      });
    }
    const minMargin = window.__kfMinMargin ?? 10;
    rows.forEach((r) => {
      const base = cache.get(key(r.barcode, r.current, r.currentRate) + '|cur');
      if (!base) return;
      const res = { found: base.found, has_cost: base.has_cost, current: base.current,
        options: r.cells.map((c) => (cache.get(key(r.barcode, c.price, c.rate)) || {}).opt || null) };
      render(r, res, minMargin);
    });
  }

  function schedule() { clearTimeout(timer); timer = setTimeout(() => { scan().catch(() => {}); }, 500); }
  new MutationObserver((muts) => {
    if (muts.every((m) => [...m.addedNodes, ...m.removedNodes].every((n) => n.nodeType === 1 && n.classList.contains('kf-badge')) && m.type === 'childList' && (m.addedNodes.length || m.removedNodes.length))) return;
    if (muts.every((m) => m.target.nodeType === 1 && m.target.closest && m.target.closest('.kf-badge'))) return;
    schedule();
  }).observe(document.body, { childList: true, subtree: true, characterData: true });
  chrome.storage.onChanged.addListener(() => { cache.clear(); notice = null; schedule(); });
  schedule();
})();
