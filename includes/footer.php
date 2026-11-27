</main>
<?php if(!empty($uiShell)):?><footer class="ui-footer"><span>Covisca</span><span><a href="kvkk.php">Gizlilik</a> · <a href="kosullar.php">Kullanım koşulları</a></span></footer></div><?php else:?>
<footer class="site-footer">
  <div class="footer-inner">
    <div class="footer-brand"><span class="logo-mark">K</span>Kâr<span>&</span>Fiyat</div>
    <div class="footer-note">Pazaryeri satıcısının net kâr hesabı ve fiyat optimizasyonu. Komisyon oranları 2025–2026 güncel kategori tarifelerinden derlenmiştir; kesin rakam için kendi satıcı panelinizi doğrulayın.</div>
    <div class="footer-links">
      <a href="index.php">Hesapla</a>
      <?php if ($u ?? null): ?>
        <a href="dashboard.php">Ürünlerim</a>
        <a href="magaza.php">Mağaza Bağla</a>
        <a href="odeme.php">Planım</a>
      <?php else: ?>
        <a href="giris.php">Giriş</a>
        <a href="kayit.php">Kayıt</a>
      <?php endif; ?>
      <a href="kvkk.php">KVKK</a>
      <a href="kosullar.php">Kullanım Koşulları</a>
    </div>
    <div class="footer-note">Komisyon oranları 2025–2026 güncel kategori tarifelerinden derlenmiştir; kesin rakam için kendi satıcı panelinizi doğrulayın.</div>
  </div>
</footer>
<?php endif;?>
</body>
</html>
