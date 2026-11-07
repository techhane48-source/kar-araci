<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'Blog — ' . APP_NAME;
$pageDesc = 'E-ticaret kârlılığı, pazaryeri komisyonları ve fiyat optimizasyonu üzerine yazılar.';

$posts = db()->query("SELECT * FROM posts WHERE published = 1 ORDER BY created_at DESC, id DESC")->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <div class="toolbar"><h1>Blog</h1></div>

  <?php if (!$posts): ?>
    <div class="card">
      <p class="hint">Henüz yayınlanmış yazı yok. Yakında e-ticaret kârlılığı üzerine yazılarla buradayız.</p>
    </div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($posts as $p): ?>
      <div class="card">
        <h2><a href="blog-detay.php?slug=<?= h(urlencode($p['slug'])) ?>"><?= h($p['title']) ?></a></h2>
        <?php if ($p['excerpt'] !== ''): ?><p><?= h($p['excerpt']) ?></p><?php endif; ?>
        <div class="muted" style="margin-bottom:12px"><?= h($p['created_at']) ?></div>
        <a href="blog-detay.php?slug=<?= h(urlencode($p['slug'])) ?>" class="btn-primary" style="display:inline-block">Devamını Oku</a>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
