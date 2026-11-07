<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'KVKK Aydınlatma Metni — ' . APP_NAME;
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap" style="max-width:760px">
  <h1>KVKK Aydınlatma Metni</h1>
  <div class="card" style="margin-top:16px;font-size:14px;line-height:1.7">
    <p>6698 sayılı Kişisel Verilerin Korunması Kanunu ("KVKK") uyarınca, <?= h(APP_NAME) ?> hizmeti kapsamında kişisel verileriniz aşağıda açıklanan amaçlarla işlenmektedir.</p>

    <h2 style="font-size:15px;margin:16px 0 8px">1. İşlenen Veriler</h2>
    <p>Hesap oluştururken sağladığınız ad, e-posta adresi ve şifre (şifreniz tek yönlü şifrelenerek saklanır, okunabilir biçimde tutulmaz) ile hizmet kullanımınıza ilişkin kayıtlar (kayıtlı ürünler, ödeme bildirimleri).</p>

    <h2 style="font-size:15px;margin:16px 0 8px">2. İşleme Amaçları</h2>
    <p>Hesabınızın oluşturulması ve yönetilmesi, hizmetin sunulması (kâr hesabı, ürün kaydetme, abonelik), ödeme bildirimlerinin eşleştirilmesi ve yasal yükümlülüklerin yerine getirilmesi.</p>

    <h2 style="font-size:15px;margin:16px 0 8px">3. Hukuki Sebep</h2>
    <p>KVKK m.5/1-c (bir sözleşmenin kurulması ve ifası için gerekli olması) ve m.5/1-f (meşru menfaat) kapsamında işlenir.</p>

    <h2 style="font-size:15px;margin:16px 0 8px">4. Aktarım</h2>
    <p>Verileriniz üçüncü kişilerle paylaşılmaz. Yalnızca yasal zorunluluk halinde yetkili kurumlara aktarılabilir.</p>

    <h2 style="font-size:15px;margin:16px 0 8px">5. Haklarınız</h2>
    <p>KVKK m.11 kapsamında verilerinize erişme, düzeltilmesini, silinmesini isteme ve işlemeye itiraz etme haklarına sahipsiniz. Bu haklarınız için yönetici e-posta adresi üzerinden başvurabilirsiniz.</p>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
