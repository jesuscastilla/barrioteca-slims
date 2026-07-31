<?php
/**
 * Añadir Libro sin ISBN - Barrioteca Acalenca
 * 
 * ATENCION: Sube este archivo a /slims/anadir-libro.php (NO a /barrioteca/)
 * Accede a: https://pelotxo.synology.me/slims/anadir-libro.php
 * 
 * Permite añadir libros sin ISBN buscando por titulo/autor en APIs
 * o introduciendo los datos manualmente.
 * 
 * Eliminalo del servidor cuando termines.
 */

// ==========================================================
// CONFIGURACION
// ==========================================================
$GOOGLE_BOOKS_API_KEY = '';

// Cargar clave de Google Books desde la PWA si existe
if (file_exists(__DIR__ . '/../barrioteca/api-config.php')) {
    require __DIR__ . '/../barrioteca/api-config.php';
    $GOOGLE_BOOKS_API_KEY = defined('GOOGLE_BOOKS_API_KEY') ? GOOGLE_BOOKS_API_KEY : '';
}
// ==========================================================

set_time_limit(30);
ini_set('display_errors', 0);

// Cargar SLiMS
define('INDEX_AUTH', '1');
define('DB_ACCESS', 'fa');
$_SERVER['REQUEST_METHOD'] = 'GET';
require __DIR__ . '/sysconfig.inc.php';

// ==========================================================
// FUNCIONES
// ==========================================================

function httpGet($url, $timeout = 8) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp];
}

function searchOpenLibrary($query, $author = '') {
    $q = urlencode($query);
    if (!empty($author)) $q .= '+inauthor:' . urlencode($author);
    $url = "https://openlibrary.org/search.json?q={$q}&language=spa&limit=5";
    list($code, $resp) = httpGet($url, 8);
    if ($code !== 200 || !$resp) return [];
    $data = json_decode($resp, true);
    if (empty($data['docs'])) return [];
    
    $results = [];
    foreach (array_slice($data['docs'], 0, 5) as $doc) {
        $title = $doc['title'] ?? 'Sin titulo';
        if (!empty($doc['subtitle'])) $title .= ' : ' . $doc['subtitle'];
        
        $authors = [];
        if (!empty($doc['author_name'])) $authors = array_slice($doc['author_name'], 0, 3);
        
        $coverId = $doc['cover_i'] ?? 0;
        $imageUrl = $coverId > 0 ? "https://covers.openlibrary.org/b/id/{$coverId}-M.jpg" : '';
        
        $results[] = [
            'title'        => $title,
            'authors'      => $authors,
            'publisher'    => $doc['publisher'][0] ?? '',
            'publish_year' => $doc['first_publish_year'] ?? '',
            'description'  => '',
            'pages'        => isset($doc['number_of_pages_median']) ? $doc['number_of_pages_median'] . ' p.' : '',
            'image_url'    => $imageUrl,
            'source'       => 'Open Library',
            'ol_key'       => $doc['key'] ?? '',
        ];
    }
    return $results;
}

function searchGoogleBooks($query, $author = '', $apiKey = '') {
    $q = 'intitle:' . urlencode($query);
    if (!empty($author)) $q .= '+inauthor:' . urlencode($author);
    $url = "https://www.googleapis.com/books/v1/volumes?q={$q}&langRestrict=es&country=ES&maxResults=5";
    if ($apiKey) $url .= "&key={$apiKey}";
    list($code, $resp) = httpGet($url, 8);
    if ($code !== 200 || !$resp) return [];
    $data = json_decode($resp, true);
    if (empty($data['items'])) return [];
    
    $results = [];
    foreach ($data['items'] as $item) {
        $v = $item['volumeInfo'] ?? [];
        $results[] = [
            'title'        => $v['title'] ?? 'Sin titulo',
            'authors'      => $v['authors'] ?? [],
            'publisher'    => $v['publisher'] ?? '',
            'publish_year' => substr($v['publishedDate'] ?? '', 0, 4),
            'description'  => substr($v['description'] ?? '', 0, 3000),
            'pages'        => isset($v['pageCount']) ? $v['pageCount'] . ' p.' : '',
            'image_url'    => $v['imageLinks']['thumbnail'] ?? ($v['imageLinks']['smallThumbnail'] ?? ''),
            'source'       => 'Google Books',
        ];
    }
    return $results;
}

function getOLDescription($olKey) {
    if (empty($olKey)) return '';
    list($code, $resp) = httpGet("https://openlibrary.org{$olKey}.json", 5);
    if ($code !== 200 || !$resp) return '';
    $data = json_decode($resp, true);
    $desc = $data['description'] ?? '';
    if (is_array($desc)) $desc = $desc['value'] ?? '';
    if (is_array($desc)) $desc = '';
    return substr(strip_tags($desc), 0, 3000);
}

function downloadCover($url, $filename) {
    if (empty($url)) return null;
    // Limpiar filename
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename);
    $url = str_replace('http://', 'https://', $url);
    $ch = curl_init($url); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 8); 
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $img = curl_exec($ch); 
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
    curl_close($ch);
    if ($http !== 200 || strlen($img) < 1000) return null;
    $fn = 'cover_' . $filename . '.jpg';
    $fp = IMGBS . 'docs' . DS . $fn;
    if (!is_dir(IMGBS . 'docs')) { mkdir(IMGBS . 'docs', 0775, true); }
    if (@file_put_contents($fp, $img)) return $fn;
    return null;
}

function insertBook($meta, $dbs) {
    $title = $dbs->real_escape_string($meta['title']);
    $yr = !empty($meta['publish_year']) ? "'" . $dbs->real_escape_string($meta['publish_year']) . "'" : 'NULL';
    $desc = $dbs->real_escape_string($meta['description']);
    $pg = $dbs->real_escape_string($meta['pages']);
    $isbnEsc = empty($meta['isbn']) ? 'NULL' : "'" . $dbs->real_escape_string($meta['isbn']) . "'";
    $pub = trim($dbs->real_escape_string($meta['publisher']));

    $publisherId = 'NULL';
    if (!empty($pub)) {
        $q = $dbs->query("SELECT publisher_id FROM mst_publisher WHERE publisher_name='$pub' LIMIT 1");
        if ($q && $q->num_rows > 0) { $r = $q->fetch_row(); $publisherId = (int)$r[0]; }
        else { $dbs->query("INSERT INTO mst_publisher (publisher_name, input_date, last_update) VALUES ('$pub', NOW(), NOW())"); $publisherId = (int)$dbs->insert_id; }
    }

    $sql = "INSERT INTO biblio (gmd_id, title, isbn_issn, publisher_id, publish_year, notes, collation, input_date, last_update, opac_hide)
            VALUES (1, '$title', $isbnEsc, $publisherId, $yr, '$desc', '$pg', NOW(), NOW(), 0)";
    if (!$dbs->query($sql)) return ['ok' => false, 'msg' => 'Error SQL: ' . $dbs->error];
    $biblioId = (int)$dbs->insert_id;

    foreach ($meta['authors'] as $aname) {
        $an = trim($dbs->real_escape_string($aname));
        if (empty($an)) continue;
        $q = $dbs->query("SELECT author_id FROM mst_author WHERE author_name='$an' LIMIT 1");
        if ($q && $q->num_rows > 0) { $r = $q->fetch_row(); $aid = (int)$r[0]; }
        else { $dbs->query("INSERT INTO mst_author (author_name, input_date, last_update) VALUES ('$an', NOW(), NOW())"); $aid = (int)$dbs->insert_id; }
        $dbs->query("INSERT IGNORE INTO biblio_author (biblio_id, author_id, level) VALUES ($biblioId, $aid, 1)");
    }

    // Descargar portada
    $coverHash = substr(md5($meta['title']), 0, 10);
    $coverName = downloadCover($meta['image_url'], $coverHash);
    if ($coverName) $dbs->query("UPDATE biblio SET image='" . $dbs->real_escape_string($coverName) . "' WHERE biblio_id=$biblioId");

    // Crear item
    $itemCode = 'LIB-' . $biblioId;
    $dbs->query("INSERT INTO item (biblio_id, item_code, input_date, last_update) VALUES ($biblioId, '$itemCode', NOW(), NOW())");

    // Indexar
    if (class_exists('biblio_indexer')) { $indexer = new biblio_indexer($dbs); $indexer->makeIndex($biblioId); }
    return ['ok' => true, 'msg' => "Libro '{$meta['title']}' añadido correctamente (ID: {$biblioId})"];
}

// ==========================================================
// ACCIONES
// ==========================================================
$step = $_POST['step'] ?? ($_GET['step'] ?? 'search');
$searchResults = [];
$message = '';
$messageType = '';
$formData = [
    'title' => '', 'authors' => '', 'publisher' => '', 'publish_year' => '',
    'pages' => '', 'isbn' => '', 'description' => '', 'image_url' => ''
];
$savedBiblioId = 0;
$savedItemCode = '';
$savedTitle = '';

// 1. Búsqueda
if ($step === 'search' && !empty($_POST['title'])) {
    $title = trim($_POST['title']);
    $author = trim($_POST['author'] ?? '');
    $formData['title'] = htmlspecialchars($title);
    $formData['authors'] = htmlspecialchars($author);
    
    $sr1 = searchOpenLibrary($title, $author);
    $sr2 = searchGoogleBooks($title, $author, $GOOGLE_BOOKS_API_KEY);
    
    // Combinar y deduplicar
    $seenTitles = [];
    $searchResults = [];
    foreach (array_merge($sr2, $sr1) as $r) {
        $key = mb_strtolower(trim($r['title']));
        if (in_array($key, $seenTitles)) continue;
        $seenTitles[] = $key;
        
        // Intentar obtener descripcion de Open Library
        if (empty($r['description']) && !empty($r['ol_key'])) {
            $r['description'] = getOLDescription($r['ol_key']);
        }
        
        $searchResults[] = $r;
    }
    $searchResults = array_slice($searchResults, 0, 5);
    
    if (empty($searchResults)) {
        $message = 'No se encontraron resultados en las APIs. Puedes añadir el libro manualmente.';
        $messageType = 'info';
        $step = 'manual';
    }
}

// 2. Seleccionar un resultado de búsqueda
if ($step === 'select' && isset($_POST['index'])) {
    $idx = (int)$_POST['index'];
    $allResults = json_decode($_POST['results_json'] ?? '[]', true);
    if (isset($allResults[$idx])) {
        $r = $allResults[$idx];
        $formData = [
            'title'        => htmlspecialchars($r['title']),
            'authors'      => htmlspecialchars(implode('; ', $r['authors'])),
            'publisher'    => htmlspecialchars($r['publisher']),
            'publish_year' => htmlspecialchars($r['publish_year']),
            'pages'        => htmlspecialchars($r['pages']),
            'description'  => htmlspecialchars($r['description']),
            'image_url'    => htmlspecialchars($r['image_url']),
            'isbn'         => '',
            'source'       => $r['source'] ?? '',
        ];
        $step = 'manual';
    }
}

// 3. Guardar manualmente
if ($step === 'save' && !empty($_POST['title'])) {
    $meta = [
        'title'        => trim($_POST['title']),
        'authors'      => array_map('trim', explode(';', $_POST['authors'] ?? '')),
        'publisher'    => trim($_POST['publisher'] ?? ''),
        'publish_year' => trim($_POST['publish_year'] ?? ''),
        'pages'        => trim($_POST['pages'] ?? ''),
        'description'  => trim($_POST['description'] ?? ''),
        'image_url'    => trim($_POST['image_url'] ?? ''),
        'isbn'         => trim($_POST['isbn'] ?? ''),
    ];
    // Filtrar autores vacios
    $meta['authors'] = array_filter($meta['authors'], function($a) { return !empty($a); });
    if (empty($meta['authors'])) $meta['authors'] = ['Autora Desconocida'];
    
    if (empty($meta['title'])) {
        $message = 'El titulo es obligatorio.';
        $messageType = 'error';
        $formData = array_map('htmlspecialchars', $meta);
        $formData['authors'] = htmlspecialchars(implode('; ', $meta['authors']));
        $step = 'manual';
    } else {
        $result = insertBook($meta, $dbs);
        $message = $result['msg'];
        $messageType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            // Extraer el biblio_id del mensaje
            if (preg_match('/ID: (\d+)/', $result['msg'], $m)) {
                $savedBiblioId = (int)$m[1];
                $savedItemCode = 'LIB-' . $savedBiblioId;
                $savedTitle = $meta['title'];
            }
            $step = 'done';
        } else {
            $formData = array_map('htmlspecialchars', $meta);
            $formData['authors'] = htmlspecialchars(implode('; ', $meta['authors']));
            $step = 'manual';
        }
    }
}

// Si viene de search con resultados y no hay accion
if ($step === 'search' && empty($searchResults) && empty($message)) {
    // mostrar formulario de busqueda
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Añadir Libro - Barrioteca Acalenca</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, -apple-system, sans-serif; background: #f5f5f0; color: #141414; padding: 20px; }
  .card { background: white; border-radius: 20px; padding: 24px; max-width: 800px; margin: 0 auto 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
  h1 { font-size: 1.5rem; margin-bottom: 4px; }
  h2 { font-size: 1.1rem; margin-bottom: 12px; color: #555; }
  .sub { font-size: 0.75rem; color: #888; margin-bottom: 16px; }
  label { font-weight: 600; font-size: 0.88rem; display: block; margin-bottom: 4px; color: #333; }
  input[type="text"], input[type="url"], textarea, select { 
    width: 100%; padding: 10px 12px; border: 1.5px solid #ddd; border-radius: 10px; 
    font-size: 0.9rem; font-family: inherit; margin-bottom: 12px; 
    transition: border-color 0.2s; background: #fafafa;
  }
  input:focus, textarea:focus { outline: none; border-color: #141414; background: white; }
  textarea { resize: vertical; min-height: 80px; }
  button, .btn { 
    background: #141414; color: #f5f5f0; border: none; padding: 12px 28px; 
    border-radius: 12px; font-weight: 700; font-size: 0.9rem; cursor: pointer; 
    text-transform: uppercase; letter-spacing: 0.05em; display: inline-block; 
    text-decoration: none; transition: background 0.2s;
  }
  button:hover, .btn:hover { background: #333; }
  .btn-outline { background: white; color: #141414; border: 2px solid #141414; }
  .btn-outline:hover { background: #f5f5f0; }
  .btn-small { padding: 6px 14px; font-size: 0.75rem; }
  .flex { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
  .flex2 { display: flex; gap: 10px; }
  .flex2 > *:first-child { flex: 1; }
  .result-card { 
    border: 1.5px solid #eee; border-radius: 14px; padding: 16px; margin-bottom: 10px;
    display: flex; gap: 16px; align-items: flex-start; transition: border-color 0.2s;
  }
  .result-card:hover { border-color: #141414; }
  .result-card img { width: 60px; height: 90px; object-fit: cover; border-radius: 6px; background: #f0f0f0; flex-shrink: 0; }
  .result-card .info { flex: 1; min-width: 0; }
  .result-card .info h3 { font-size: 0.95rem; margin-bottom: 4px; }
  .result-card .info p { font-size: 0.8rem; color: #666; margin: 2px 0; }
  .result-card .info .source { font-size: 0.65rem; color: #999; text-transform: uppercase; letter-spacing: 0.05em; }
  .message { padding: 12px 16px; border-radius: 12px; margin-bottom: 16px; font-weight: 600; font-size: 0.88rem; }
  .message.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
  .message.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .message.info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
  .cover-preview { max-width: 200px; max-height: 300px; border-radius: 10px; margin-top: 8px; display: block; }
  .cover-preview[src=""], .cover-preview:not([src]) { display: none; }
  .hint { background: #fefce8; border: 1px solid #fde047; border-radius: 8px; padding: 10px 14px; font-size: 0.78rem; color: #854d0e; margin: 12px 0; }
  .important { background: #fef2f2; border: 2px solid #dc2626; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; font-weight: 600; color: #991b1b; }
  hr { border: none; border-top: 1px solid #eee; margin: 20px 0; }
  .label-card { text-align:center; border:2px dashed #ddd; border-radius:16px; padding:20px 24px; max-width:380px; margin:16px auto; background:#fafafa; }
  .label-header { font-size: 10px; font-weight:700; text-transform:uppercase; letter-spacing:0.1em; color:#999; margin-bottom:6px; }
  .label-title { font-size:14px; font-weight:700; line-height:1.3; margin-bottom:12px; }
  .label-barcode { display:block; margin:0 auto; }
  .label-code { font-family: 'Courier New', monospace; font-size: 15px; font-weight:700; letter-spacing:0.15em; margin-top:4px; color:#333; }
</style>
</head>
<body>

<?php if ($step === 'search' || (isset($_GET['step']) && $_GET['step'] === 'search')): ?>
<div class="card">
  <h1>Añadir libro sin ISBN</h1>
  <p class="sub">Barrioteca Acalenca - Busca por titulo o introduce los datos manualmente</p>
  
  <div class="important">
    Este script debe estar en /slims/anadir-libro.php, no en /barrioteca/
  </div>

  <?php if ($message): ?>
  <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="step" value="search">
    
    <label for="title">Titulo del libro (*)</label>
    <input type="text" name="title" id="title" value="<?= $formData['title'] ?>" required placeholder="Ej: Cien anos de soledad">
    
    <label for="author">Autora (opcional, ayuda a afinar la busqueda)</label>
    <input type="text" name="author" id="author" value="<?= $formData['authors'] ?>" placeholder="Ej: Gabriel Garcia Marquez">
    
    <div class="flex" style="margin-top: 8px;">
      <button type="submit">Buscar en APIs</button>
      <button type="button" class="btn-outline" onclick="document.getElementById('manualForm').submit();">Saltar a formulario manual</button>
    </div>
  </form>

  <form id="manualForm" method="POST" style="display:none;">
    <input type="hidden" name="step" value="manual">
    <input type="hidden" name="title" value="<?= $formData['title'] ?>">
    <input type="hidden" name="authors" value="<?= $formData['authors'] ?>">
  </form>

  <div class="hint">
    Las busquedas se hacen en Open Library y Google Books, priorizando resultados en espanol.
  </div>
</div>

<?php elseif ($step === 'search' && !empty($searchResults)): ?>
<div class="card">
  <h1>Resultados de la busqueda</h1>
  <p class="sub">"<?= htmlspecialchars($_POST['title']) ?>" - <?= count($searchResults) ?> resultado(s) encontrado(s)</p>

  <form method="POST">
    <input type="hidden" name="step" value="select">
    <input type="hidden" name="results_json" value="<?= htmlspecialchars(json_encode($searchResults)) ?>">
    
    <?php foreach ($searchResults as $i => $r): ?>
    <div class="result-card" onclick="this.querySelector('button').click();" style="cursor:pointer;">
      <?php $img = !empty($r['image_url']) ? htmlspecialchars($r['image_url']) : ''; ?>
      <?php if ($img): ?><img src="<?= $img ?>" alt="Portada" onerror="this.style.display='none'"><?php endif; ?>
      <div class="info">
        <h3><?= htmlspecialchars($r['title']) ?></h3>
        <?php if (!empty($r['authors'])): ?><p>Autoras: <?= htmlspecialchars(implode(', ', $r['authors'])) ?></p><?php endif; ?>
        <?php if (!empty($r['publisher'])): ?><p>Editorial: <?= htmlspecialchars($r['publisher']) ?> <?= !empty($r['publish_year']) ? '(' . htmlspecialchars($r['publish_year']) . ')' : '' ?></p><?php endif; ?>
        <?php if (!empty($r['pages'])): ?><p><?= htmlspecialchars($r['pages']) ?></p><?php endif; ?>
        <p class="source">Fuente: <?= htmlspecialchars($r['source']) ?></p>
        <?php if (!empty($r['description'])): ?>
        <p style="margin-top:6px;font-size:0.78rem;color:#555;line-height:1.4;">
          <?= htmlspecialchars(mb_substr(strip_tags($r['description']), 0, 250)) ?>...
        </p>
        <?php endif; ?>
      </div>
      <button type="submit" name="index" value="<?= $i ?>" class="btn-small" style="flex-shrink:0;align-self:center;">Seleccionar</button>
    </div>
    <?php endforeach; ?>
  </form>

  <hr>
  
  <form method="POST">
    <input type="hidden" name="step" value="manual">
    <input type="hidden" name="title" value="<?= htmlspecialchars($_POST['title']) ?>">
    <input type="hidden" name="authors" value="<?= htmlspecialchars($_POST['author'] ?? '') ?>">
    <button type="submit" class="btn-outline">O anadir manualmente (sin usar estos resultados)</button>
  </form>
</div>

<?php elseif ($step === 'manual'): ?>
<div class="card">
  <h1><?= empty($formData['source']) ? 'Añadir libro manualmente' : 'Editar datos antes de guardar' ?></h1>
  <p class="sub"><?= empty($formData['source']) ? 'Rellena los campos con la informacion del libro' : 'Datos obtenidos de: ' . htmlspecialchars($formData['source']) . '. Revisalos antes de guardar.' ?></p>

  <?php if ($message): ?>
  <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="step" value="save">
    
    <label for="m_title">Titulo (*)</label>
    <input type="text" name="title" id="m_title" value="<?= $formData['title'] ?>" required>
    
    <label for="m_authors">Autoras (separadas por ;)</label>
    <input type="text" name="authors" id="m_authors" value="<?= $formData['authors'] ?>" placeholder="Ej: Gabriel Garcia Marquez; Isabel Allende">
    
    <div class="flex2">
      <div><label for="m_publisher">Editorial</label>
      <input type="text" name="publisher" id="m_publisher" value="<?= $formData['publisher'] ?>"></div>
      <div><label for="m_year">Ano</label>
      <input type="text" name="publish_year" id="m_year" value="<?= $formData['publish_year'] ?>" style="width:100px;"></div>
      <div><label for="m_pages">Pags.</label>
      <input type="text" name="pages" id="m_pages" value="<?= $formData['pages'] ?>" style="width:80px;"></div>
      <div><label for="m_isbn">ISBN (si lo tiene)</label>
      <input type="text" name="isbn" id="m_isbn" value="<?= $formData['isbn'] ?>" style="width:140px;"></div>
    </div>
    
    <label for="m_desc">Sinopsis / Descripcion</label>
    <textarea name="description" id="m_desc" rows="6"><?= $formData['description'] ?></textarea>
    
    <label for="m_image">URL de la portada</label>
    <input type="url" name="image_url" id="m_image" value="<?= $formData['image_url'] ?>" placeholder="https://...">
    <img id="coverPreview" class="cover-preview" src="<?= $formData['image_url'] ?>" alt="Preview" onerror="this.style.display='none'" onload="this.style.display='block'">
    
    <script>
    document.getElementById('m_image').addEventListener('input', function() {
      var p = document.getElementById('coverPreview');
      p.src = this.value;
      p.onload = function() { p.style.display = 'block'; };
      p.onerror = function() { p.style.display = 'none'; };
    });
    </script>

    <div class="flex" style="margin-top: 16px;">
      <button type="submit">Guardar en la biblioteca</button>
      <a href="?" class="btn-outline" style="text-decoration:none;">Cancelar / Volver</a>
    </div>
  </form>
</div>

<?php elseif ($step === 'done'): ?>
<div class="card" style="text-align:center;">
  <h1 style="font-size:2rem;color:#16a34a;">Libro anadido</h1>
  <div class="message success"><?= htmlspecialchars($message) ?></div>
  <p class="sub" style="margin: 16px 0;">El libro ya esta disponible en el catalogo de la Barrioteca.</p>

  <?php if ($savedBiblioId > 0): ?>
  <div class="label-sheet" id="labelSheet">
    <div class="label-card">
      <div class="label-header">Barrioteca Acalenca</div>
      <div class="label-title"><?= htmlspecialchars(mb_substr($savedTitle, 0, 60)) ?></div>
      <svg id="barcodeSvg" class="label-barcode"></svg>
      <div class="label-code"><?= htmlspecialchars($savedItemCode) ?></div>
    </div>
  </div>
  <p class="sub" style="margin:12px 0 8px;">Escanea este codigo de barras con la PWA para prestar/devolver el libro.</p>
  <p class="sub" style="margin-bottom:16px;">Imprime la etiqueta y pegala en el libro.</p>
  <button class="btn" onclick="printLabel()" style="margin-right:10px;">Imprimir etiqueta</button>
  <?php endif; ?>

  <div class="flex" style="justify-content:center;margin-top:16px;">
    <a href="?" class="btn-outline" style="text-decoration:none;">Añadir otro libro</a>
    <a href="/slims/" class="btn-outline" style="text-decoration:none;">Ver catalogo</a>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
<?php if ($savedBiblioId > 0): ?>
try {
  JsBarcode('#barcodeSvg', '<?= htmlspecialchars($savedItemCode) ?>', {
    format: 'CODE128',
    width: 2,
    height: 60,
    displayValue: true,
    fontSize: 14,
    textMargin: 6,
    margin: 8,
    background: '#ffffff',
    lineColor: '#000000'
  });
} catch(e) { console.error('Error generando codigo de barras:', e); }

function printLabel() {
  var label = document.getElementById('labelSheet');
  var win = window.open('', '_blank', 'width=400,height=400');
  win.document.write('<html><head><title>Etiqueta - Barrioteca</title><style>' +
    '* { margin:0; padding:0; box-sizing:border-box; }' +
    'body { font-family: system-ui, sans-serif; display:flex; align-items:center; justify-content:center; min-height:100vh; }' +
    '.label-sheet { padding: 20px; }' +
    '.label-card { text-align:center; border:2px dashed #ccc; border-radius:16px; padding:20px 24px; max-width:350px; }' +
    '.label-header { font-size: 10px; font-weight:700; text-transform:uppercase; letter-spacing:0.1em; color:#999; margin-bottom:6px; }' +
    '.label-title { font-size:13px; font-weight:700; line-height:1.3; margin-bottom:10px; }' +
    '.label-barcode { display:block; margin:0 auto; }' +
    '.label-code { font-family: monospace; font-size: 13px; font-weight:700; letter-spacing:0.1em; margin-top:2px; }' +
    '</style></head><body>' + label.outerHTML + '</body></html>');
  win.document.close();
  setTimeout(function() { win.print(); win.close(); }, 500);
}
<?php endif; ?>
</script>
<?php endif; ?>

</body>
</html>