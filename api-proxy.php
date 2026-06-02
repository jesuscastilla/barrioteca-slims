<?php
/**
 * API Proxy para la PWA de Barrioteca Acalencá
 * Este archivo permite que la PWA se comunique con la API de SLiMS 
 * sin necesidad de un servidor Node intermedio.
 */

// --- CORS ---
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
} else {
    header("Access-Control-Allow-Origin: *");
}

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

// Configuración de la API interna
$SLIMS_API_BASE = 'http://localhost/api/v1'; // Ajustar si SLiMS está en un subdirectorio

// Obtener la ruta solicitada
$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$base_path = dirname($_SERVER['PHP_SELF']);
$path = str_replace($base_path . '/api-proxy.php', '', $request_uri);
$path = explode('?', $path)[0];

// Mapeo de rutas de la PWA a la API de SLiMS
$route_map = [
    '/verify-member' => '/member/{id}/verify',
    '/perform-action' => '/loan/{action}',
    '/catalog-proxy' => '/biblio/search'
];

// Lógica de proxy simplificada
$target_url = '';
$input_data = json_decode(file_get_contents('php://input'), true) ?: $_POST;

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

if (!$target_url) {
    echo json_encode(['status' => 'error', 'message' => 'Ruta no encontrada en el proxy: ' . $path]);
    exit;
}

// Ejecutar la petición a la API interna
$ch = curl_init($target_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
if ($method == 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($input_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
}
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Mapear respuesta para compatibilidad con el frontend feminizado
$data = json_decode($response, true);

// Add check for non-JSON response from SLiMS
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code($http_code >= 400 ? $http_code : 500);
    echo json_encode([
        'status' => 'error',
        'message' => 'El servidor devolvió una respuesta no válida (quizás el registro no existe o hay un error).'
    ]);
    exit;
}

if ($path == '/catalog-proxy' && is_array($data)) {
    $results = array_map(function($item) {
        return [
            'id' => $item['biblio_id'],
            'title' => $item['title'],
            'author' => $item['author'] ?: "Autora Desconocida",
            'isbn' => $item['isbn_issn'],
            'status' => $item['is_available'] ? "disponible" : "prestada",
            'image' => $item['image']
        ];
    }, $data);
    echo json_encode($results);
} else {
    http_response_code($http_code);
    echo $response;
}
