<?php
/**
 * Bootstrap de la API REST de SLiMS para la PWA Barrioteca Acalencá
 * 
 * Este archivo carga el entorno de SLiMS y enruta las peticiones
 * a los controladores adecuados mediante routes.php.
 *
 * Funciona con Nginx SIN necesidad de reglas de reescritura:
 * usa PATH_INFO, que PHP recibe automáticamente cuando la URL
 * apunta a un archivo real seguido de una ruta adicional.
 *
 * Ejemplo:
 *   /slims/api/index.php/v1/member/pelotxo/verify
 *   → PATH_INFO = /v1/member/pelotxo/verify
 *   → bootstrap lo convierte en $_GET['p'] = 'api/member/pelotxo/verify'
 *   → AltoRouter lo enruta a CirculationController@verifyMember
 *
 * También funciona con Apache y URL rewriting (.htaccess).
 */

// Prevenir acceso directo
define('INDEX_AUTH', '1');

// Cargar configuración global de SLiMS — esto define SB, $sysconf, $dbs, etc.
require __DIR__ . '/../sysconfig.inc.php';

// Establecer content-type JSON para toda la API
header('Content-Type: application/json; charset=utf-8');

try {
    // ─── Extraer la ruta API de la URL solicitada ───────────────────────
    // Prioridad 1 (RECOMENDADA para Synology): Parámetro _api_path
    //   El api-proxy.php llama a:
    //     /slims/api/index.php?_api_path=/member/pelotxo/verify
    //   Los query params los pasa Nginx SIEMPRE.
    //
    // Prioridad 2: PATH_INFO (si Nginx tiene fastcgi_split_path_info)
    //   /slims/api/index.php/member/pelotxo/verify
    //
    // Prioridad 3: REQUEST_URI (Apache con .htaccess)
    //   /slims/api/v1/member/pelotxo/verify
    if (!empty($_GET['_api_path'])) {
        $path = '/' . ltrim($_GET['_api_path'], '/');
    } elseif (!empty($_SERVER['PATH_INFO'])) {
        $path = $_SERVER['PATH_INFO'];
    } else {
        $request_uri = $_SERVER['REQUEST_URI'];
        $base_path = dirname($_SERVER['PHP_SELF']);
        $path = substr($request_uri, strlen($base_path));
        $path = parse_url($path, PHP_URL_PATH);
    }
    
    // Eliminar el prefijo de versión (v1, v2…) si existe,
    // porque las rutas en routes.php están registradas SIN el segmento de versión.
    // Ej: /v1/member/foo → /member/foo
    $path = preg_replace('#^/v\d+/?#', '/', $path);
    
    // Asegurar que termina sin slash (salvo la raíz)
    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }
    
    // Establecer $_GET['p'] para el enrutador de SLiMS (AltoRouter).
    // El basePath configurado en routes.php es 'api', así que anteponemos 'api'.
    // Ej: /member/pelotxo/verify → $_GET['p'] = 'api/member/pelotxo/verify'
    $_GET['p'] = 'api' . $path;
    
    // ─── Cargar y ejecutar las rutas ────────────────────────────────────
    require __DIR__ . '/v1/routes.php';
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Error interno de la API: ' . $e->getMessage()
    ]);
}
