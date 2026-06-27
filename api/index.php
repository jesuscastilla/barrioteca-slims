<?php
/**
 * Bootstrap de la API REST de SLiMS para la PWA Barrioteca Acalencá
 * 
 * Este archivo carga el entorno de SLiMS y enruta las peticiones
 * a los controladores adecuados mediante routes.php.
 *
 * Uso: Todas las peticiones a /api/* se redirigen aquí mediante .htaccess
 * Ejemplo: /api/v1/member/pelotxo/verify → este archivo → routes.php → CirculationController
 */

// Prevenir acceso directo
define('INDEX_AUTH', '1');

// Cargar configuración global de SLiMS — esto define SB, $sysconf, $dbs, etc.
require __DIR__ . '/../sysconfig.inc.php';

// Establecer content-type JSON para toda la API
header('Content-Type: application/json; charset=utf-8');

try {
    // ─── Extraer la ruta API de la URL solicitada ───────────────────────
    // Ejemplo: /slims/api/v1/member/pelotxo/verify
    //   → PHP_SELF = /slims/api/index.php
    //   → dirname  = /slims/api
    //   → path     = /v1/member/pelotxo/verify
    $request_uri = $_SERVER['REQUEST_URI'];
    $base_path = dirname($_SERVER['PHP_SELF']);
    
    // Obtener la porción de ruta después de /api/
    $path = substr($request_uri, strlen($base_path));
    $path = parse_url($path, PHP_URL_PATH);
    
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
