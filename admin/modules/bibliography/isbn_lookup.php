<?php
/**
 * ISBN Lookup - Catalogador automático por ISBN
 * 
 * Busca datos bibliográficos en múltiples fuentes (Google Books, Open Library,
 * Biblioteca Nacional de España) y permite importarlos directamente a SLiMS.
 * 
 * No requiere php-yaz ni extensiones Z39.50. Funciona mediante APIs REST/HTTP.
 * 
 * @author      Barrioteca Acalencá / SLiMS Community
 * @copyright   2024
 * @license     GPL-3.0-or-later
 * @version     1.0.0
 */

// Clave para autenticación
define('INDEX_AUTH', '1');
// Clave para acceso completo a la base de datos
define('DB_ACCESS', 'fa');

if (!isset($errors)) {
    $errors = false;
}

// Iniciar la sesión y cargar configuración
require '../../../sysconfig.inc.php';
// Limitación de acceso por IP
require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-bibliography');
require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';
require SIMBIO . 'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO . 'simbio_GUI/paging/simbio_paging.inc.php';
require SIMBIO . 'simbio_DB/simbio_dbop.inc.php';
require MDLBS . 'system/biblio_indexer.inc.php';

// Comprobación de privilegios
$can_read = utility::havePrivilege('bibliography', 'r');
$can_write = utility::havePrivilege('bibliography', 'w');

if (!$can_read) {
    die('<div class="errorBox">' . __('No tienes autorización para ver esta sección') . '</div>');
}

# COMPROBAR ACCESO
if ($_SESSION['uid'] != 1) {
    if (!utility::haveAccess('bibliography.isbn-lookup')) {
        die('<div class="errorBox">' . __('No tienes autorización para ver esta sección') . '</div>');
    }
}

// ============================================================================
// FUNCIONES DE CONSULTA A APIs
// ============================================================================

/**
 * Realiza una petición HTTP GET con manejo de errores
 */
function isbn_http_get($url, $timeout = 15)
{
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
    if ($response === false) {
        return null;
    }
    return $response;
}

/**
 * Consulta Google Books API por ISBN
 */
function queryGoogleBooks($isbn)
{
    $isbn = preg_replace('/[^0-9X]/i', '', $isbn);
    $url = 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . urlencode($isbn) . '&langRestrict=es&maxResults=5';

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
            $result['image_url'] = str_replace('&edge=curl', '', $result['image_url']);
            $result['image_url'] = preg_replace('/zoom=\d/', 'zoom=1', $result['image_url']);
        }

        $results[] = $result;
    }

    return $results;
}

/**
 * Consulta Open Library API por ISBN
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
 * Consulta la base de datos del ISBN del Ministerio de Cultura de España
 * Usa acceso al servicio web público
 */
function queryISBNSpain($isbn)
{
    $isbn = preg_replace('/[^0-9X]/i', '', $isbn);
    $url = 'https://www.cultura.gob.es/webISBN/tituloSimpleFilter.do?cache=init&prev_layout=busquedaisbn&layout=busquedaisbn&language=es&searchType=2&resultados=&isbn=' . urlencode($isbn);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: text/html\r\nUser-Agent: Mozilla/5.0 (compatible; SLiMS-ISBN-Lookup/1.0)\r\n",
            'timeout' => 15,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if (!$response) return [];

    $results = [];

    // Buscar enlace al detalle del libro en el HTML
    if (preg_match('/tituloDetalle\.do[^"]*/', $response, $detailMatch)) {
        $detailUrl = 'https://www.cultura.gob.es/webISBN/' . html_entity_decode($detailMatch[0]);
        $detailResponse = @file_get_contents($detailUrl, false, $context);

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

            // Extraer título
            if (preg_match('/<span[^>]*class="[^"]*isbnField[^"]*"[^>]*>T[ií]tulo<\/span>\s*<\/div>\s*<div[^>]*>\s*<span[^>]*>([^<]+)/si', $detailResponse, $m)) {
                $result['title'] = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
            }

            // Extraer autoras
            if (preg_match_all('/Autor(?:es)?[^<]*<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si', $detailResponse, $m)) {
                foreach ($m[1] as $author) {
                    $author = trim(html_entity_decode($author, ENT_QUOTES, 'UTF-8'));
                    if ($author && $author !== '-') {
                        $result['authors'][] = $author;
                    }
                }
            }

            // Extraer editorial
            if (preg_match('/Editorial[^<]*<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si', $detailResponse, $m)) {
                $result['publisher'] = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
            }

            // Extraer año
            if (preg_match('/Fecha\s*(?:de\s*)?(?:Publicaci[oó]n|Edici[oó]n)[^<]*<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si', $detailResponse, $m)) {
                if (preg_match('/(\d{4})/', $m[1], $yearMatch)) {
                    $result['publish_year'] = $yearMatch[1];
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
 * Descarga la imagen de portada y la guarda en SLiMS
 */
function downloadCoverImage($url, $isbn)
{
    if (empty($url)) return null;

    $url = str_replace('http://', 'https://', $url);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: SLiMS-ISBN-Lookup/1.0\r\n",
            'timeout' => 10,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);

    $imageData = @file_get_contents($url, false, $context);
    if (!$imageData) return null;

    $fileName = 'isbn_' . $isbn . '.jpg';
    $savePath = SB . 'images' . DS . 'docs' . DS . $fileName;

    if (@file_put_contents($savePath, $imageData)) {
        return $fileName;
    }
    return null;
}
