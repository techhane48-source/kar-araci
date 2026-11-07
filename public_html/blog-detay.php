<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

$slug = (string) ($_GET['slug'] ?? '');
$post = null;
if ($slug !== '') {
    $stmt = db()->prepare("SELECT * FROM posts WHERE slug = ? AND published = 1");
    $stmt->execute([$slug]);
    $post = $stmt->fetch() ?: null;
}

$pageTitle = $post ? ($post['title'] . ' — ' . APP_NAME) : ('Yazı Bulunamadı — ' . APP_NAME);
require __DIR__ . '/../includes/header.php';
?>
<div class="wrap">
  <?php if (!$post): ?>
    <div class="card" style="text-align:center;padding:40px">
      <h1>Yazı bulunamadı</h1>
      <p class="hint">Aradığın yazı mevcut değil ya da henüz yayınlanmadı.</p>
      <a href="blog.php" class="btn-primary" style="display:inline-block">Bloğa Dön</a>
    </div>
  <?php else: ?>
    <div class="card">
      <h1><?= h($post['title']) ?></h1>
      <div class="muted" style="margin-bottom:20px"><?= h($post['created_at']) ?></div>
      <div class="post-content"><?= nl2br(h($post['content'])) ?></div>
      <div style="margin-top:24px"><a href="blog.php" class="btn-ghost">&larr; Tüm Yazılar</a></div>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
