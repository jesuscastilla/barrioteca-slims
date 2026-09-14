<?php
/**
 * ACTUALIZAR METADATOS — Barrioteca Acalencá
 * ==========================================
 * Actualiza portadas (biblio.image) y sinopsis (biblio.notes) de los libros
 * ya existentes consultando: Google Books -> OpenLibrary -> OpenLibrary Covers.
 *
 * SUBE ESTE ARCHIVO A /slims/actualizar-metadatos.php
 * Ejecuta por CLI:   php actualizar-metadatos.php           (real)
 *                    php actualizar-metadatos.php --dry-run (simulación)
 * Por navegador:     https://.../slims/actualizar-metadatos.php?dry=1
 *
 * ELIMÍNALO del servidor cuando termines.
 */

$DRY_RUN = (isset($argv[1]) && $argv[1] === '--dry-run') || isset($_GET['dry']);

set_time_limit(0);
ini_set('display_errors', 0);

// Clave de Google Books desde la PWA si existe
$GOOGLE_BOOKS_API_KEY = '';
if (file_exists(__DIR__ . '/../barrioteca/api-config.php')) {
    require __DIR__ . '/../barrioteca/api-config.php';
    $GOOGLE_BOOKS_API_KEY = defined('GOOGLE_BOOKS_API_KEY') ? GOOGLE_BOOKS_API_KEY : '';
}

// Conexión directa a la BD (evita el bootstrap completo de SLiMS en CLI)
define('DS', DIRECTORY_SEPARATOR);
define('IMGBS', __DIR__ . DS . 'images' . DS);

$dbConfig = require __DIR__ . '/config/database.php';
$nodes = $dbConfig['nodes'] ?? [];
$defaultProfile = $dbConfig['default_profile'] ?? 'SLiMS';
$node = $nodes[$defaultProfile] ?? (count($nodes) ? reset($nodes) : []);

$mysqli = new mysqli(
    $node['host'] ?? 'localhost',
    $node['username'] ?? '',
    $node['password'] ?? '',
    $node['database'] ?? 'acalenca',
    (int)($node['port'] ?? 3306)
);
if ($mysqli->connect_error) {
    die("ERROR de conexión a la BD: " . $mysqli->connect_error . "\n");
}
$mysqli->set_charset('utf8mb4');
$dbs = $mysqli;

function metaHttpGet($url, $timeout = 10) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Barrioteca-Metadata/1.0'
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp];
}

// Metadatos por ISBN: [title, authors, image, description, provider] o null
function metaLookup($isbn, $apiKey = '') {
    $clean = preg_replace('/[^0-9X]/i', '', $isbn);
    if (strlen($clean) < 10) return null;

    // 1) Google Books (con reintento por rate-limit 429)
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $url = "https://www.googleapis.com/books/v1/volumes?q=isbn:{$clean}";
        if ($apiKey) $url .= "&key={$apiKey}";
        list($code, $resp) = metaHttpGet($url, 8);
        if ($code === 200) {
            $data = json_decode($resp, true);
            if (!empty($data['items'][0]['volumeInfo'])) {
                $v = $data['items'][0]['volumeInfo'];
                return [
                    'title' => $v['title'] ?? null,
                    'authors' => !empty($v['authors']) ? implode(', ', $v['authors']) : null,
                    'image' => $v['imageLinks']['thumbnail'] ?? null,
                    'description' => $v['description'] ?? null,
                    'provider' => 'google'
                ];
            }
            break; // 200 pero sin resultados: ese ISBN no está en Google
        }
        if ($code === 429 && $attempt < 2) {
            usleep(1500000); // 1,5 s y reintentar
            continue;
        }
        break; // otro error: no reintentar
    }

    // 2) OpenLibrary
    $olUrl = "https://openlibrary.org/api/books?bibkeys=ISBN:{$clean}&format=json&jscmd=data";
    list($olCode, $olResp) = metaHttpGet($olUrl, 10);
    if ($olCode === 200) {
        $olData = json_decode($olResp, true);
        $olKey = "ISBN:{$clean}";
        if (!empty($olData[$olKey]) && is_array($olData[$olKey])) {
            $book = $olData[$olKey];
            $authors = null;
            if (!empty($book['authors']) && is_array($book['authors'])) {
                $authors = implode(', ', array_map(function ($a) { return $a['name'] ?? ''; }, $book['authors']));
            }
            return [
                'title' => $book['title'] ?? null,
                'authors' => $authors,
                'image' => $book['cover']['large'] ?? $book['cover']['medium'] ?? null,
                'description' => null,
                'provider' => 'openlibrary'
            ];
        }
    }

    return null;
}

// Descarga una portada a images/docs/ y devuelve el nombre de archivo (o null)
function metaSaveCover($url, $hash) {
    if (empty($url)) return null;
    $url = str_replace('http://', 'https://', $url);
    list($code, $img) = metaHttpGet($url, 12);
    if ($code !== 200 || strlen($img) < 5000) return null; // < 5 KB = placeholder
    $fn = 'cover_' . $hash . '.jpg';
    $dir = IMGBS . 'docs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (@file_put_contents($dir . DS . $fn, $img)) return $fn;
    return null;
}

// Portada por ISBN (OpenLibrary Covers) — comprueba que sea una imagen real
function metaCoverByIsbn($isbn) {
    $clean = preg_replace('/[^0-9X]/i', '', $isbn);
    $url = "https://covers.openlibrary.org/b/isbn/{$clean}-M.jpg";
    list($code, $img) = metaHttpGet($url, 10);
    if ($code === 200 && strlen($img) > 5000) return $img;
    return null;
}

// Portada por ISBN en CEGAL (todostuslibros / static.cegal.es) — convierte GIF a JPG
function metaCoverByCegal($isbn) {
    $clean = preg_replace('/[^0-9X]/i', '', $isbn);
    if (strlen($clean) < 13) return null;
    $dir = substr($clean, 0, 7);
    $file = substr($clean, 0, 12);
    $url = "https://static.cegal.es/imagenes/marcadas/{$dir}/{$file}.gif";
    list($code, $img) = metaHttpGet($url, 12);
    if ($code !== 200 || strlen($img) < 5000) return null; // < 5 KB = placeholder (3963 bytes)

    $tmp = tempnam(sys_get_temp_dir(), 'cegal') . '.gif';
    @file_put_contents($tmp, $img);
    $gd = @imagecreatefromgif($tmp);
    @unlink($tmp);
    if (!$gd) return null;

    $w = imagesx($gd);
    $h = imagesy($gd);
    $canvas = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopy($canvas, $gd, 0, 0, 0, 0, $w, $h);
    imagedestroy($gd);

    ob_start();
    imagejpeg($canvas, null, 85);
    $jpg = ob_get_clean();
    imagedestroy($canvas);

    return (strlen($jpg) > 3000) ? $jpg : null;
}

// Guarda bytes JPG en images/docs/ y devuelve el nombre de archivo (o null)
function metaSaveBytes($bytes, $hash) {
    if (empty($bytes) || strlen($bytes) < 3000) return null;
    $fn = 'cover_' . $hash . '.jpg';
    $dir = IMGBS . 'docs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (@file_put_contents($dir . DS . $fn, $bytes)) return $fn;
    return null;
}


echo ($DRY_RUN ? ">>> MODO SIMULACIÓN (dry-run)\n" : ">>> MODO REAL\n");

// Copia de seguridad de la tabla biblio (solo en modo real)
if (!$DRY_RUN) {
    $backupFile = __DIR__ . '/_backup_biblio_' . date('Ymd_His') . '.json';
    $rows = [];
    $r = $dbs->query("SELECT biblio_id, title, isbn_issn, image, notes FROM biblio");
    while ($x = $r->fetch_assoc()) $rows[] = $x;
    file_put_contents($backupFile, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "Backup creado: $backupFile (" . count($rows) . " filas)\n";
}


$q = $dbs->query("SELECT biblio_id, title, isbn_issn, image, notes FROM biblio WHERE opac_hide < 1 ORDER BY biblio_id");
$total = 0; $covers = 0; $notes = 0; $skip = 0;

while ($row = $q->fetch_assoc()) {
    $total++;
    usleep(250000); // ~250 ms entre libros para no saturar las APIs
    $bid = (int)$row['biblio_id'];
    $isbn = trim($row['isbn_issn'] ?? '');
    $image = trim($row['image'] ?? '');
    $notesTxt = trim($row['notes'] ?? '');

    $hasCover = ($image !== '' && $image !== 'images/default/image.png');
    $needNotes = ($notesTxt === '');

    if ($hasCover && !$needNotes) { $skip++; continue; }

    if ($isbn === '') { echo "  #$bid SIN ISBN (se omite)\n"; $skip++; continue; }

    $meta = metaLookup($isbn, $GOOGLE_BOOKS_API_KEY);

    $newImage = null;
    $newNotes = null;

    if (!$hasCover) {
        $hash = substr(md5($isbn), 0, 10);
        if ($meta && !empty($meta['image'])) {
            $fn = $DRY_RUN ? 'cover_' . $hash . '.jpg (SIM) [' . ($meta['provider'] ?? '') . ']' : metaSaveCover($meta['image'], $hash);
            if ($fn) $newImage = $fn;
        }
        if (!$newImage) {
            // CEGAL (todostuslibros / static.cegal.es) — portadas de libros españoles
            if ($DRY_RUN) {
                $newImage = 'cover_' . $hash . '.jpg (SIM, cegal)';
            } else {
                $bytes = metaCoverByCegal($isbn);
                if ($bytes) $newImage = metaSaveBytes($bytes, $hash);
            }
        }
        if (!$newImage) {
            // OpenLibrary Covers
            if ($DRY_RUN) {
                $newImage = 'cover_' . $hash . '.jpg (SIM, covers_openlibrary)';
            } else {
                $bytes = metaCoverByIsbn($isbn);
                if ($bytes) $newImage = metaSaveBytes($bytes, $hash);
            }
        }
    }

    if ($needNotes && $meta && !empty($meta['description'])) {
        $newNotes = substr(trim(strip_tags($meta['description'])), 0, 3000);
    }

    if ($newImage || $newNotes) {
        $updates = [];
        if ($newImage) { $updates[] = "image='" . $dbs->real_escape_string($newImage) . "'"; $covers++; }
        if ($newNotes) { $updates[] = "notes='" . $dbs->real_escape_string($newNotes) . "'"; $notes++; }
        $sql = "UPDATE biblio SET " . implode(', ', $updates) . " WHERE biblio_id=$bid";
        if ($DRY_RUN) {
            echo "  #$bid [$isbn] SIM: " . substr($sql, 0, 110) . "...\n";
        } else {
            echo "  #$bid [$isbn] " . ($dbs->query($sql) ? "OK" : "ERROR: " . $dbs->error) . "\n";
        }
        usleep(120000);
    } else {
        echo "  #$bid [$isbn] sin metadatos disponibles\n";
    }
}

echo "\n=== RESUMEN ===\n";
echo "Total revisados: $total\n";
echo "Portadas a actualizar: $covers\n";
echo "Sinopsis a actualizar: $notes\n";
echo "Sin cambios: $skip\n";

