<?php
// Si hay parametros GET (login, member, etc), cargar el OPAC original completo
if (!empty($_GET)) {
    require __DIR__ . '/index_opac_original.php';
    exit;
}

// Cargar configuracion minima de SLiMS para acceder a la BD
define('INDEX_AUTH', '1');
require 'sysconfig.inc.php';
// Consultar todos los libros disponibles
$libros = [];
$q = $dbs->query("SELECT b.biblio_id, b.title, COALESCE(GROUP_CONCAT(DISTINCT a.author_name ORDER BY ba.level SEPARATOR '; '), '') AS author, b.image, b.notes, b.isbn_issn FROM biblio b LEFT JOIN biblio_author ba ON b.biblio_id = ba.biblio_id LEFT JOIN mst_author a ON ba.author_id = a.author_id WHERE b.opac_hide < 1 GROUP BY b.biblio_id ORDER BY b.last_update DESC");
if ($q) {
    while ($r = $q->fetch_assoc()) {
        $img = $r['image'] ?? '';
        $thumb = SWB . 'lib/minigalnano/createthumb.php?filename=' . urlencode($img ? 'images/docs/' . $img : 'images/default/image.png') . '&width=200';
        $libros[] = [
            'title' => $r['title'],
            'author' => $r['author'] ?: 'Autora Desconocida',
            'isbn' => $r['isbn_issn'] ?? '',
            'notes' => $r['notes'] ?? '',
            'image' => $thumb
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Barrioteca Acalenca - Catalogo</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: #fafaf8; color: #1a1a1a; min-height: 100vh; }
  
  .topbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 28px; background: #fff; border-bottom: 1px solid #e8e8e4; position: sticky; top: 0; z-index: 100; }
  .topbar h1 { font-family: Georgia, 'Times New Roman', serif; font-style: italic; font-size: 1.4rem; font-weight: 700; color: #1a1a1a; }
  .topbar .btns { display: flex; gap: 10px; }
  .btn { padding: 10px 20px; border-radius: 10px; font-size: 0.78rem; font-weight: 600; text-decoration: none; text-transform: uppercase; letter-spacing: 0.04em; transition: all 0.2s; }
  .btn-staff { background: #1a1a1a; color: #fff; }
  .btn-staff:hover { background: #333; }
  .btn-member { background: #f5f5f0; color: #1a1a1a; border: 1px solid #ddd; }
  .btn-member:hover { background: #e8e8e4; }

  .container { max-width: 960px; margin: 0 auto; padding: 28px 20px 80px; }
  h2 { font-size: 1.2rem; font-weight: 700; margin-bottom: 20px; color: #1a1a1a; display: flex; align-items: center; gap: 8px; }
  h2 .count { font-size: 0.8rem; color: #888; font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; }
  
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 20px; }
  .book-card { background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.04); transition: all 0.2s; display: flex; flex-direction: column; }
  .book-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.08); transform: translateY(-2px); }
  .book-card .cover { width: 100%; height: 240px; background: #f0f0ed; display: flex; align-items: center; justify-content: center; overflow: hidden; }
  .book-card .cover img { width: 100%; height: 100%; object-fit: cover; }
  .book-card .info { padding: 14px 16px; flex: 1; display: flex; flex-direction: column; gap: 4px; }
  .book-card .title { font-size: 0.82rem; font-weight: 700; line-height: 1.3; color: #1a1a1a; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .book-card .author { font-size: 0.7rem; color: #888; line-height: 1.3; }
  .book-card .isbn { font-size: 0.65rem; color: #bbb; font-family: 'Courier New', monospace; }
  .empty { text-align: center; color: #bbb; padding: 60px 0; font-style: italic; font-size: 1rem; }

  /* Modal */
  .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; justify-content: center; align-items: center; padding: 20px; }
  .modal-overlay.open { display: flex; }
  .modal { background: #fff; border-radius: 20px; max-width: 500px; width: 100%; max-height: 80vh; overflow-y: auto; box-shadow: 0 8px 40px rgba(0,0,0,0.15); position: relative; }
  .modal .close { position: absolute; top: 12px; right: 12px; background: rgba(0,0,0,0.08); border: none; width: 32px; height: 32px; border-radius: 50%; font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #555; transition: background 0.2s; }
  .modal .close:hover { background: rgba(0,0,0,0.15); }
  .modal .modal-cover { width: 100%; height: 260px; background: #f0f0ed; }
  .modal .modal-cover img { width: 100%; height: 100%; object-fit: cover; }
  .modal .modal-body { padding: 20px 24px; }
  .modal .modal-body h3 { font-size: 1.1rem; font-weight: 700; margin-bottom: 8px; }
  .modal .modal-body .meta { font-size: 0.8rem; color: #888; margin-bottom: 12px; }
  .modal .modal-body .meta span { display: block; margin-bottom: 2px; }
  .modal .modal-body .synopsis { font-size: 0.85rem; line-height: 1.6; color: #444; white-space: pre-line; }

  @media (max-width: 600px) {
    .grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
    .book-card .cover { height: 180px; }
    .topbar { padding: 12px 16px; }
    .topbar h1 { font-size: 1.1rem; }
    .btn { padding: 8px 14px; font-size: 0.7rem; }
  }
</style>
</head>
<body>
<div class="topbar">
  <h1>Barrioteca Acalenca</h1>
  <div class="btns">
    <a href="index.php?p=login" class="btn btn-member">Socias</a>
    <a href="admin/" class="btn btn-staff">Staff</a>
  </div>
</div>

<div class="container">
  <h2>
    Catalogo
    <?php if (!empty($libros)): ?>
    <span class="count">- <?= count($libros) ?> libros</span>
    <?php endif; ?>
  </h2>
  <?php if (empty($libros)): ?>
  <div class="empty">El catalogo esta vacio.</div>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($libros as $i => $l): ?>
    <div class="book-card" onclick="openDetail(<?= $i ?>)">
      <div class="cover">
        <img src="<?= htmlspecialchars($l['image']) ?>" alt="<?= htmlspecialchars($l['title']) ?>" loading="lazy">
      </div>
      <div class="info">
        <div class="title"><?= htmlspecialchars($l['title']) ?></div>
        <div class="author"><?= htmlspecialchars($l['author']) ?></div>
        <?php if (!empty($l['isbn'])): ?>
        <div class="isbn">ISBN <?= htmlspecialchars($l['isbn']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Modal -->
<div class="modal-overlay" id="modal">
  <div class="modal">
    <button class="close" onclick="closeModal()">&times;</button>
    <div class="modal-cover" id="modal-cover"></div>
    <div class="modal-body">
      <h3 id="modal-title"></h3>
      <div class="meta" id="modal-meta"></div>
      <div class="synopsis" id="modal-synopsis"></div>
    </div>
  </div>
</div>

<script>
var librosData = <?= json_encode($libros, JSON_UNESCAPED_UNICODE) ?>;
function openDetail(i) {
    var l = librosData[i];
    document.getElementById('modal-cover').innerHTML = '<img src="' + l.image + '" alt="' + l.title + '">';
    document.getElementById('modal-title').textContent = l.title;
    document.getElementById('modal-meta').innerHTML = '<span>Autora: ' + l.author + '</span>' + (l.isbn ? '<span>ISBN: ' + l.isbn + '</span>' : '');
    document.getElementById('modal-synopsis').innerHTML = l.notes || 'Sinopsis no disponible.';
    document.getElementById('modal').classList.add('open');
}
function closeModal() {
    document.getElementById('modal').classList.remove('open');
}
document.getElementById('modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
</body>
</html>