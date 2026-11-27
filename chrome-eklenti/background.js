// Kâr&Fiyat arka plan: sunucu çağrıları burada yapılır (içerik betiği sayfanın CORS kurallarına takılmasın diye).
async function settings() {
  const s = await chrome.storage.sync.get({ url: '', token: '' });
  return { url: (s.url || '').trim(), token: (s.token || '').trim() };
}

async function call(body) {
  const { url, token } = await settings();
  if (!url || !token) return { ok: false, error: 'ayar', message: 'Eklenti ayarlarında sunucu adresi ve anahtar eksik.' };
  let res;
  try {
    res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
      body: JSON.stringify(body),
    });
  } catch (e) {
    return { ok: false, error: 'ag', message: 'Sunucuya ulaşılamadı: ' + e.message };
  }
  let data = null;
  try { data = await res.json(); } catch (e) { /* JSON değil */ }
  if (!data) return { ok: false, error: 'yanit', message: 'Sunucudan beklenmeyen yanıt (' + res.status + ').' };
  if (!res.ok && data.ok !== false) data.ok = false;
  if (!data.message) data.message = data.error || '';
  return data;
}

chrome.runtime.onMessage.addListener((msg, sender, reply) => {
  if (msg && msg.kf === 'api') { call(msg.body).then(reply); return true; }
  if (msg && msg.kf === 'options') { chrome.runtime.openOptionsPage(); return false; }
  return false;
});
