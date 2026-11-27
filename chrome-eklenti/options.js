const $ = (id) => document.getElementById(id);
chrome.storage.sync.get({ url: '', token: '' }).then((s) => { $('url').value = s.url; $('token').value = s.token; });

$('save').addEventListener('click', async () => {
  const st = $('st'); st.className = ''; st.textContent = 'Deneniyor…';
  const url = $('url').value.trim(), token = $('token').value.trim();
  let origin;
  try { origin = new URL(url).origin; } catch (e) { st.className = 'err'; st.textContent = 'Sunucu adresi geçerli bir adres değil.'; return; }
  // Sunucuya istek atabilmek için o adrese izin iste (yalnız bu alan adı)
  const granted = await chrome.permissions.request({ origins: [origin + '/*'] });
  if (!granted) { st.className = 'err'; st.textContent = 'Sunucu adresine erişim izni verilmedi.'; return; }
  await chrome.storage.sync.set({ url, token });
  const r = await chrome.runtime.sendMessage({ kf: 'api', body: { action: 'ping' } });
  if (r && r.ok) { st.className = 'ok'; st.textContent = 'Bağlandı: ' + r.name + (r.pro ? ' (Pro)' : ' — panelde kâr gösterimi için Pro plan gerekir'); }
  else { st.className = 'err'; st.textContent = (r && r.message) || 'Bağlanamadı.'; }
});
