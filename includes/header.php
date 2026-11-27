<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$u = current_user();
$pro = is_pro($u);
$flash = flash_get();
require_once __DIR__."/admin_ui.php";
$uiPage=basename((string)($_SERVER["SCRIPT_NAME"]??""));
$uiNav=ui_navigation(!empty($u["is_admin"]));$uiSection=null;
foreach($uiNav as $key=>$section)if(isset($section[2][$uiPage])){$uiSection=$key;break;}
$uiShell=$u&&$uiSection!==null;
$uiAuth=in_array($uiPage,['giris.php','kayit.php'],true);
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
<link rel="stylesheet" href="assets/style.css?v=13">
<?php if($uiShell||$uiAuth):?><link rel="stylesheet" href="assets/admin-ui.css?v=3"><script defer src="assets/admin-ui.js?v=2"></script><?php endif;?>
</head>
<body<?= $uiShell?' class="ui-shell"':($uiAuth?' class="ui-auth"':'') ?>>
<a href="#main-content" class="skip-link">Ana içeriğe geç</a>
<?php if($uiShell):?>
<aside class="ui-sidebar" id="ui-sidebar">
<a href="dashboard.php" class="ui-brand"><span class="ui-brand-mark"><?=ui_icon('finance')?></span><span>Covisca<small>E-ticaret yönetimi</small></span></a>
<button type="button" class="ui-menu-close btn-ghost">Menüyü kapat</button>
<nav class="ui-nav" aria-label="Ana menü"><?php foreach($uiNav as $key=>$section):?><a href="<?=h($section[1])?>" class="ui-nav-item<?=$key===$uiSection?' is-active':''?>" <?=$key===$uiSection?'aria-current="true"':''?>><?=ui_icon($key)?><span><?=h($section[0])?></span></a><?php endforeach;?></nav>
<div class="ui-sidebar-bottom"><a href="index.php#hesapla">Kâr hesap makinesi</a><small>Covisca · Mağaza yönetimi</small></div>
</aside><div class="ui-menu-backdrop" hidden></div><div class="ui-workspace">
<header class="ui-header">
<button type="button" class="ui-menu-toggle btn-ghost" aria-controls="ui-sidebar" aria-expanded="false"><?=ui_icon('menu')?> Menü</button>
<a href="magaza.php" class="ui-header-link" aria-label="Mağaza bağlantısı"><?=ui_icon('store')?><span>Mağaza bağlantısı</span></a>
<div class="ui-header-right"><a href="uyarilar.php" class="ui-header-link" aria-label="Uyarılar"><?=ui_icon('bell')?><span>Uyarılar</span></a>
<details class="ui-profile"><summary aria-label="Hesabım"><?=ui_icon('user')?><span>Hesabım</span></summary><div class="ui-profile-menu"><strong><?=h($u['name']?:$u['email'])?></strong><a href="hesabim.php">Hesap ayarları</a><a href="odeme.php">Abonelik</a><a href="cikis.php">Çıkış yap</a></div></details></div>
</header>
<?php else:?>
<header class="site-header<?= $u ? ' signed-in-header' : '' ?>">
  <div class="header-inner">
    <a href="index.php" class="logo"><span class="logo-mark">K</span>Kâr<span>&</span>Fiyat</a>
    <nav class="main-nav" aria-label="Genel menü">
      <a href="index.php#hesapla">Hesapla</a>
      <a href="index.php#ozellikler">Özellikler</a>
      <a href="index.php#fiyatlandirma">Fiyatlandırma</a>
      <a href="index.php#sss">SSS</a>
      <a href="blog.php">Blog</a>
    </nav>
    <div class="nav-auth"<?= $u ? ' role="navigation" aria-label="Mağaza yönetimi"' : '' ?>>
      <?php if ($u): ?>
        <a href="dashboard.php" class="btn-primary">Mağaza paneline dön</a><a href="hesabim.php" class="nav-link">Hesabım</a><a href="cikis.php" class="btn-ghost">Çıkış</a>
      <?php else: ?>
        <a href="giris.php" class="btn-ghost">Giriş Yap</a>
        <a href="kayit.php" class="btn-primary">Ücretsiz Başla</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<?php endif;?>
<?php if ($flash): ?>
<div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>
<main class="site-main" id="main-content" tabindex="-1">
<?php if($uiShell):?><div class="ui-context">Mağaza yönetimi <?=ui_icon('chevron')?> <strong><?=h($uiNav[$uiSection][0])?></strong></div>
<?php if($uiSection!=='dashboard'):?><nav class="ui-tabs" aria-label="Bölüm işlemleri"><?php foreach($uiNav[$uiSection][2] as $route=>$label):if($route==='perde-maliyeti.php')continue;?><a href="<?=h($route)?>" <?=$uiPage===$route?'aria-current="page" class="is-active"':''?>><?=h($label)?></a><?php endforeach;?></nav><?php endif;endif;?>
