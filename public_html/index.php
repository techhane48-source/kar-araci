<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$u = current_user();
$pageTitle = APP_NAME . ' — Pazaryeri Kâr Hesabı ve Fiyat Optimizasyonu';
$pageDesc = APP_TAGLINE;

require __DIR__ . '/../includes/header.php';
?>

<!-- Hero -->
<div class="hero">
  <div class="hero-inner">
    <span class="hero-badge"><span class="dot"></span> 7 pazaryeri · güncel kategori komisyon oranları</span>
    <h1>Satıştaki gerçek kârını <em>anında</em> gör.</h1>
    <p>Trendyol, Hepsiburada, N11, Amazon, AliExpress, ÇiçekSepeti ve EPTTAVM'de ürün başı net kârını hesapla — komisyon, kargo ve KDV'yi çıkar, doğru satış fiyatını tersine çöz.</p>
    <div class="hero-cta">
      <?php if ($u): ?>
        <a href="#hesapla" class="btn-primary">Hesaplamaya Başla</a>
        <a href="urunler.php" class="btn-ghost">Ürünlerim</a>
      <?php else: ?>
        <a href="#hesapla" class="btn-primary">Hesap Makinesini Kullan</a>
        <a href="kayit.php" class="btn-ghost">Ücretsiz Üye Ol</a>
      <?php endif; ?>
    </div>
    <div class="trust-strip">
      <div class="trust-item"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> KVKK uyumlu</div>
      <div class="trust-item"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg> Şifreli veri</div>
      <div class="trust-item"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Anında sonuç</div>
    </div>
  </div>
</div>

<!-- Hesap makinesi -->
<div class="section" id="hesapla" style="padding-top:0;margin-top:-48px">
  <div class="section-inner">
    <div class="tabbar">
      <button data-mode="profit" class="on">Komisyon Hesapla</button>
      <button data-mode="price">Fiyat Optimize Et</button>
      <button data-mode="campaign">Kampanya Analizi</button>
    </div>

    <div class="grid">
      <div class="card">
        <h2>Girdiler</h2>

        <div class="two">
          <div class="row">
            <label>Pazar Yeri</label>
            <select id="marketplace">
              <option value="trendyol">Trendyol</option>
              <option value="hepsiburada">Hepsiburada</option>
              <option value="n11">N11</option>
              <option value="amazon">Amazon</option>
              <option value="aliexpress">AliExpress</option>
              <option value="ciceksepeti">ÇiçekSepeti</option>
              <option value="epttavm">EPTTAVM</option>
            </select>
          </div>
          <div class="row">
            <label>Kategori</label>
            <select id="category"></select>
          </div>
        </div>

        <div class="two">
          <div class="row">
            <label>Ürün Satış Fiyatı (₺)</label>
            <input type="number" id="price" value="400" step="0.01">
          </div>
          <div class="row">
            <label>Ürün Alış Fiyatı (₺)</label>
            <input type="number" id="cost" value="200" step="0.01">
          </div>
        </div>

        <div class="two">
          <div class="row">
            <label>Komisyon (%)</label>
            <input type="number" id="rate" value="21.36" step="0.01">
            <div class="hint" id="rate-hint"></div>
          </div>
          <div class="row">
            <label>KDV (%)</label>
            <input type="number" id="kdv" value="20" step="0.1">
          </div>
        </div>

        <div class="two">
          <div class="row">
            <label>Kargo Ücreti (₺)</label>
            <input type="number" id="cargo" value="50" step="0.01">
          </div>
          <div class="row">
            <label>Kargo Tipi</label>
            <div class="seg seg-3" id="cargoType">
              <button data-c="seller" class="on">Satıcıya Ait</button>
              <button data-c="export">İhracat</button>
              <button data-c="sameday">Aynı Gün</button>
            </div>
            <div id="extra-fee-block" style="display:none;margin-top:10px">
              <label id="extra-fee-label" style="font-size:12px">İhracat Bedeli (₺)</label>
              <input type="number" id="extra-fee" value="0" step="0.01">
              <div class="hint">Bu kesinti platformun ek bedelidir; kârdan düşülür. Değeri platform tarifene göre gir.</div>
            </div>
          </div>
        </div>

        <div class="two">
          <div class="row">
            <label>Hizmet Bedeli (₺)</label>
            <input type="number" id="service-fee" value="13.19" step="0.01">
            <div class="hint">Trendyol sabit hizmet bedeli 13,19₺ (otomatik; elle değiştirilebilir).</div>
          </div>
          <div class="row">
            <label>Stopaj (tevkifat)</label>
            <label class="check" style="margin:4px 0 8px"><input type="checkbox" id="stopaj-auto" checked> Otomatik — KDV-hariç satış × %1 (Trendyol)</label>
            <input type="number" id="stopaj" value="" step="0.01" disabled placeholder="Otomatik hesaplanır">
            <div class="hint">Stopaj, Trendyol'da KDV-hariç satış tutarının %1'i olarak otomatik hesaplanır. Elle girmek istersen işareti kaldır.</div>
          </div>
        </div>

        <div class="row">
          <label>İade Oranı (%)</label>
          <input type="number" id="return-rate" value="0" step="0.1">
          <div class="hint">Beklenen iade oranı; iade edilen ürünün satış tutarı kârdan düşülür.</div>
        </div>

        <div class="row" id="target-block" style="display:none">
          <label>Hedef</label>
          <div class="target-row">
            <div>
              <input type="number" id="targetProfit" value="50" step="0.01" placeholder="Kâr ₺">
              <div class="hint">Hedef net kâr (₺)</div>
            </div>
            <div>
              <input type="number" id="targetMargin" value="0" step="0.1" placeholder="Marj %">
              <div class="hint">veya hedef marj % (0 = kullanma)</div>
            </div>
          </div>
        </div>

        <div class="row" id="campaign-block" style="display:none">
          <label>İndirim Oranı (%)</label>
          <input type="number" id="discount" value="20" step="0.5">
          <div class="hint">Kampanyada uygulayacağın indirim yüzdesi. İndirimli fiyattaki net kârın ve "kampanya kârda mı, zararda mı" sonucu hesaplanır.</div>
        </div>

        <p class="hint" style="margin:12px 0 0">* Alış, Satış ve Kargo fiyatlarına KDV dahil girmeniz gerekmektedir.</p>

        <?php if ($u): ?>
        <div class="row" style="margin-top:20px">
          <button class="btn-primary" id="save-btn" style="width:100%">Ürünü Kaydet</button>
          <div class="hint">Kaydedilen ürünler <a href="urunler.php">Ürünlerim</a>'de toplu kârlılık tablosunda görünür.</div>
        </div>
        <?php else: ?>
        <div class="row" style="margin-top:20px">
          <a href="kayit.php" class="btn-primary" style="width:100%;text-align:center">Ücretsiz üye ol — ürünlerini kaydet</a>
          <div class="hint">Üyelik ücretsiz. Kaydedince tüm ürünlerinin kârlılığını tek tabloda görürsün.</div>
        </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2>Sonuç</h2>
        <div class="big-result">
          <div class="label" id="result-label">Net Kâr</div>
          <div class="value" id="result-value">—</div>
        </div>
        <div class="kpi-row" style="grid-template-columns:repeat(4,1fr)">
          <div class="kpi"><div class="k-label">Kâr Marjı</div><div class="k-value" id="kpi-margin">—</div></div>
          <div class="kpi"><div class="k-label">Kâr Oranı</div><div class="k-value" id="kpi-ratio">—</div></div>
          <div class="kpi"><div class="k-label">Komisyon</div><div class="k-value" id="kpi-commission">—</div></div>
          <div class="kpi"><div class="k-label">Ödenecek KDV</div><div class="k-value" id="kpi-kdv">—</div></div>
        </div>
        <table id="breakdown"></table>
        <div class="note">
          <b>Nasıl hesaplanıyor?</b>
          Pazaryeri, ürün satış fiyatı üzerinden <b>komisyon</b> ve (varsa) <b>hizmet bedeli</b> adı altında ana giderleri keser.
          Ardından <b>kargo ücreti</b> düşülür, kalan tutar hesabınıza yatırılır. Burada gösterilen <b>KDV</b> tutarını
          ay sonunda devlete ödersiniz. <b>Kâr</b> ise ürün başı kazandığınız net kârdır.<br><br>
          <b>Satış Fiyatı:</b> KDV dahil sergilediğiniz ürün fiyatı · <b>Alış Fiyatı:</b> KDV dahil ürün maliyeti ·
          <b>Komisyon:</b> kategoriden yapılan kesinti · <b>Kargo:</b> kargoya ödediğiniz KDV dahil ücret ·
          <b>KDV:</b> devletin belirlediği katma değer vergisi.<br><br>
          Komisyon oranları 2025–2026 güncel kategori tarifelerindendir; yeni pazaryerleri (AliExpress, ÇiçekSepeti,
          EPTTAVM) için genel ortalamadır — kesin oran için satıcı panelinizi esas alın, kutuyu elle düzeltebilirsiniz.
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Nasıl çalışır -->
<div class="section" style="background:var(--bg-soft)">
  <div class="section-inner">
    <span class="section-eyebrow">Nasıl çalışır</span>
    <div class="section-title">Üç adımda kârlılığını yönet</div>
    <div class="section-sub">Karmaşık Excel dosyalarına ve ezbere hesap yapmaya son.</div>
    <div class="steps">
      <div class="step">
        <div class="step-num">01</div>
        <h3>Mağazanı bağla</h3>
        <p>Trendyol mağazanı kendi API bilgilerinle (satıcı ID, API key, API secret) bir dakikada bağla; ürünlerin otomatik gelsin.</p>
      </div>
      <div class="step">
        <div class="step-num">02</div>
        <h3>Maliyetlerini gir</h3>
        <p>Çekilen ürünlere alış maliyeti, kargo, paketleme ve iade oranını gir. Komisyon ve tüm kesintiler arka planda otomatik işlenir.</p>
      </div>
      <div class="step">
        <div class="step-num">03</div>
        <h3>Kârlılığını yönet</h3>
        <p>Net kârını ve marjını anında gör, hedef kârına göre doğru satış fiyatını tek tıkla bul.</p>
      </div>
    </div>
  </div>
</div>

<!-- Özellikler -->
<div class="section" id="ozellikler">
  <div class="section-inner">
    <span class="section-eyebrow">Özellikler</span>
    <div class="section-title">Neler yapabilirsin</div>
    <div class="section-sub">Pazaryeri satıcısının gerçek ihtiyacına odaklanan araç.</div>
    <div class="features">
      <div class="feature">
        <div class="ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg></div>
        <h3>Net Kâr Hesabı</h3>
        <p>Komisyon, kargo, paketleme, reklam ve iade — hepsini çıkar, gerçek kârını gör.</p>
      </div>
      <div class="feature">
        <div class="ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/></svg></div>
        <h3>Fiyat Optimizasyonu</h3>
        <p>Hedef kârı veya marjı gir, doğru satış fiyatını tersine çözsün.</p>
      </div>
      <div class="feature">
        <div class="ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg></div>
        <h3>Toplu Kârlılık</h3>
        <p>Ürünlerini kaydet, hepsinin kâr/marjını tek tabloda gör. Zarar edeni anında yakala.</p>
      </div>
      <div class="feature">
        <div class="ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg></div>
        <h3>CSV İçe Aktarma</h3>
        <p>Yüzlerce ürünü tek CSV ile toplu yükle (Pro). Excel derdini bitir.</p>
      </div>
      <div class="feature">
        <div class="ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 13h6M9 17h4"/></svg></div>
        <h3>7 Pazaryeri</h3>
        <p>Trendyol, Hepsiburada, N11, Amazon, AliExpress, ÇiçekSepeti, EPTTAVM — hepsi tek araçta, güncel oranlarla.</p>
      </div>
      <div class="feature">
        <div class="ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
        <h3>İki Faktörlü Doğrulama (2FA)</h3>
        <p>Google Authenticator uyumlu 2FA ile hesabın çift katmanlı korunur.</p>
      </div>
      <div class="feature">
        <div class="ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg></div>
        <h3>Partner Programı</h3>
        <p>Yönlendirdiğin her abonenin ödemesinden %15 hakediş kazan, bitiş yok.</p>
      </div>
      <div class="feature">
        <div class="ico"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg></div>
        <h3>Demo Hesabı</h3>
        <p>Tek tıkla örnek ürünlerle dolu demo hesabına gir, kayıt olmadan dene.</p>
      </div>
    </div>
  </div>
</div>

<!-- Fiyatlandırma -->
<div class="section" id="fiyatlandirma" style="background:var(--bg-soft)">
  <div class="section-inner">
    <span class="section-eyebrow">Fiyatlandırma</span>
    <div class="section-title">Basit fiyatlandırma</div>
    <div class="section-sub">Ücretsiz başla, ihtiyacın büyüyünce Pro'ya geç. Taahhüt yok.</div>
    <div class="pricing">
      <div class="price-card">
        <h3>Ücretsiz</h3>
        <div class="price-tag">0₺</div>
        <ul>
          <li>Kâr hesabı ve fiyat optimizasyonu</li>
          <li>5 ürün kaydetme</li>
          <li>Temel kârlılık tablosu</li>
          <li>7 pazaryeri desteği</li>
        </ul>
        <a href="kayit.php" class="btn-ghost" style="width:100%;text-align:center">Ücretsiz Başla</a>
      </div>
      <div class="price-card featured">
        <span class="popular">POPÜLER</span>
        <h3>Pro</h3>
        <div class="price-tag"><?= h(number_format((float)setting('pro_price'), 0, ',', '.')) ?>₺ <small>/ ay</small></div>
        <ul>
          <li>Sınırsız ürün kaydetme</li>
          <li>Toplu CSV içe aktarma</li>
          <li>Tüm ürünlerin kârlılık analizi</li>
          <li>Öncelikli destek</li>
        </ul>
        <a href="odeme.php" class="btn-primary" style="width:100%;text-align:center">Pro'ya Geç</a>
      </div>
    </div>
  </div>
</div>

<!-- SSS -->
<div class="section" id="sss">
  <div class="section-inner">
    <span class="section-eyebrow">SSS</span>
    <div class="section-title">Sıkça Sorulan Sorular</div>
    <div class="section-sub">Aklına takılanları yanıtladık.</div>
    <div class="faq">
      <div class="faq-item">
        <div class="faq-q">Komisyon oranları güncel mi?</div>
        <div class="faq-a">Oranlar 2025–2026 dönemi kamuya açık kategori tarifelerinden derlenmiştir. Pazaryerleri oranları değiştirebilir; kesin rakam için kendi satıcı panelinizi kontrol etmenizi öneririz.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Hangi pazaryerlerini destekliyor?</div>
        <div class="faq-a">Trendyol, Hepsiburada, N11, Amazon, AliExpress, ÇiçekSepeti ve EPTTAVM. İlk dördü için kategori bazlı komisyon oranları ayrı ayrı tanımlıdır; yeni üçü için genel oran kullanılır ve komisyon kutusundan elle düzeltilebilir.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Mağazamı bağlamam gerekiyor mu?</div>
        <div class="faq-a">Hayır. Ürünlerini elle girebilir veya CSV ile toplu yükleyebilirsin. İstersen Trendyol mağazanı API ile bağlayıp ürünlerini otomatik çekebilirsin — diğer pazaryeri entegrasyonları yakında eklenecek.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Hesaplama KDV dahil mi?</div>
        <div class="faq-a">Evet, tüm girdiler KDV dahil TL üzerinden hesaplanır. Komisyon, kargo, hizmet bedeli, stopaj ve iade oranı kârdan düşülür; KDV ayrıca gösterilir. Gelir/kurumlar vergisi bu aracın kapsamı dışındadır.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Ücretsiz plan yeterli mi?</div>
        <div class="faq-a">5 ürüne kadar ücretsiz. Daha fazla ürün ve toplu CSV için Pro plana geçmen gerekir. Taahhüt yok, istediğin zaman bırakabilirsin.</div>
      </div>
    </div>
  </div>
</div>

<!-- CTA -->
<div class="section" style="padding-top:0">
  <div class="section-inner">
    <div class="cta-band">
      <h2>Zarar ettiğini fark etmeden satma</h2>
      <p>Net kârını saniyeler içinde gör, doğru fiyatı bul. Ücretsiz başla — kart bilgisi gerekmez.</p>
      <?php if ($u): ?>
        <a href="#hesapla" class="btn-primary">Hesaplamaya Başla</a>
      <?php else: ?>
        <a href="kayit.php" class="btn-primary">Ücretsiz Hesap Aç</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>window.CATEGORIES = <?= json_encode(categories(), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/app.js?v=4"></script>
<?php if ($u): ?>
<script>
document.getElementById("save-btn").addEventListener("click", async () => {
  const category = window.CATEGORIES[+document.getElementById("category").value].name;
  const priceVal = mode === "price" ? lastPrice : num("price");
  const sa = document.getElementById("stopaj-auto");
  const stopajVal = (sa && sa.checked) ? priceVal / (1 + num("kdv")/100) * 0.01 : num("stopaj");
  const body = {
    marketplace, category,
    cost: num("cost"), price: priceVal, cargo: num("cargo"),
    rate: num("rate"), kdv: num("kdv"),
    cargo_type: cargoType,
    extra_fee: num("extra-fee"),
    service_fee: num("service-fee"), stopaj: stopajVal, return_rate: num("return-rate"),
    name: prompt("Ürüne bir ad ver (örn. 'Kadın Bot 38')", "") || "Ürün"
  };
  const resp = await fetch("api-save.php", {
    method: "POST",
    headers: {"Content-Type": "application/json"},
    body: JSON.stringify(body)
  });
  const r = await resp.json();
  alert(r.ok ? "Ürün kaydedildi. Ürünlerim'de görüntüleyebilirsin." : (r.error || "Kayıt başarısız"));
  if (r.redirect) location.href = r.redirect;
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
