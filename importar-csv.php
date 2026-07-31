<?php
/**
 * 📚 IMPORTAR CSV — Barrioteca Acalencá (por lotes)
 * 
 * ⚠️ SUBE ESTE ARCHIVO A /slims/importar-csv.php (NO a /barrioteca/)
 * Accede a: https://pelotxo.synology.me/slims/importar-csv.php
 * 
 * Permite subir un archivo CSV con ISBNs y los importa a SLiMS
 * por lotes de 3 libros para evitar timeout 504.
 * 
 * ELIMÍNALO del servidor cuando termines.
 */

// ═══════════════════════════════════════════════════════
// CONFIGURACIÓN
// ═══════════════════════════════════════════════════════
$BATCH_SIZE = 3;
$PENDING_FILE = __DIR__ . '/_slims_import_pending.json';
$RESULTS_FILE = __DIR__ . '/_slims_import_results.json';
$GOOGLE_BOOKS_API_KEY = '';

// Cargar clave de Google Books desde la PWA si existe
if (file_exists(__DIR__ . '/../barrioteca/api-config.php')) {
    require __DIR__ . '/../barrioteca/api-config.php';
    $GOOGLE_BOOKS_API_KEY = defined('GOOGLE_BOOKS_API_KEY') ? GOOGLE_BOOKS_API_KEY : '';
}
// ═══════════════════════════════════════════════════════

set_time_limit(30);
ini_set('display_errors', 0);

// Cargar SLiMS — ESTAMOS DENTRO DE /slims/
define('INDEX_AUTH', '1');
define('DB_ACCESS', 'fa');
$_SERVER['REQUEST_METHOD'] = 'GET';
require __DIR__ . '/sysconfig.inc.php';

// ═══════════════════════════════════════════════════════
// FUNCIONES
// ═══════════════════════════════════════════════════════

function httpGet($url, $timeout = 5) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Barrioteca-Import/1.0');
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp];
}

function googleBooksLookup($isbn, $apiKey = '') {
    $clean = preg_replace('/[^0-9X]/i', '', $isbn);
    if (strlen($clean) < 10) return null;
    $url = "https://www.googleapis.com/books/v1/volumes?q=isbn:{$clean}&langRestrict=es&country=ES";
    if ($apiKey) $url .= "&key={$apiKey}";
    list($code, $resp) = httpGet($url, 5);
    if ($code !== 200 || !$resp) return null;
    $data = json_decode($resp, true);
    if (empty($data['items'][0]['volumeInfo'])) return null;
    $v = $data['items'][0]['volumeInfo'];
    return [
        'title'        => $v['title'] ?? 'Sin título',
        'subtitle'     => $v['subtitle'] ?? '',
        'authors'      => $v['authors'] ?? [],
        'publisher'    => $v['publisher'] ?? '',
        'publish_year' => substr($v['publishedDate'] ?? '', 0, 4),
        'description'  => substr($v['description'] ?? '', 0, 2000),
        'pages'        => isset($v['pageCount']) ? $v['pageCount'] . ' p.' : '',
        'language'     => $v['language'] ?? 'es',
        'image_url'    => $v['imageLinks']['thumbnail'] ?? ($v['imageLinks']['smallThumbnail'] ?? ''),
        'source'       => 'Google Books (español)',
    ];
}

function openLibraryLookup($isbn) {
    $clean = preg_replace('/[^0-9X]/i', '', $isbn);
    if (strlen($clean) < 10) return null;
    list($code, $resp) = httpGet("https://openlibrary.org/isbn/{$clean}.json", 5);
    if ($code !== 200 || !$resp) return null;
    $data = json_decode($resp, true);
    if (empty($data['title'])) return null;

    $esTitle = $data['title'];
    $esDescription = '';
    if (!empty($data['works'][0]['key'])) {
        list($wc, $wr) = httpGet("https://openlibrary.org{$data['works'][0]['key']}/editions.json?limit=5", 5);
        if ($wc === 200) {
            $editions = json_decode($wr, true);
            if (!empty($editions['entries'])) {
                foreach ($editions['entries'] as $ed) {
                    $edLang = $ed['languages'][0]['key'] ?? '';
                    if (strpos($edLang, 'spa') !== false && !empty($ed['title'])) {
                        $esTitle = $ed['title'];
                        if (!empty($ed['description'])) $esDescription = is_string($ed['description']) ? $ed['description'] : ($ed['description']['value'] ?? '');
                        break;
                    }
                }
            }
        }
    }

    $authors = [];
    if (!empty($data['authors'])) {
        foreach (array_slice($data['authors'], 0, 3) as $ar) {
            $ak = $ar['key'] ?? '';
            if ($ak) { list($ac, $ar2) = httpGet("https://openlibrary.org{$ak}.json", 3); if ($ac === 200) { $ad = json_decode($ar2, true); $authors[] = $ad['personal_name'] ?? $ad['name'] ?? 'Autora Desconocida'; } }
        }
    }
    if (empty($authors) && isset($data['by_statement'])) $authors = [trim(str_replace(['by ','By '], '', $data['by_statement']))];
    if (empty($authors)) $authors = ['Autora Desconocida'];

    $pub = $data['publishers'][0] ?? '';
    $yr = $data['publish_date'] ?? ''; if (preg_match('/(\d{4})/', $yr, $m)) $yr = $m[1];
    $pg = isset($data['number_of_pages']) ? $data['number_of_pages'] . ' p.' : '';
    $image = "https://covers.openlibrary.org/b/isbn/{$clean}-M.jpg";

    return [
        'title' => $esTitle, 'subtitle' => $data['subtitle'] ?? '', 'authors' => $authors,
        'publisher' => $pub, 'publish_year' => $yr,
        'description' => substr($esDescription ?: ($data['description']['value'] ?? ''), 0, 2000),
        'pages' => $pg, 'language' => 'es', 'image_url' => $image, 'source' => 'Open Library',
    ];
}

function lookupISBN($isbn) {
    global $GOOGLE_BOOKS_API_KEY;
    $r = googleBooksLookup($isbn, $GOOGLE_BOOKS_API_KEY);
    if ($r) return $r;
    return openLibraryLookup($isbn);
}

function downloadCover($url, $isbn) {
    if (empty($url)) return null;
    $url = str_replace('http://', 'https://', $url);
    $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_TIMEOUT, 8); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $img = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($http !== 200 || strlen($img) < 1000) return null;
    $clean = preg_replace('/[^0-9X]/i', '', $isbn);
    $fn = 'isbn_' . $clean . '.jpg';
    $fp = IMGBS . 'docs' . DS . $fn;
    if (!is_dir(IMGBS . 'docs')) { mkdir(IMGBS . 'docs', 0775, true); }
    if (@file_put_contents($fp, $img)) return $fn;
    return null;
}

function insertBook($meta, $clean, $dbs) {
    $title = $dbs->real_escape_string($meta['title'] . ($meta['subtitle'] ? ' : ' . $meta['subtitle'] : ''));
    $yr = $meta['publish_year'] ? "'" . $dbs->real_escape_string($meta['publish_year']) . "'" : 'NULL';
    $desc = $dbs->real_escape_string($meta['description']);
    $pg = $dbs->real_escape_string($meta['pages']);
    $isbnEsc = $dbs->real_escape_string($clean);
    $pub = trim($dbs->real_escape_string($meta['publisher']));

    $publisherId = 'NULL';
    if (!empty($pub)) {
        $q = $dbs->query("SELECT publisher_id FROM mst_publisher WHERE publisher_name='$pub' LIMIT 1");
        if ($q && $q->num_rows > 0) { $r = $q->fetch_row(); $publisherId = (int)$r[0]; }
        else { $dbs->query("INSERT INTO mst_publisher (publisher_name, input_date, last_update) VALUES ('$pub', NOW(), NOW())"); $publisherId = (int)$dbs->insert_id; }
    }

    $sql = "INSERT INTO biblio (gmd_id, title, isbn_issn, publisher_id, publish_year, notes, collation, input_date, last_update, opac_hide)
            VALUES (1, '$title', '$isbnEsc', $publisherId, $yr, '$desc', '$pg', NOW(), NOW(), 0)";
    if (!$dbs->query($sql)) return false;
    $biblioId = (int)$dbs->insert_id;

    foreach ($meta['authors'] as $aname) {
        $an = trim($dbs->real_escape_string($aname));
        if (empty($an)) continue;
        $q = $dbs->query("SELECT author_id FROM mst_author WHERE author_name='$an' LIMIT 1");
        if ($q && $q->num_rows > 0) { $r = $q->fetch_row(); $aid = (int)$r[0]; }
        else { $dbs->query("INSERT INTO mst_author (author_name, input_date, last_update) VALUES ('$an', NOW(), NOW())"); $aid = (int)$dbs->insert_id; }
        $dbs->query("INSERT IGNORE INTO biblio_author (biblio_id, author_id, level) VALUES ($biblioId, $aid, 1)");
    }

    $coverName = downloadCover($meta['image_url'], $clean);
    if ($coverName) $dbs->query("UPDATE biblio SET image='" . $dbs->real_escape_string($coverName) . "' WHERE biblio_id=$biblioId");

    $itemCode = 'LIB-' . $biblioId;
    $dbs->query("INSERT INTO item (biblio_id, item_code, input_date, last_update) VALUES ($biblioId, '$itemCode', NOW(), NOW())");

    if (class_exists('biblio_indexer')) { $indexer = new biblio_indexer($dbs); $indexer->makeIndex($biblioId); }
    return $meta['title'];
}

// ═══════════════════════════════════════════════════════
// ACCIONES
// ═══════════════════════════════════════════════════════
$step = $_POST['step'] ?? 'upload';
$results = [];
$pendingCount = 0;

if ($step === 'upload' && !empty($_FILES['csv_file']['tmp_name'])) {
    $isbns = [];
    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
    if ($handle) {
        $headers = fgetcsv($handle);
        $textCol = array_search('text', array_map('strtolower', $headers));
        if ($textCol === false) $textCol = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $code = trim($row[$textCol] ?? '');
            $clean = preg_replace('/[^0-9X]/i', '', $code);
            if (strlen($clean) >= 10 && !in_array($clean, $isbns)) $isbns[] = $clean;
        }
        fclose($handle);
    }
    file_put_contents($PENDING_FILE, json_encode($isbns));
    file_put_contents($RESULTS_FILE, json_encode([]));
    $pendingCount = count($isbns);
    $step = 'batch';
}

if ($step === 'batch') {
    $results = json_decode(@file_get_contents($RESULTS_FILE), true) ?: [];
    $pending = json_decode(@file_get_contents($PENDING_FILE), true) ?: [];
    $pendingCount = count($pending);
    $batch = array_slice($pending, 0, $BATCH_SIZE);

    foreach ($batch as $isbn) {
        $clean = preg_replace('/[^0-9X]/i', '', $isbn);
        $status = ['isbn' => $clean, 'ok' => false, 'msg' => ''];

        $dup = $dbs->query("SELECT biblio_id FROM biblio WHERE isbn_issn='$clean' LIMIT 1");
        if ($dup && $dup->num_rows > 0) { $status['msg'] = 'Ya existe en SLiMS'; $status['ok'] = true; $results[] = $status; continue; }

        $meta = lookupISBN($clean);
        if (!$meta) { $status['msg'] = 'No encontrado'; $results[] = $status; continue; }

        $title = insertBook($meta, $clean, $dbs);
        if ($title) { $status['ok'] = true; $status['msg'] = $title . ' (' . $meta['source'] . ')'; }
        else { $status['msg'] = 'Error SQL: ' . $dbs->error; }
        $results[] = $status;
    }

    $pending = array_slice($pending, count($batch));
    $pendingCount = count($pending);
    file_put_contents($PENDING_FILE, json_encode($pending));
    file_put_contents($RESULTS_FILE, json_encode($results));
}

$okCount = count(array_filter($results, fn($r) => $r['ok'] && $r['msg'] !== 'Ya existe en SLiMS'));
$skipCount = count(array_filter($results, fn($r) => $r['msg'] === 'Ya existe en SLiMS'));
$errCount = count(array_filter($results, fn($r) => !$r['ok']));
$notFoundList = array_filter($results, fn($r) => $r['msg'] === 'No encontrado');
$notFoundIsbns = array_column($notFoundList, 'isbn');
$totalCount = count($results) + $pendingCount;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>📚 Importar CSV — Barrioteca Acalencá</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; background: #f5f5f0; color: #141414; padding: 20px; }
  .card { background: white; border-radius: 20px; padding: 24px; max-width: 800px; margin: 0 auto 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
  h1 { font-size: 1.5rem; margin-bottom: 4px; }
  h2 { font-size: 1.1rem; margin-bottom: 12px; }
  .sub { font-size: 0.75rem; color: #888; margin-bottom: 16px; }
  label { font-weight: 600; font-size: 0.9rem; display: block; margin-bottom: 6px; }
  input[type="file"] { width: 100%; padding: 12px; border: 2px dashed #ccc; border-radius: 12px; cursor: pointer; margin-bottom: 16px; font-size: 0.85rem; }
  button, .btn { background: #141414; color: #f5f5f0; border: none; padding: 12px 28px; border-radius: 12px; font-weight: 700; font-size: 0.9rem; cursor: pointer; text-transform: uppercase; letter-spacing: 0.05em; display: inline-block; text-decoration: none; }
  button:hover, .btn:hover { background: #333; }
  .btn-outline { background: white; color: #141414; border: 2px solid #141414; }
  .btn-outline:hover { background: #f5f5f0; }
  .btn-danger { background: #dc2626; color: white; margin-top: 12px; padding: 8px 16px; font-size: 0.8rem; }
  .result { padding: 10px 0; border-bottom: 1px solid #f0f0f0; display: flex; align-items: center; gap: 10px; font-size: 0.82rem; }
  .result:last-child { border-bottom: none; }
  .ok { color: #16a34a; } .fail { color: #dc2626; }
  .summary { text-align: center; padding: 16px; border-radius: 12px; font-weight: 600; margin: 16px 0; }
  .summary.ok { background: #ecfdf5; } .summary.mixed { background: #fefce8; }
  .progress-bar { width: 100%; height: 8px; background: #e5e7eb; border-radius: 4px; margin: 12px 0; overflow: hidden; }
  .progress-fill { height: 100%; background: #16a34a; border-radius: 4px; transition: width 0.3s; }
  .hint { background: #fefce8; border: 1px solid #fde047; border-radius: 8px; padding: 10px 14px; font-size: 0.78rem; color: #854d0e; margin-top: 12px; }
  .flex { display: flex; gap: 10px; flex-wrap: wrap; }
  .important { background: #fef2f2; border: 2px solid #dc2626; border-radius: 12px; padding: 16px 20px; margin-bottom: 20px; font-weight: 600; color: #991b1b; }
</style>
</head>
<body>

<?php if ($step === 'upload' && empty($_FILES['csv_file']['tmp_name'])): ?>
<div class="card">
  <h1>📚 Importar bibliografía desde CSV</h1>
  <p class="sub">Barrioteca Acalencá — Sube tu archivo CSV con ISBNs escaneados</p>
  
  <div class="important">
    ⚠️ Este script debe estar en <strong>/slims/importar-csv.php</strong>, no en /barrioteca/
  </div>

  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="step" value="upload">
    <label for="csv_file">Selecciona el archivo CSV:</label>
    <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
    <button type="submit">📤 Subir y comenzar</button>
  </form>
  <div class="hint">
    💡 El CSV debe tener una columna <strong>"text"</strong> con los ISBNs.<br>
    Se procesan <?= $BATCH_SIZE ?> libros por lote. Los duplicados se saltan.
  </div>
</div>

<?php elseif ($step === 'batch' || $step === 'upload'): ?>
<?php $pct = $totalCount > 0 ? round(($okCount + $skipCount + $errCount) / max($totalCount, 1) * 100) : 0; ?>
<div class="card">
  <h1>📚 Importar CSV — Lote procesado</h1>
  <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%"></div></div>
  <p class="sub"><?= $okCount + $skipCount + $errCount ?> de <?= $totalCount ?> · <?= $pct ?>% completado</p>

  <?php if (!empty($results)): ?>
  <h2>📋 Últimos resultados</h2>
  <?php foreach (array_slice($results, -$BATCH_SIZE) as $r): ?>
  <div class="result">
    <span class="<?= $r['ok'] ? 'ok' : 'fail' ?>"><?= $r['ok'] ? '✅' : '❌' ?></span>
    <span><strong><?= htmlspecialchars($r['isbn']) ?></strong> — <?= htmlspecialchars($r['msg']) ?></span>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($pendingCount > 0): ?>
  <div class="summary mixed">⏳ Quedan <strong><?= $pendingCount ?></strong> libros pendientes</div>
  <p class="sub" style="text-align:center;margin-top:8px;">⏱️ Procesando automáticamente el siguiente lote en 5 segundos...</p>
  <div class="progress-bar" style="margin:8px 0 16px;"><div id="auto-progress" class="progress-fill" style="width:100%;transition:width 5s linear;"></div></div>
  <script>
    setTimeout(function(){document.getElementById('auto-progress').style.width='0%';},50);
    setTimeout(function(){document.getElementById('autoForm').submit();}, 5000);
  </script>
  <form id="autoForm" method="POST" style="text-align:center;margin-top:12px;">
    <input type="hidden" name="step" value="batch">
    <button type="submit" class="btn-outline">▶️ Continuar ahora (<?= min($BATCH_SIZE, $pendingCount) ?> libros)</button>
  </form>
  <?php else: ?>
  <div class="summary ok">🎉 Importación completada · ✅ <?= $okCount ?> importados · ♻️ <?= $skipCount ?> ya existían · ❌ <?= $errCount ?> errores</div>

  <?php if (!empty($notFoundIsbns)): ?>
  <div class="card" style="margin-top:16px;">
    <h2>🔍 ISBNs no encontrados (<?= count($notFoundIsbns) ?>)</h2>
    <p class="sub" style="margin-bottom:8px;">Estos libros no se encontraron en Google Books ni Open Library. Debes añadirlos manualmente en SLiMS.</p>
    <div style="background:#f8f8f5;border-radius:8px;padding:12px;font-family:monospace;font-size:0.75rem;max-height:200px;overflow-y:auto;">
      <?php foreach ($notFoundIsbns as $isbn): ?>
      <div style="padding:3px 0;">📘 <?= htmlspecialchars($isbn) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <div class="flex" style="margin-top:12px;justify-content:space-between;">
    <?php if ($pendingCount > 0): ?>
    <a class="btn btn-outline" href="?restart=true" onclick="return confirm('¿Empezar de nuevo? Se perderá el progreso.')">↺ Reiniciar</a>
    <?php endif; ?>
    <a class="btn-danger" href="?delete=true" onclick="return confirm('¿Eliminar este script del servidor?')">🧹 Eliminar script (solo cuando hayas terminado)</a>
  </div>
  <?php if (isset($_GET['delete'])): @unlink(__FILE__); @unlink($PENDING_FILE); @unlink($RESULTS_FILE); ?>
  <p style="color:green;font-weight:bold;margin-top:12px;">✅ Script eliminado del servidor. Cierra esta página.</p>
  <?php endif; ?>
  <p class="sub" style="margin-top:12px;text-align:center;">💡 Puedes dejar este script en el servidor. No se ejecuta solo.</p>
</div>

<?php elseif (isset($_GET['restart'])): ?>
<?php @unlink($PENDING_FILE); @unlink($RESULTS_FILE); ?>
<div class="card"><h1>🔄 Reiniciado</h1><p>Vuelve a subir el archivo CSV.</p><a class="btn" href="?">Subir CSV</a></div>
<?php endif; ?>
</body>
</html>