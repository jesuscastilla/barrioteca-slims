<?php
/**
 * ISBN Lookup - Catalogador automático por ISBN
 * 
 * Busca datos bibliográficos en múltiples fuentes (Google Books, Open Library,
 * Biblioteca Nacional de España) y permite importarlos directamente a SLiMS.
 * 
 * No requiere php-yaz ni extensiones Z39.50. Funciona mediante APIs REST/HTTP.
 * 
 * @author      Barrioteca / SLiMS Community
 * @copyright   2024
 * @license     GPL-3.0-or-later
 * @version     1.0.0
 */

// key to authenticate
define('INDEX_AUTH', '1');
// key to get full database access
define('DB_ACCESS', 'fa');

if (!isset($errors)) {
    $errors = false;
}

// start the session
require '../../../sysconfig.inc.php';
// IP based access limitation
require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-bibliography');
require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';
require SIMBIO . 'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO . 'simbio_GUI/paging/simbio_paging.inc.php';
require SIMBIO . 'simbio_DB/simbio_dbop.inc.php';
require MDLBS . 'system/biblio_indexer.inc.php';

// privileges checking
$can_read = utility::havePrivilege('bibliography', 'r');
$can_write = utility::havePrivilege('bibliography', 'w');

if (!$can_read) {
    die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');
}

# CHECK ACCESS
if ($_SESSION['uid'] != 1) {
    if (!utility::haveAccess('bibliography.isbn-lookup')) {
        die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');
    }
}

// ============================================================================
// FUNCIONES DE CONSULTA A APIs
// ============================================================================

/**
 * Realiza una petición HTTP GET con manejo de errores.
 * 
 * Primero intenta con file_get_contents(). Si falla (por ejemplo si
 * allow_url_fopen está desactivado en el NAS), intenta automáticamente
 * con cURL como fallback.
 */
function isbn_http_get($url, $timeout = 15)
{
    $response = null;

    // --- Intento 1: file_get_contents (solo si hay wrapper HTTPS) ---
    if (in_array('https', stream_get_wrappers())) {
        $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\nUser-Agent: SLiMS-ISBN-Lookup/1.0\r\n",
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

        $response = @file_get_contents($url, false, $context);
        if ($response !== false) {
            return $response;
        }
    }

    // --- Intento 2: cURL (fallback) ---
    if (function_exists('curl_version')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'SLiMS-ISBN-Lookup/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $response = @curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response !== false && $httpCode >= 200 && $httpCode < 400) {
            return $response;
        }
    }

    return null;
}

/**
 * Clave de API de Google Books (opcional pero recomendada)
 * Se pasa como parámetro key=API_KEY en la URL.
 * Obtén tu clave en: https://console.cloud.google.com/apis/library/books.googleapis.com
 * Sin API Key, Google Books tiene un límite muy bajo de peticiones (HTTP 429).
 * 
 * ⚠️ No hardcodees la clave aquí. Una forma más segura es definirla como variable
 * de entorno GOOGLE_BOOKS_API_KEY en el servidor (Apache/Nginx), o crea un archivo
 * .env en la raíz de SLiMS con: GOOGLE_BOOKS_API_KEY=tu_clave
 */
$google_api_key = getenv('GOOGLE_BOOKS_API_KEY');
if (empty($google_api_key)) {
    // Fallback: intentar cargar desde archivo .env en la raíz
    $envFile = __DIR__ . '/../../../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), 'GOOGLE_BOOKS_API_KEY=') === 0) {
                $google_api_key = trim(substr($line, strpos($line, '=') + 1));
                break;
            }
        }
    }
}
define('GOOGLE_BOOKS_API_KEY', $google_api_key ?: '');

/**
 * Consulta Google Books API por ISBN
 * https://developers.google.com/books/docs/v1/using
 */
function queryGoogleBooks($isbn)
{
    $isbn = preg_replace('/[^0-9X]/i', '', $isbn);
    $url = 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . urlencode($isbn) . '&langRestrict=es&maxResults=5';
    if (defined('GOOGLE_BOOKS_API_KEY') && GOOGLE_BOOKS_API_KEY !== '') {
        $url .= '&key=' . GOOGLE_BOOKS_API_KEY;
    }

    $response = isbn_http_get($url);
    if (!$response) return [];

    $data = json_decode($response, true);
    if (!isset($data['totalItems']) || $data['totalItems'] < 1) return [];

    $results = [];
    foreach ($data['items'] as $item) {
        $vol = $item['volumeInfo'] ?? [];
        $result = [
            'source' => 'Google Books',
            'title' => $vol['title'] ?? '',
            'subtitle' => $vol['subtitle'] ?? '',
            'authors' => $vol['authors'] ?? [],
            'publisher' => $vol['publisher'] ?? '',
            'publish_year' => isset($vol['publishedDate']) ? substr($vol['publishedDate'], 0, 4) : '',
            'publish_place' => '',
            'isbn' => $isbn,
            'pages' => isset($vol['pageCount']) ? $vol['pageCount'] . ' p.' : '',
            'language' => $vol['language'] ?? 'spa',
            'description' => $vol['description'] ?? '',
            'categories' => $vol['categories'] ?? [],
            'image_url' => '',
            'edition' => '',
            'classification' => '',
        ];

        // Extraer ISBN-13 si está disponible
        if (isset($vol['industryIdentifiers'])) {
            foreach ($vol['industryIdentifiers'] as $id) {
                if ($id['type'] === 'ISBN_13') {
                    $result['isbn'] = $id['identifier'];
                    break;
                }
            }
        }

        // Imagen de portada
        if (isset($vol['imageLinks'])) {
            $result['image_url'] = $vol['imageLinks']['thumbnail']
                ?? $vol['imageLinks']['smallThumbnail']
                ?? '';
            // Mejorar calidad de imagen de Google Books
            $result['image_url'] = str_replace('&edge=curl', '', $result['image_url']);
            $result['image_url'] = preg_replace('/zoom=\d/', 'zoom=1', $result['image_url']);
        }

        $results[] = $result;
    }

    return $results;
}

/**
 * Consulta Open Library API por ISBN
 * https://openlibrary.org/developers/api
 */
function queryOpenLibrary($isbn)
{
    $isbn = preg_replace('/[^0-9X]/i', '', $isbn);
    $url = 'https://openlibrary.org/api/books?bibkeys=ISBN:' . urlencode($isbn) . '&format=json&jscmd=data';

    $response = isbn_http_get($url);
    if (!$response) return [];

    $data = json_decode($response, true);
    if (empty($data)) return [];

    $results = [];
    foreach ($data as $key => $book) {
        $authors = [];
        if (isset($book['authors'])) {
            foreach ($book['authors'] as $author) {
                $authors[] = $author['name'] ?? '';
            }
        }

        $subjects = [];
        if (isset($book['subjects'])) {
            foreach ($book['subjects'] as $subject) {
                $subjects[] = $subject['name'] ?? '';
            }
        }

        $result = [
            'source' => 'Open Library',
            'title' => $book['title'] ?? '',
            'subtitle' => $book['subtitle'] ?? '',
            'authors' => $authors,
            'publisher' => isset($book['publishers'][0]) ? $book['publishers'][0]['name'] : '',
            'publish_year' => $book['publish_date'] ?? '',
            'publish_place' => isset($book['publish_places'][0]) ? $book['publish_places'][0]['name'] : '',
            'isbn' => $isbn,
            'pages' => isset($book['number_of_pages']) ? $book['number_of_pages'] . ' p.' : '',
            'language' => 'spa',
            'description' => '',
            'categories' => $subjects,
            'image_url' => isset($book['cover']) ? ($book['cover']['medium'] ?? $book['cover']['small'] ?? '') : '',
            'edition' => '',
            'classification' => isset($book['classifications']['dewey_decimal_class'][0]) ? $book['classifications']['dewey_decimal_class'][0] : '',
        ];

        // Extraer año de la fecha de publicación
        if (preg_match('/(\d{4})/', $result['publish_year'], $m)) {
            $result['publish_year'] = $m[1];
        }

        $results[] = $result;
    }

    return $results;
}

/**
 * Consulta Open Library Books API (endpoint alternativo con más datos)
 */
function queryOpenLibraryWorks($isbn)
{
    $isbn = preg_replace('/[^0-9X]/i', '', $isbn);
    $url = 'https://openlibrary.org/isbn/' . urlencode($isbn) . '.json';

    $response = isbn_http_get($url);
    if (!$response) return [];

    $data = json_decode($response, true);
    if (empty($data) || !isset($data['title'])) return [];

    // Obtener datos del work para la descripción
    $description = '';
    if (isset($data['works'][0]['key'])) {
        $workUrl = 'https://openlibrary.org' . $data['works'][0]['key'] . '.json';
        $workResponse = isbn_http_get($workUrl);
        if ($workResponse) {
            $workData = json_decode($workResponse, true);
            if (isset($workData['description'])) {
                $description = is_string($workData['description']) ? $workData['description'] : ($workData['description']['value'] ?? '');
            }
        }
    }

    // Obtener autores
    $authors = [];
    if (isset($data['authors'])) {
        foreach ($data['authors'] as $authorRef) {
            $authorKey = $authorRef['key'] ?? '';
            if ($authorKey) {
                $authorUrl = 'https://openlibrary.org' . $authorKey . '.json';
                $authorResponse = isbn_http_get($authorUrl, 5);
                if ($authorResponse) {
                    $authorData = json_decode($authorResponse, true);
                    $authors[] = $authorData['name'] ?? '';
                }
            }
        }
    }

    $result = [
        'source' => 'Open Library (Works)',
        'title' => $data['title'] ?? '',
        'subtitle' => $data['subtitle'] ?? '',
        'authors' => $authors,
        'publisher' => isset($data['publishers'][0]) ? $data['publishers'][0] : '',
        'publish_year' => $data['publish_date'] ?? '',
        'publish_place' => isset($data['publish_places'][0]) ? $data['publish_places'][0] : '',
        'isbn' => $isbn,
        'pages' => isset($data['number_of_pages']) ? $data['number_of_pages'] . ' p.' : '',
        'language' => 'spa',
        'description' => $description,
        'categories' => $data['subjects'] ?? [],
        'image_url' => 'https://covers.openlibrary.org/b/isbn/' . $isbn . '-M.jpg',
        'edition' => $data['edition_name'] ?? '',
        'classification' => isset($data['dewey_decimal_class'][0]) ? $data['dewey_decimal_class'][0] : '',
    ];

    // Extraer año
    if (preg_match('/(\d{4})/', $result['publish_year'], $m)) {
        $result['publish_year'] = $m[1];
    }

    return [$result];
}

/**
 * Consulta la base de datos del ISBN del Ministerio de Cultura de España
 * Usa scraping del servicio web público
 */
function queryISBNSpain($isbn)
{
    $isbn = preg_replace('/[^0-9X]/i', '', $isbn);
    $url = 'https://www.cultura.gob.es/webISBN/tituloSimpleFilter.do?cache=init&prev_layout=busquedaisbn&layout=busquedaisbn&language=es&searchType=2&resultados=&isbn=' . urlencode($isbn);

    // Usamos isbn_http_get que tiene fallback a cURL
    $response = isbn_http_get($url, 20);
    if (!$response) return [];

    // Intentar parsear la respuesta HTML del Ministerio
    $results = [];

    // Buscar enlace al detalle del libro
    if (preg_match('/tituloDetalle\.do[^"]*/', $response, $detailMatch)) {
        $detailUrl = 'https://www.cultura.gob.es/webISBN/' . html_entity_decode($detailMatch[0]);
        $detailResponse = isbn_http_get($detailUrl, 20);

        if ($detailResponse) {
            $result = [
                'source' => 'ISBN España (Ministerio de Cultura)',
                'title' => '',
                'subtitle' => '',
                'authors' => [],
                'publisher' => '',
                'publish_year' => '',
                'publish_place' => '',
                'isbn' => $isbn,
                'pages' => '',
                'language' => 'spa',
                'description' => '',
                'categories' => [],
                'image_url' => '',
                'edition' => '',
                'classification' => '',
            ];

            // Extraer título — patrón más flexible para el HTML actual del Ministerio
            // Busca "Título" seguido de algún contenido en un span/div cercano
            $titlePatterns = [
                '/<span[^>]*class="[^"]*isbnField[^"]*"[^>]*>T[ií]tulo<\/span>\s*<\/div>\s*<div[^>]*>\s*<span[^>]*>([^<]+)/si',
                '/T[ií]tulo[^<]*<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si',
                '/<span[^>]*class="[^"]*label[^"]*"[^>]*>T[ií]tulo[^<]*<\/span>\s*<span[^>]*class="[^"]*value[^"]*"[^>]*>([^<]+)/si',
                '/<th[^>]*>T[ií]tulo<\/th>\s*<td[^>]*>([^<]+)/si',
                '/T[ií]tulo[^:]*:<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si',
                '/(?:T[ií]tulo|Title)\s*[:|]\s*([^<\n]+)/si',
            ];
            foreach ($titlePatterns as $pattern) {
                if (preg_match($pattern, $detailResponse, $m)) {
                    $title = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                    if (!empty($title)) {
                        $result['title'] = $title;
                        break;
                    }
                }
            }

            // Si no se encontró con patrones específicos, buscar en tablas
            if (empty($result['title'])) {
                if (preg_match('/<td[^>]*>\s*(\d{13})\s*<\/td>\s*<td[^>]*>\s*<a[^>]*>([^<]+)/si', $detailResponse, $m)) {
                    $result['title'] = trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
                }
            }

            // Extraer autor(es) — patrones más flexibles
            $authorPatterns = [
                '/Autor(?:es)?[^<]*<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si',
                '/<span[^>]*class="[^"]*label[^"]*"[^>]*>Autor(?:es)?[^<]*<\/span>\s*<span[^>]*class="[^"]*value[^"]*"[^>]*>([^<]+)/si',
                '/<th[^>]*>Autor(?:es)?<\/th>\s*<td[^>]*>([^<]+)/si',
                '/Autor(?:es)?[^:]*:<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si',
            ];
            foreach ($authorPatterns as $pattern) {
                if (preg_match_all($pattern, $detailResponse, $m)) {
                    $found = false;
                    foreach ($m[1] as $author) {
                        $author = trim(html_entity_decode($author, ENT_QUOTES, 'UTF-8'));
                        if ($author && $author !== '-') {
                            $result['authors'][] = $author;
                            $found = true;
                        }
                    }
                    if ($found) break;
                }
            }

            // Extraer editorial — patrones más flexibles
            $publisherPatterns = [
                '/Editorial[^<]*<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si',
                '/<span[^>]*class="[^"]*label[^"]*"[^>]*>Editorial[^<]*<\/span>\s*<span[^>]*class="[^"]*value[^"]*"[^>]*>([^<]+)/si',
                '/<th[^>]*>Editorial<\/th>\s*<td[^>]*>([^<]+)/si',
                '/Editorial[^:]*:<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si',
            ];
            foreach ($publisherPatterns as $pattern) {
                if (preg_match($pattern, $detailResponse, $m)) {
                    $publisher = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                    if (!empty($publisher) && $publisher !== '-') {
                        $result['publisher'] = $publisher;
                        break;
                    }
                }
            }

            // Extraer año de publicación — patrones más flexibles
            $yearPatterns = [
                '/Fecha\s*(?:de\s*)?(?:Publicaci[oó]n|Edici[oó]n)[^<]*<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si',
                '/<span[^>]*class="[^"]*label[^"]*"[^>]*>(?:A[ñn]o|Fecha)[^<]*<\/span>\s*<span[^>]*class="[^"]*value[^"]*"[^>]*>([^<]+)/si',
                '/<th[^>]*>(?:A[ñn]o|Fecha\s*(?:de\s*)?Publicaci[oó]n)<\/th>\s*<td[^>]*>([^<]+)/si',
                '/(?:A[ñn]o|Fecha\s*(?:de\s*)?Publicaci[oó]n)[^:]*:<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si',
            ];
            foreach ($yearPatterns as $pattern) {
                if (preg_match($pattern, $detailResponse, $m)) {
                    $dateStr = trim($m[1]);
                    if (preg_match('/(\d{4})/', $dateStr, $yearMatch)) {
                        $result['publish_year'] = $yearMatch[1];
                        break;
                    }
                }
            }

            // Extraer páginas — patrones más flexibles
            $pagesPatterns = [
                '/P[aá]ginas[^<]*<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si',
                '/<span[^>]*class="[^"]*label[^"]*"[^>]*>P[aá]ginas[^<]*<\/span>\s*<span[^>]*class="[^"]*value[^"]*"[^>]*>([^<]+)/si',
                '/<th[^>]*>P[aá]ginas<\/th>\s*<td[^>]*>([^<]+)/si',
            ];
            foreach ($pagesPatterns as $pattern) {
                if (preg_match($pattern, $detailResponse, $m)) {
                    $pages = trim($m[1]);
                    if (!empty($pages) && $pages !== '-') {
                        $result['pages'] = $pages . ' p.';
                        break;
                    }
                }
            }

            // Extraer materia(s)
            $subjectPatterns = [
                '/Materia[^<]*<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si',
                '/<span[^>]*class="[^"]*label[^"]*"[^>]*>Materia[^<]*<\/span>\s*<span[^>]*class="[^"]*value[^"]*"[^>]*>([^<]+)/si',
                '/<th[^>]*>Materia<\/th>\s*<td[^>]*>([^<]+)/si',
            ];
            foreach ($subjectPatterns as $pattern) {
                if (preg_match_all($pattern, $detailResponse, $m)) {
                    foreach ($m[1] as $subject) {
                        $subject = trim(html_entity_decode($subject, ENT_QUOTES, 'UTF-8'));
                        if ($subject && $subject !== '-') {
                            $result['categories'][] = $subject;
                        }
                    }
                    if (!empty($result['categories'])) break;
                }
            }

            // Extraer edición
            $editionPatterns = [
                '/Edici[oó]n[^<]*<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si',
                '/<span[^>]*class="[^"]*label[^"]*"[^>]*>Edici[oó]n[^<]*<\/span>\s*<span[^>]*class="[^"]*value[^"]*"[^>]*>([^<]+)/si',
                '/<th[^>]*>Edici[oó]n<\/th>\s*<td[^>]*>([^<]+)/si',
            ];
            foreach ($editionPatterns as $pattern) {
                if (preg_match($pattern, $detailResponse, $m)) {
                    $edition = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                    if ($edition && $edition !== '-') {
                        $result['edition'] = $edition;
                        break;
                    }
                }
            }

            // Extraer CDU/clasificación
            $classPatterns = [
                '/CDU[^<]*<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si',
                '/<span[^>]*class="[^"]*label[^"]*"[^>]*>CDU[^<]*<\/span>\s*<span[^>]*class="[^"]*value[^"]*"[^>]*>([^<]+)/si',
                '/<th[^>]*>CDU<\/th>\s*<td[^>]*>([^<]+)/si',
            ];
            foreach ($classPatterns as $pattern) {
                if (preg_match($pattern, $detailResponse, $m)) {
                    $classification = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                    if ($classification && $classification !== '-') {
                        $result['classification'] = $classification;
                        break;
                    }
                }
            }

            if (!empty($result['title'])) {
                $results[] = $result;
            }
        }
    }

    return $results;
}

/**
 * Descarga la imagen de portada y la guarda en el directorio de imágenes de SLiMS
 */
function downloadCoverImage($url, $isbn)
{
    if (empty($url)) return null;

    $url = str_replace('http://', 'https://', $url);

    $imageData = null;

    // Try file_get_contents if HTTPS wrapper is available
    if (in_array('https', stream_get_wrappers())) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: SLiMS-ISBN-Lookup/1.0\r\n",
                'timeout' => 10,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $imageData = @file_get_contents($url, false, $context);
    }

    // Fallback to cURL if file_get_contents failed
    if (empty($imageData) && function_exists('curl_version')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'SLiMS-ISBN-Lookup/1.0'
        ]);
        $imageData = @curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 400) $imageData = null;
    }

    if (!$imageData || strlen($imageData) < 1000) return null;

    // Detectar tipo de imagen
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($imageData);
    $ext = 'jpg';
    switch ($mime) {
        case 'image/png': $ext = 'png'; break;
        case 'image/gif': $ext = 'gif'; break;
        case 'image/webp': $ext = 'webp'; break;
    }

    $filename = 'isbn_' . preg_replace('/[^0-9X]/i', '', $isbn) . '.' . $ext;
    $filepath = IMGBS . 'docs' . DS . $filename;

    // Crear directorio si no existe
    if (!is_dir(IMGBS . 'docs')) {
        @mkdir(IMGBS . 'docs', 0775, true);
    }

    if (@file_put_contents($filepath, $imageData)) {
        return $filename;
    }

    return null;
}

/**
 * Busca en todas las fuentes disponibles y devuelve resultados combinados
 */
function searchAllSources($isbn, $sources = ['google', 'openlibrary', 'isbn_spain'])
{
    $allResults = [];

    if (in_array('google', $sources)) {
        $results = queryGoogleBooks($isbn);
        $allResults = array_merge($allResults, $results);
    }

    if (in_array('openlibrary', $sources)) {
        $results = queryOpenLibrary($isbn);
        if (empty($results)) {
            $results = queryOpenLibraryWorks($isbn);
        }
        $allResults = array_merge($allResults, $results);
    }

    if (in_array('isbn_spain', $sources)) {
        $results = queryISBNSpain($isbn);
        $allResults = array_merge($allResults, $results);
    }

    return $allResults;
}

// ============================================================================
// OPERACIÓN DE GUARDADO DE REGISTROS
// ============================================================================

if (isset($_POST['saveISBN'])) {
    try {
        if (!isset($_SESSION['isbn_results'])) {
            throw new Exception('La sesion ha expirado. Realice la busqueda de nuevo.');
        }

        require MDLBS . 'bibliography/biblio_utils.inc.php';

        $gmd_cache = [];
        $publ_cache = [];
        $place_cache = [];
        $lang_cache = [];
        $author_cache = [];
        $subject_cache = [];

        $sql_op = new simbio_dbop($dbs);
        $r = 0;

    foreach ($_POST['zrecord'] as $id) {
        $record = $_SESSION['isbn_results'][$id] ?? null;
        if (!$record) continue;

        $data = [];

        // Título (incluir subtítulo si existe)
        $data['title'] = $dbs->escape_string(trim($record['title']));
        if (!empty($record['subtitle'])) {
            $data['title'] .= ' : ' . $dbs->escape_string(trim($record['subtitle']));
        }

        // Statement of Responsibility (autores)
        if (!empty($record['authors'])) {
            $data['sor'] = $dbs->escape_string(implode('; ', $record['authors']));
        }

        // GMD (General Material Designation) - por defecto "Text"
        $data['gmd_id'] = utility::getID($dbs, 'mst_gmd', 'gmd_id', 'gmd_name', 'Text', $gmd_cache);

        // Editorial
        if (!empty($record['publisher'])) {
            $data['publisher_id'] = utility::getID($dbs, 'mst_publisher', 'publisher_id', 'publisher_name', $record['publisher'], $publ_cache);
        }

        // Lugar de publicación
        if (!empty($record['publish_place'])) {
            $data['publish_place_id'] = utility::getID($dbs, 'mst_place', 'place_id', 'place_name', $record['publish_place'], $place_cache);
        }

        // Año de publicación
        $data['publish_year'] = $dbs->escape_string($record['publish_year'] ?? '');

        // ISBN
        $data['isbn_issn'] = $dbs->escape_string($record['isbn'] ?? '');

        // Colación (páginas)
        $data['collation'] = $dbs->escape_string($record['pages'] ?? '');

        // Edición
        $data['edition'] = $dbs->escape_string($record['edition'] ?? '');

        // Clasificación
        $data['classification'] = $dbs->escape_string($record['classification'] ?? '');

        // Idioma
        $langName = 'Spanish';
        $langCode = $record['language'] ?? 'spa';
        switch ($langCode) {
            case 'es':
            case 'spa':
                $langName = 'Spanish';
                break;
            case 'en':
            case 'eng':
                $langName = 'English';
                break;
            case 'fr':
            case 'fre':
                $langName = 'French';
                break;
            case 'de':
            case 'ger':
                $langName = 'German';
                break;
            case 'pt':
            case 'por':
                $langName = 'Portuguese';
                break;
            case 'ca':
            case 'cat':
                $langName = 'Catalan';
                break;
            case 'eu':
            case 'eus':
                $langName = 'Basque';
                break;
            case 'gl':
            case 'glg':
                $langName = 'Galician';
                break;
            default:
                $langName = 'Spanish';
        }
        $data['language_id'] = utility::getID($dbs, 'mst_language', 'language_id', 'language_name', $langName, $lang_cache);

        // Notas (descripción)
        if (!empty($record['description'])) {
            $data['notes'] = $dbs->escape_string(substr($record['description'], 0, 2000));
        }

        // Descargar imagen de portada
        $data['image'] = null;
        if (!empty($record['image_url'])) {
            $imageName = downloadCoverImage($record['image_url'], $record['isbn']);
            if ($imageName) {
                $data['image'] = $imageName;
            }
        }

        // Campos adicionales
        $data['opac_hide'] = 0;
        $data['promoted'] = 0;
        $data['labels'] = '';
        $data['spec_detail_info'] = '';
        $data['input_date'] = date('Y-m-d H:i:s');
        $data['last_update'] = date('Y-m-d H:i:s');
        $data['uid'] = $_SESSION['uid'];

        // Insertar registro bibliográfico
        $insert = $sql_op->insert('biblio', $data);
        $biblio_id = $sql_op->insert_id;

        if ($biblio_id < 1) {
            continue;
        }

        // Insertar autores
        if (!empty($record['authors'])) {
            $level = 1;
            foreach ($record['authors'] as $authorName) {
                $authorName = trim($authorName);
                if (empty($authorName)) continue;
                $author_id = getAuthorID($authorName, 'p', $author_cache);
                @$dbs->query("INSERT IGNORE INTO biblio_author (biblio_id, author_id, level) VALUES ($biblio_id, $author_id, $level)");
                $level++;
            }
        }

        // Insertar materias/categorías
        if (!empty($record['categories'])) {
            foreach ($record['categories'] as $subject) {
                $subject = trim($subject);
                if (empty($subject)) continue;
                $subject_id = getSubjectID($subject, 't', $subject_cache);
                @$dbs->query("INSERT IGNORE INTO biblio_topic (biblio_id, topic_id, level) VALUES ($biblio_id, $subject_id, 1)");
            }
        }

        // Indexar el registro
        $indexer = new biblio_indexer($dbs);
        $indexer->makeIndex($biblio_id);

        // Escribir en el log
        $sourceLabel = $record['source'] ?? 'ISBN Lookup';
        writeLog('staff', $_SESSION['uid'], 'bibliography',
            sprintf(__('%s insert bibliographic data from %s with title (%s) and biblio_id (%s)'),
                $_SESSION['realname'], $sourceLabel, $data['title'], $biblio_id),
            'ISBN Lookup', 'Add');
        $r++;
    }

    // Limpiar sesión
    unset($_SESSION['isbn_results']);

    echo json_encode(['success' => true, 'count' => $r]);
    exit();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

// ============================================================================
// OPERACIÓN DE BÚSQUEDA
// ============================================================================

if (isset($_GET['keywords']) && $can_read) {
    $isbn = trim($_GET['keywords']);

    // Validar formato ISBN
    $isbn_clean = preg_replace('/[^0-9X]/i', '', $isbn);
    if (strlen($isbn_clean) !== 10 && strlen($isbn_clean) !== 13) {
        echo '<div class="errorBox">' . __('El ISBN debe tener 10 o 13 dígitos. Verifique el número introducido.') . '</div>';
        exit();
    }

    // Determinar fuentes seleccionadas
    $sources = [];
    if (isset($_GET['source_google'])) $sources[] = 'google';
    if (isset($_GET['source_openlibrary'])) $sources[] = 'openlibrary';
    if (isset($_GET['source_spain'])) $sources[] = 'isbn_spain';
    if (empty($sources)) $sources = ['google', 'openlibrary', 'isbn_spain'];

    // Buscar en todas las fuentes
    $allResults = searchAllSources($isbn_clean, $sources);

    if (empty($allResults)) {
        echo '<div class="errorBox">' . __('No se encontraron resultados para el ISBN: ') . htmlspecialchars($isbn) . '</div>';
        echo '<div class="infoBox">' . __('Sugerencias: Verifique que el ISBN sea correcto, pruebe sin guiones, o intente con otra fuente.') . '</div>';
        exit();
    }

    // Guardar resultados en sesión
    $_SESSION['isbn_results'] = [];
    $row = 1;
    foreach ($allResults as $result) {
        $_SESSION['isbn_results'][$row] = $result;
        $row++;
    }

    // Mostrar resultados
    echo '<div class="infoBox">' . sprintf(__('Se encontraron %d resultado(s) para ISBN: %s'), count($allResults), htmlspecialchars($isbn)) . '</div>';

    $table = new simbio_table();
    $table->table_attr = 'align="center" class="s-table table" cellpadding="5" cellspacing="0"';

    echo '<div class="p-3">
            <input value="' . __('Seleccionar Todo') . '" class="check-all button btn btn-default" type="button">
            <input value="' . __('Deseleccionar Todo') . '" class="uncheck-all button btn btn-default" type="button">
            <input type="submit" name="saveISBN" class="s-btn btn btn-success save" value="' . __('Guardar en Base de Datos') . '" />
          </div>';

    // Encabezados de tabla
    $table->setHeader([__('Sel.'), __('Portada'), __('Título / Autores'), __('ISBN'), __('Editorial'), __('Año'), __('Fuente')]);
    $table->table_header_attr = 'class="dataListHeader alterCell font-weight-bold"';

    $row = 1;
    foreach ($allResults as $result) {
        $cb = '<input type="checkbox" name="zrecord[' . $row . ']" value="' . $row . '">';

        // Imagen miniatura
        $imgTag = '<img src="' . SWB . 'images/default/image.png" style="height:70px;" class="rounded">';
        if (!empty($result['image_url'])) {
            $imgTag = '<img src="' . htmlspecialchars($result['image_url']) . '" style="height:70px; max-width:50px;" class="rounded" onerror="this.src=\'' . SWB . 'images/default/image.png\'">';
        }

        // Título y autores
        $titleContent = '<div class="media-body">';
        $titleContent .= '<div class="title"><strong>' . htmlspecialchars($result['title']);
        if (!empty($result['subtitle'])) {
            $titleContent .= ' : ' . htmlspecialchars($result['subtitle']);
        }
        $titleContent .= '</strong></div>';
        if (!empty($result['authors'])) {
            $titleContent .= '<div class="authors text-muted"><small>' . htmlspecialchars(implode('; ', $result['authors'])) . '</small></div>';
        }
        if (!empty($result['description'])) {
            $titleContent .= '<div class="description text-muted mt-1"><small>' . htmlspecialchars(mb_substr($result['description'], 0, 150)) . '...</small></div>';
        }
        $titleContent .= '</div>';

        // Badge de fuente con color
        $sourceColor = 'secondary';
        switch ($result['source']) {
            case 'Google Books': $sourceColor = 'primary'; break;
            case 'Open Library':
            case 'Open Library (Works)': $sourceColor = 'success'; break;
            case 'ISBN España (Ministerio de Cultura)': $sourceColor = 'danger'; break;
        }
        $sourceBadge = '<span class="badge badge-' . $sourceColor . '">' . htmlspecialchars($result['source']) . '</span>';

        $table->appendTableRow([
            $cb,
            $imgTag,
            $titleContent,
            htmlspecialchars($result['isbn']),
            htmlspecialchars($result['publisher']),
            htmlspecialchars($result['publish_year']),
            $sourceBadge,
        ]);

        $row_class = ($row % 2 == 0) ? 'alterCell' : 'alterCell2';
        $table->setCellAttr($row, 0, 'class="' . $row_class . '" valign="top" style="width: 30px;"');
        $table->setCellAttr($row, 1, 'class="' . $row_class . '" valign="top" style="width: 60px;"');
        $table->setCellAttr($row, 2, 'class="' . $row_class . '" valign="top" style="width: auto;"');
        $table->setCellAttr($row, 3, 'class="' . $row_class . '" valign="top" style="width: 120px;"');
        $table->setCellAttr($row, 4, 'class="' . $row_class . '" valign="top" style="width: 150px;"');
        $table->setCellAttr($row, 5, 'class="' . $row_class . '" valign="top" style="width: 60px;"');
        $table->setCellAttr($row, 6, 'class="' . $row_class . '" valign="top" style="width: 120px;"');

        $row++;
    }

    echo $table->printTable();
    ?>
    <script>
        $('.save').on('click', function (e) {
            var zrecord = {};
            var uri = '<?php echo MWB; ?>bibliography/isbn_lookup.php';
            $("input[type=checkbox]:checked").each(function() {
                zrecord[$(this).val()] = $(this).val();
            });

            if (Object.keys(zrecord).length === 0) {
                parent.toastr.warning("<?php echo __('Seleccione al menos un registro para guardar'); ?>", "ISBN Lookup");
                return;
            }

            $.ajax({
                url: uri,
                type: 'post',
                data: { saveISBN: true, zrecord: zrecord },
                dataType: 'json'
            })
            .done(function (response) {
                if (response.success) {
                    parent.toastr.success(response.count + " <?php echo __('registro(s) guardado(s) en la base de datos'); ?>", "ISBN Lookup");
                    parent.jQuery('#mainContent').simbioAJAX(uri);
                } else {
                    parent.toastr.error(response.error || "<?php echo __('Error al guardar los registros'); ?>", "ISBN Lookup");
                }
            })
            .fail(function (jqXHR, textStatus, errorThrown) {
                parent.toastr.error("Error (" + jqXHR.status + "): " + (jqXHR.responseText || errorThrown || "<?php echo __('Error de conexión al guardar'); ?>"), "ISBN Lookup");
                console.error("Save error:", jqXHR.responseText, textStatus, errorThrown);
            });
        });

        $(".uncheck-all").on('click', function (e) {
            e.preventDefault();
            $('[type=checkbox]').prop('checked', false);
        });
        $(".check-all").on('click', function (e) {
            e.preventDefault();
            $('[type=checkbox]').prop('checked', true);
        });
    </script>
    <?php
    exit();
}

// ============================================================================
// FORMULARIO DE BÚSQUEDA
// ============================================================================
?>
<div class="menuBox">
    <div class="menuBoxInner biblioIcon">
        <div class="per_title">
            <h2><?php echo __('Catalogación por ISBN'); ?></h2>
        </div>
        <div class="sub_section">
            <div class="infoBox mb-3">
                <i class="fa fa-info-circle"></i>
                <?php echo __('Introduzca un ISBN (10 o 13 dígitos) para buscar automáticamente los datos del libro en múltiples fuentes. No requiere php-yaz ni Z39.50.'); ?>
            </div>
            <form name="search" id="search" action="<?php echo MWB; ?>bibliography/isbn_lookup.php"
                  loadcontainer="searchResult" method="get" class="form-inline">
                <div class="form-group mr-2">
                    <label for="keywords" class="mr-2"><?php echo __('ISBN'); ?>:</label>
                    <input type="text" name="keywords" id="keywords" class="form-control"
                           placeholder="<?php echo __('Ej: 978-84-376-0494-7'); ?>"
                           style="width: 250px;"
                           pattern="[0-9Xx\-]{10,17}"
                           title="<?php echo __('Introduzca un ISBN válido (10 o 13 dígitos, con o sin guiones)'); ?>" />
                </div>
                <input type="submit" id="doSearch" value="<?php echo __('Buscar'); ?>" class="s-btn btn btn-primary mr-3" />
                
                <div class="form-group ml-3">
                    <label class="mr-2"><small><?php echo __('Fuentes'); ?>:</small></label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="source_google" id="src_google" value="1" checked>
                        <label class="form-check-label" for="src_google"><small>Google Books</small></label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="source_openlibrary" id="src_ol" value="1" checked>
                        <label class="form-check-label" for="src_ol"><small>Open Library</small></label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="source_spain" id="src_spain" value="1" checked>
                        <label class="form-check-label" for="src_spain"><small>ISBN España</small></label>
                    </div>
                </div>
            </form>
        </div>
        <div class="infoBox mt-2">
            <small>
                <strong><?php echo __('Fuentes disponibles'); ?>:</strong>
                <span class="badge badge-primary">Google Books</span> <?php echo __('Cobertura internacional, datos en español'); ?> |
                <span class="badge badge-success">Open Library</span> <?php echo __('Datos abiertos, portadas'); ?> |
                <span class="badge badge-danger">ISBN España</span> <?php echo __('Base de datos oficial del Ministerio de Cultura'); ?>
            </small>
        </div>
    </div>
</div>
<div id="searchResult">&nbsp;</div>
