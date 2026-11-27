<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$u = current_user();
$pro = is_pro($u);
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle ?? APP_NAME) ?></title>
<meta name="description" content="<?= h($pageDesc ?? APP_TAGLINE) ?>">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=10">
</head>
<body>
<header class="site-header">
  <div class="header-inner">
    <a href="index.php" class="logo"><span class="logo-mark">K</span>Kâr<span>&</span>Fiyat</a>
    <nav class="main-nav">
      <a href="index.php#hesapla">Hesapla</a>
      <a href="index.php#ozellikler">Özellikler</a>
      <a href="index.php#fiyatlandirma">Fiyatlandırma</a>
      <a href="index.php#sss">SSS</a>
      <a href="blog.php">Blog</a>
    </nav>
    <div class="nav-auth">
      <?php if ($u): ?>
        <a href="dashboard.php" class="nav-link">Dashboard</a>
        <a href="urunler.php" class="nav-link">Ürünlerim</a>
        <a href="toplu-analiz.php" class="nav-link">Toplu Analiz</a>
        <a href="promosyon-analiz.php" class="nav-link">Promosyon Analiz</a>
        <a href="canli-performans.php" class="nav-link">Canlı Performans</a>
        <a href="kar-marji.php" class="nav-link">Kâr Marjı</a>
        <a href="raporlar.php" class="nav-link">Raporlar</a>
        <a href="hakedis-desi.php" class="nav-link">Hakediş &amp; Desi</a>
        <a href="uyarilar.php" class="nav-link">Uyarılar</a>
        <a href="pazar-zekasi.php" class="nav-link">Pazar Zekası</a>
        <a href="magaza.php" class="nav-link">Mağaza Bağla</a>
        <a href="ayarlar.php" class="nav-link">Ayarlar</a>
        <?php if ($u['is_admin']): ?><a href="admin.php" class="nav-link">Panel</a><?php endif; ?>
        <div class="user-menu">
          <a href="hesabim.php" class="user-name"><?= h($u['name'] ?: $u['email']) ?><?= $pro ? ' <span class="pro-badge">PRO</span>' : '' ?></a>
          <a href="cikis.php" class="btn-ghost">Çıkış</a>
        </div>
      <?php else: ?>
        <a href="giris.php" class="btn-ghost">Giriş Yap</a>
        <a href="kayit.php" class="btn-primary">Ücretsiz Başla</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<?php if ($flash): ?>
<div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>
<main class="site-main">
