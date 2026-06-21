<?php
/**
 * Proxy de API para la PWA de la Barrioteca Acalencá
 * Este archivo permite que la PWA se comunique con la API interna de SLiMS
 * manejando CORS y mapeando las peticiones a los endpoints correctos.
 */

// --- Configuración de CORS ---
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
} else {
    header("Access-Control-Allow-Origin: *");
}

// Manejar peticiones preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}

header('Content-Type: application/json; charset=utf-8');

/**
 * Configuración de la URL base de la API interna de SLiMS.
 * En el NAS Synology, esto suele ser la ruta local o el dominio configurado.
 */
$SLIMS_API_BASE = 'http://localhost/slims/api/v1'; 

// Obtener la ruta solicitada al proxy
$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$base_path = dirname($_SERVER['PHP_SELF']);
$path = str_replace($base_path . '/api-proxy.php', '', $request_uri);
$path = explode('?', $path)[0];

// Leer datos de entrada (JSON o POST tradicional)
$input_data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$target_url = '';

// Mapeo de rutas de la PWA a la API interna de SLiMS
if ($path == '/verify-member') {
    $member_id = $input_data['member_id'] ?? $_GET['member_id'] ?? '';
    $target_url = $SLIMS_API_BASE . "/member/" . urlencode($member_id) . "/verify";
} elseif ($path == '/perform-action') {
    $accion = $input_data['accion'] ?? '';
    if ($accion == 'prestamo') {
        $target_url = $SLIMS_API_BASE . "/loan/borrow";
    } elseif ($accion == 'devolucion') {
        $target_url = $SLIMS_API_BASE . "/loan/return";
    }
} elseif ($path == '/catalog-proxy') {
    $q = $_GET['q'] ?? '';
    $target_url = $SLIMS_API_BASE . "/biblio/search?q=" . urlencode($q);
}

// Si la ruta no está mapeada, devolver error
if (!$target_url) {
    echo json_encode(['status' => 'error', 'message' => 'Ruta no encontrada en el proxy: ' . $path]);
    exit;
}

// Ejecutar la petición a la API interna usando cURL
$ch = curl_init($target_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
if ($method == 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($input_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
}

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Procesar la respuesta
$data = json_decode($response, true);

// Verificar si la respuesta es un JSON válido
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code($http_code >= 400 ? $http_code : 500);
    echo json_encode([
        'status' => 'error',
        'message' => 'El servidor devolvió una respuesta no válida (puede que el recurso no exista o haya un error de base de datos).'
    ]);
    exit;
}

// Mapear resultados del catálogo para usar lenguaje feminizado en el frontend
if ($path == '/catalog-proxy' && is_array($data)) {
    $results = array_map(function($item) {
        return [
            'id' => $item['biblio_id'] ?? '',
            'title' => $item['title'] ?? '',
            'author' => ($item['author'] ?? '') ?: "Autora Desconocida",
            'isbn' => $item['isbn_issn'] ?? '',
            'status' => ($item['is_available'] ?? false) ? "disponible" : "prestada",
            'image' => $item['image'] ?? ''
        ];
    }, $data);
    echo json_encode($results);
} else {
    // Para otras peticiones, devolver la respuesta original de SLiMS
    http_response_code($http_code);
    echo $response;
}
