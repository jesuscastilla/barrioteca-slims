<?php
// Cargar configuracion minima de SLiMS para acceder a la BD
define('INDEX_AUTH', '1');
require 'sysconfig.inc.php';
// Consultar ultimos libros (max 12)
$libros = [];
$q = $dbs->query("SELECT b.biblio_id, b.title, COALESCE(GROUP_CONCAT(DISTINCT a.author_name ORDER BY ba.level SEPARATOR '; '), '') AS author, b.image FROM biblio b LEFT JOIN biblio_author ba ON b.biblio_id = ba.biblio_id LEFT JOIN mst_author a ON ba.author_id = a.author_id WHERE b.opac_hide < 1 GROUP BY b.biblio_id ORDER BY b.last_update DESC LIMIT 12");
if ($q) {
    while ($r = $q->fetch_assoc()) {
        $img = $r['image'] ?? '';
        $thumb = SWB . 'lib/minigalnano/createthumb.php?filename=' . urlencode($img ? 'images/docs/' . $img : 'images/default/image.png') . '&width=120';
        $libros[] = ['title' => $r['title'], 'author' => $r['author'] ?: 'Autora Desconocida', 'image' => $thumb];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Barrioteca Acalenca</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #fff; color: #141414; min-height: 100vh; }
  .topbar { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; border-bottom: 1px solid #eee; position: sticky; top: 0; background: #fff; z-index: 100; }
  .topbar h1 { font-family: Georgia, 'Times New Roman', serif; font-style: italic; font-size: 1.3rem; font-weight: 700; }
  .topbar .btns { display: flex; gap: 8px; }
  .btn { padding: 8px 16px; border-radius: 8px; font-size: 0.75rem; font-weight: 600; text-decoration: none; text-transform: uppercase; letter-spacing: 0.04em; border: none; cursor: pointer; }
  .btn-staff { background: #141414; color: #fff; }
  .btn-staff:hover { background: #333; }
  .btn-member { background: #f5f5f0; color: #141414; border: 1px solid #ddd; }
  .btn-member:hover { background: #eee; }
  .container { max-width: 800px; margin: 0 auto; padding: 24px 20px 80px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 16px; }
  .book-card { border: 1px solid #eee; border-radius: 12px; overflow: hidden; background: #fff; transition: box-shadow 0.2s; }
  .book-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
  .book-card img { width: 100%; height: 180px; object-fit: cover; background: #f5f5f0; }
  .book-card .info { padding: 10px 12px; }
  .book-card .title { font-size: 0.78rem; font-weight: 600; line-height: 1.3; margin-bottom: 3px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .book-card .author { font-size: 0.68rem; color: #888; }
  h2 { font-size: 1.1rem; font-weight: 700; margin: 24px 0 16px; color: #141414; }
  .empty { text-align: center; color: #aaa; padding: 40px 0; font-style: italic; }
</style>
</head>
<body>
<div class="topbar">
  <h1>Barrioteca Acalenca</h1>
  <div class="btns">
    <a href="index.php?p=member" class="btn btn-member">Socias</a>
    <a href="admin/" class="btn btn-staff">Staff</a>
  </div>
</div>

<div class="container">
  <h2>Ultimos libros</h2>
  <?php if (empty($libros)): ?>
  <div class="empty">El catalogo esta vacio.</div>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($libros as $l): ?>
    <div class="book-card">
      <img src="<?= htmlspecialchars($l['image']) ?>" alt="<?= htmlspecialchars($l['title']) ?>" loading="lazy">
      <div class="info">
        <div class="title"><?= htmlspecialchars($l['title']) ?></div>
        <div class="author"><?= htmlspecialchars($l['author']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</body>
</html>