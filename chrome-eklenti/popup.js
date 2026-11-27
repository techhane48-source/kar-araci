const $ = (id) => document.getElementById(id);
const n = (id) => { const v = parseFloat(($(id).value || '').replace(/\./g, '').replace(',', '.')); return isFinite(v) ? v : 0; };
const fmt = (v) => v.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺';

function setStatus(cls, html) { const s = $('st'); s.className = cls; s.innerHTML = html; s.querySelector('a')?.addEventListener('click', () => chrome.runtime.openOptionsPage()); }

chrome.runtime.sendMessage({ kf: 'api', body: { action: 'ping' } }).then((r) => {
  if (r && r.ok) setStatus('ok', 'Bağlı: ' + r.name.replace(/</g, '&lt;') + (r.pro ? ' · Pro' : ' · panelde kâr için Pro gerekir'));
  else setStatus('err', ((r && r.message) || 'Bağlı değil.').replace(/</g, '&lt;') + ' <a>Ayarlar</a>');
});

$('calc').addEventListener('click', async () => {
  const r = await chrome.runtime.sendMessage({ kf: 'api', body: { action: 'calc', cost: n('cost'), price: n('price'), rate: n('rate'), kdv: n('kdv') || 20, cargo: n('cargo') } });
  const box = $('res'); box.style.display = 'block';
  if (!r || !r.ok) { box.className = 'neg'; $('v').textContent = 'Hesaplanamadı'; $('d').textContent = (r && r.message) || ''; return; }
  box.className = r.profit < 0 ? 'neg' : 'pos';
  $('v').textContent = fmt(r.profit) + '  ·  %' + r.margin.toFixed(1).replace('.', ',');
  $('d').textContent = 'Komisyon ' + fmt(r.commission) + ' · Net KDV ' + fmt(r.net_kdv) + ' · Stopaj ' + fmt(r.stopaj) + ' · Hizmet ' + fmt(r.service_fee);
});
