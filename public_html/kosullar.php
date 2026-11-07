<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'Kullanım Koşulları — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap" style="max-width:760px">
  <h1>Kullanım Koşulları</h1>
  <div class="card" style="margin-top:16px;font-size:14px;line-height:1.7">
    <h2 style="font-size:15px;margin-bottom:8px">1. Hizmet</h2>
    <p><?= h(APP_NAME) ?>, pazaryeri satıcılarına net kâr hesaplama ve fiyat optimizasyonu aracı sunar. Hesaplamalar bilgilendirme amaçlıdır.</p>

    <h2 style="font-size:15px;margin:16px 0 8px">2. Komisyon Oranları</h2>
    <p>Komisyon oranları 2025–2026 dönemine ait kamuya açık kategori tarifelerinden derlenmiştir. Pazaryerlerinin güncel tarifeleri değişebilir; kesin oranlar için kendi satıcı panelinizi kontrol etmeniz gerekir. Bu araçtaki hesaplamalara dayanarak alınan ticari kararların sorumluluğu kullanıcıya aittir.</p>

    <h2 style="font-size:15px;margin:16px 0 8px">3. Abonelik</h2>
    <p>Pro plan ücretli aylık aboneliktir. Ödemeyi banka havalesi veya (yapılandırılmışsa) iyzico üzerinden kredi kartıyla yapabilirsiniz. Havale ödemeleri yönetici onayından sonra, kart ödemeleri ödeme doğrulandığında aktifleşir. Abonelik yenilemesi otomatik değildir; her dönem yeniden ödeme yapılır.</p>

    <h2 style="font-size:15px;margin:16px 0 8px">4. Yükümlülükler</h2>
    <p>Hesabınızın güvenliğinden siz sorumlusunuz. Hizmeti yasa dışı amaçla kullanmayı kabul etmiş sayılmazsınız.</p>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
