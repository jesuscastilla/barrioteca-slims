<?php
/**
 * DIAGNÓSTICO DE CONECTIVIDAD PARA ISBN LOOKUP
 * =============================================
 * 
 * Este script prueba la conectividad del NAS Synology con las APIs
 * externas que usa el módulo de búsqueda por ISBN de SLiMS.
 * 
 * CÓMO USARLO:
 * 1. Sube este archivo al mismo directorio donde está SLiMS (junto a index.php)
 * 2. Ábrelo en el navegador: https://tu-nas.synology.me/slims/diagnostico_isbn.php
 * 3. Los resultados se mostrarán en pantalla con colores (verde = OK, rojo = ERROR)
 * 
 * @author    Barrioteca Acalencá
 * @license   GPL-3.0-or-later
 */

// ============================================================================
// CONFIGURACIÓN
// ============================================================================

// ISBN de prueba (usamos un libro conocido en español)
// "Cien años de soledad" de Gabriel García Márquez
$isbn_prueba = '9788437604947';

// Timeout para las conexiones (segundos)
$timeout = 10;

// ============================================================================
// FUNCIONES AUXILIARES
// ============================================================================

function resultado($prueba, $ok, $detalle = '', $extra = '') {
    $icono = $ok ? '✅' : '❌';
    $color = $ok ? '#28a745' : '#dc3545';
    echo "<tr style=\"background: " . ($ok ? '#f0fff4' : '#fff5f5') . "\">\n";
    echo "  <td style=\"padding: 10px; border-bottom: 1px solid #ddd;\"><strong>{$icono} {$prueba}</strong></td>\n";
    echo "  <td style=\"padding: 10px; border-bottom: 1px solid #ddd; color: {$color};\">" . ($ok ? 'SÍ' : 'NO') . "</td>\n";
    echo "  <td style=\"padding: 10px; border-bottom: 1px solid #ddd; font-size: 0.9em;\">" . htmlspecialchars($detalle) . "</td>\n";
    echo "</tr>\n";
    if ($extra) {
        echo "<tr style=\"background: #f8f9fa;\">\n";
        echo "  <td colspan=\"3\" style=\"padding: 8px 10px; border-bottom: 1px solid #ddd; font-size: 0.85em; color: #666;\">ℹ️ {$extra}</td>\n";
        echo "</tr>\n";
    }
}

function probar_url($url, $nombre, $timeout = 10) {
    $metodo_usado = null;
    $tiempo_total = 0;

    // --- Intento 1: file_get_contents ---
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\nUser-Agent: Mozilla/5.0 (compatible; SLiMS-Diagnostico/1.0)\r\n",
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $inicio = microtime(true);
    $respuesta = @file_get_contents($url, false, $ctx);
    $tiempo_fgc = round((microtime(true) - $inicio) * 1000);
    $tiempo_total = $tiempo_fgc;

    if ($respuesta !== false) {
        $metodo_usado = 'file_get_contents';
        $tiempo_total = $tiempo_fgc;
    }

    // --- Intento 2: cURL (si el primero falló) ---
    if ($respuesta === false && function_exists('curl_version')) {
        $inicio = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SLiMS-Diagnostico/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $respuesta = @curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tiempo_curl = round((microtime(true) - $inicio) * 1000);
        curl_close($ch);

        $tiempo_total = $tiempo_curl;

        if ($respuesta !== false && $httpCode >= 200 && $httpCode < 400) {
            $metodo_usado = 'cURL (file_get_contents falló)';
        } else {
            // Ambos fallaron
            $respuesta = false; // asegurar que se marque como fallo
        }
    }

    // --- Mostrar resultado ---
    if ($respuesta !== false) {
        $tamano = strlen($respuesta);
        $primeros = substr(trim($respuesta), 0, 100);
        resultado(
            "Conectar con {$nombre}",
            true,
            "{$metodo_usado} — Respuesta ({$tamano} bytes) en {$tiempo_total} ms",
            "Primeros bytes: " . htmlspecialchars($primeros) . "..."
        );
        return $respuesta;
    } else {
        $error = error_get_last();
        $msg_fgc = $error ? $error['message'] : 'Error desconocido';
        $msg_curl = (function_exists('curl_version'))
            ? "cURL también falló (HTTP " . ($httpCode ?? 'N/A') . ")"
            : "cURL no está disponible";
        resultado(
            "Conectar con {$nombre}",
            false,
            "file_get_contents: {$msg_fgc} | {$msg_curl} (tras {$tiempo_total} ms)"
        );
        return null;
    }
}

// ============================================================================
// CABECERA HTML
// ============================================================================

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnóstico ISBN Lookup - Barrioteca Acalencá</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; color: #333; }
        .container { max-width: 900px; margin: 0 auto; }
        h1 { font-size: 1.5em; margin-bottom: 5px; color: #2c3e50; }
        .subtitle { color: #666; margin-bottom: 20px; font-size: 0.9em; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px; overflow: hidden; }
        .card-header { padding: 15px 20px; border-bottom: 1px solid #eee; font-weight: bold; font-size: 1.1em; }
        .card-body { padding: 0; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 10px; background: #f8f9fa; border-bottom: 2px solid #dee2e6; text-align: left; font-size: 0.85em; text-transform: uppercase; color: #666; }
        .resumen { padding: 15px 20px; }
        .exito { background: #d4edda; color: #155724; padding: 10px 15px; border-radius: 4px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px 15px; border-radius: 4px; }
        .aviso { background: #fff3cd; color: #856404; padding: 10px 15px; border-radius: 4px; }
        code { background: #e8e8e8; padding: 2px 5px; border-radius: 3px; font-size: 0.9em; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 Diagnóstico de ISBN Lookup</h1>
    <p class="subtitle">Barrioteca Acalencá — Prueba de conectividad con APIs externas</p>

    <div class="card">
        <div class="card-header">📋 Configuración de PHP en el NAS</div>
        <div class="card-body">
            <table>
                <tr><th>Prueba</th><th>Resultado</th><th>Detalle</th></tr>
                <?php
                // Versión de PHP
                resultado('Versión de PHP', true, 'PHP ' . phpversion());

                // allow_url_fopen (necesario para file_get_contents)
                $allow_fopen = ini_get('allow_url_fopen');
                resultado(
                    'allow_url_fopen activado',
                    $allow_fopen == '1' || strtolower($allow_fopen) == 'on',
                    $allow_fopen ? "'{$allow_fopen}'" : 'DESACTIVADO — Es necesario activarlo en php.ini'
                );

                // cURL
                $curl = function_exists('curl_version');
                if ($curl) {
                    $cv = curl_version();
                    resultado('Extensión cURL', true, 'cURL ' . $cv['version'] . ' — ' . $cv['ssl_version']);
                } else {
                    resultado('Extensión cURL', false, 'NO INSTALADA');
                }

                // OpenSSL
                resultado(
                    'Extensión OpenSSL',
                    extension_loaded('openssl'),
                    extension_loaded('openssl') ? 'Disponible' : 'NO DISPONIBLE — Necesaria para HTTPS'
                );

                // JSON
                resultado(
                    'Extensión JSON',
                    extension_loaded('json'),
                    extension_loaded('json') ? 'Disponible' : 'NO DISPONIBLE'
                );

                // fileinfo (para detectar tipo de imagen)
                resultado(
                    'Extensión fileinfo',
                    extension_loaded('fileinfo'),
                    extension_loaded('fileinfo') ? 'Disponible' : 'NO DISPONIBLE (opcional, para portadas)'
                );

                // GD (para redimensionar imágenes)
                resultado(
                    'Extensión GD',
                    extension_loaded('gd'),
                    extension_loaded('gd') ? 'Disponible' : 'NO DISPONIBLE (opcional, para redimensionar portadas)'
                );

                // Directorio de imágenes
                $img_dir = __DIR__ . '/images/docs';
                $img_writable = is_dir($img_dir) ? is_writable($img_dir) : false;
                resultado(
                    'Directorio images/docs accesible',
                    $img_writable,
                    $img_writable ? 'Directorio existe y es escribible' : 'NO ACCESIBLE — Las portadas no podrán guardarse'
                );
                if (!is_dir($img_dir)) {
                    $creado = @mkdir($img_dir, 0755, true);
                    resultado('Crear directorio images/docs', $creado, $creado ? 'Creado correctamente' : 'NO SE PUDO CREAR');
                }

                // Conexión a Internet (DNS)
                $dns = checkdnsrr('google.com', 'ANY');
                $dns_msg = $dns ? 'DNS funciona correctamente' : 
                    'FALLO — El NAS no puede resolver nombres DNS. ' .
                    'Solución: Ve a Panel de Control → Red → Interfaz de Red → Editar → DNS Manual → ' .
                    'Añade 8.8.8.8 y 8.8.4.4 (Google DNS) o los DNS de tu ISP.';
                resultado(
                    'Resolución DNS (google.com)',
                    $dns,
                    $dns_msg
                );
                ?>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">🌐 Prueba de conectividad con APIs</div>
        <div class="card-body">
            <table>
                <tr><th>Prueba</th><th>Resultado</th><th>Detalle</th></tr>
                <?php
                // 1. Google Books API (con API Key para evitar límite de peticiones)
                $google_api_key = 'REEMPLAZA_CON_TU_CLAVE';
                $url_gb = 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . urlencode($isbn_prueba) . '&langRestrict=es&maxResults=1&key=' . $google_api_key;
                $resp_gb = probar_url($url_gb, 'Google Books API', $timeout);

                if ($resp_gb) {
                    $data = json_decode($resp_gb, true);
                    if (isset($data['totalItems']) && $data['totalItems'] > 0) {
                        $vol = $data['items'][0]['volumeInfo'] ?? [];
                        $titulo = $vol['title'] ?? 'desconocido';
                        $autores = isset($vol['authors']) ? implode(', ', $vol['authors']) : 'desconocido';
                        resultado(
                            'Google Books — Buscar ISBN ' . $isbn_prueba,
                            true,
                            "¡Libro encontrado! \"{$titulo}\" por {$autores}"
                        );
                    } else {
                        resultado(
                            'Google Books — Buscar ISBN ' . $isbn_prueba,
                            true,
                            'API responde pero no encuentra resultados para este ISBN'
                        );
                    }
                }

                // 2. Open Library API
                $url_ol = 'https://openlibrary.org/api/books?bibkeys=ISBN:' . urlencode($isbn_prueba) . '&format=json&jscmd=data';
                $resp_ol = probar_url($url_ol, 'Open Library API', $timeout);

                if ($resp_ol) {
                    $data = json_decode($resp_ol, true);
                    $key = 'ISBN:' . $isbn_prueba;
                    if (isset($data[$key])) {
                        $titulo = $data[$key]['title'] ?? 'desconocido';
                        resultado(
                            'Open Library — Buscar ISBN ' . $isbn_prueba,
                            true,
                            "¡Libro encontrado! \"{$titulo}\""
                        );
                    } else {
                        resultado(
                            'Open Library — Buscar ISBN ' . $isbn_prueba,
                            true,
                            'API responde pero no encuentra este ISBN'
                        );
                    }

                    // Probar portada de Open Library
                    $url_cover = 'https://covers.openlibrary.org/b/isbn/' . $isbn_prueba . '-M.jpg';
                    $ctx_cover = stream_context_create([
                        'http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true],
                        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
                    ]);
                    $headers = @get_headers($url_cover, true, $ctx_cover);
                    if ($headers && (strpos($headers[0] ?? '', '200') !== false || strpos($headers[0] ?? '', '304') !== false)) {
                        resultado('Open Library — Portada', true, 'URL de portada accesible: ' . $url_cover);
                    } else {
                        $status = $headers ? $headers[0] : 'sin respuesta';
                        resultado('Open Library — Portada', true, "URL generada (puede no tener portada para este ISBN): {$status}");
                    }
                }

                // 3. ISBN España (Ministerio de Cultura)
                $url_es = 'https://www.cultura.gob.es/webISBN/tituloSimpleFilter.do?cache=init&prev_layout=busquedaisbn&layout=busquedaisbn&language=es&searchType=2&resultados=&isbn=' . urlencode($isbn_prueba);
                $resp_es = probar_url($url_es, 'ISBN España (Ministerio de Cultura)', $timeout);

                if ($resp_es) {
                    if (preg_match('/T[ií]tulo[^<]*<\/[^>]+>\s*<[^>]+>\s*<[^>]+>([^<]+)/si', $resp_es, $m)) {
                        $titulo = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                        resultado(
                            'ISBN España — Buscar ISBN ' . $isbn_prueba,
                            true,
                            "¡Libro encontrado! \"{$titulo}\""
                        );
                    } elseif (preg_match('/class="[^"]*error[^"]*"/si', $resp_es)) {
                        resultado(
                            'ISBN España — Buscar ISBN ' . $isbn_prueba,
                            true,
                            'Página del Ministerio responde pero no se pudo extraer el título (el scraping puede necesitar actualización)'
                        );
                    } else {
                        resultado(
                            'ISBN España — Buscar ISBN ' . $isbn_prueba,
                            true,
                            'Página del Ministerio responde correctamente (no se detectó error)'
                        );
                    }
                }
                ?>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">📝 Resumen</div>
        <div class="resumen">
            <?php
            $todo_ok = true;
            if (!$allow_fopen || $allow_fopen == '0' || strtolower($allow_fopen) == 'off') {
                $todo_ok = false;
                echo '<div class="error">❌ <strong>ERROR CRÍTICO:</strong> allow_url_fopen está DESACTIVADO. El módulo ISBN Lookup no podrá conectar con ninguna API externa hasta que se active.<br>';
                echo 'Solución: En Web Station del Synology, edita el perfil PHP y activa <code>allow_url_fopen = On</code>.</div><br>';
            }
            if (!$resp_gb && !$resp_ol && !$resp_es) {
                $todo_ok = false;
                echo '<div class="error">❌ <strong>ERROR:</strong> No se pudo conectar con NINGUNA API externa. Posibles causas:<br>';
                echo '• El NAS no tiene acceso a Internet<br>';
                echo '• Firewall del NAS bloqueando salida<br>';
                echo '• Proxy corporativo (si el NAS está en una red con proxy)<br>';
                echo '• DNS no funciona</div>';
            } elseif ($resp_gb) {
                echo '<div class="exito">✅ <strong>Google Books API:</strong> Funciona correctamente. Los libros en español deberían encontrarse.</div><br>';
            }
            if ($resp_ol) {
                echo '<div class="exito">✅ <strong>Open Library:</strong> Funciona correctamente. Portadas disponibles.</div><br>';
            }
            if ($resp_es) {
                echo '<div class="exito">✅ <strong>ISBN España:</strong> Funciona correctamente. Catálogo oficial del Ministerio de Cultura accesible.</div><br>';
            }
            if ($todo_ok && $resp_gb && $resp_ol) {
                echo '<div class="exito">🎉 <strong>¡TODO CORRECTO!</strong> El NAS puede conectar con las APIs externas. El módulo ISBN Lookup debería funcionar.<br>';
                echo 'Para usarlo, ve a <strong>Bibliographic → ISBN Lookup</strong> en el panel de administración de SLiMS.</div>';
            } else {
                echo '<div class="aviso">⚠️ Hay problemas de conectividad. Revisa los resultados en rojo más arriba para identificar la causa.</div>';
            }
            ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">📖 Próximos pasos</div>
        <div class="card-body" style="padding: 15px 20px; line-height: 1.8;">
            <p>Una vez que el diagnóstico muestre todo verde:</p>
            <ol style="margin-left: 20px;">
                <li>Ve al panel de administración de SLiMS</li>
                <li>Entra en <strong>Bibliographic → Cataloging → Add New Bibliography</strong></li>
                <li>Introduce un ISBN en el campo correspondiente</li>
                <li>Haz clic en <strong>"ISBN Lookup"</strong> — debería buscar automáticamente los datos del libro</li>
                <li>Selecciona los resultados que quieras importar y guarda</li>
            </ol>
            <p style="margin-top: 10px; color: #666; font-size: 0.9em;">📌 Si el diagnóstico muestra fallos de conexión, el problema está en la configuración de red del NAS, no en el código de SLiMS.</p>
        </div>
    </div>

    <p style="text-align: center; color: #999; font-size: 0.8em; margin-top: 20px;">
        Diagnóstico ISBN Lookup — Barrioteca Acalencá — Generado: <?php echo date('d/m/Y H:i:s'); ?>
    </p>
</div>
</body>
</html>
<?php
// Al final, si allow_url_fopen está desactivado, lo mostramos también al principio
if (!$allow_fopen || $allow_fopen == '0' || strtolower($allow_fopen) == 'off') {
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            var firstCard = document.querySelector('.card');
            if (firstCard) {
                var warning = document.createElement('div');
                warning.style.cssText = 'background: #dc3545; color: white; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; font-size: 1.1em;';
                warning.innerHTML = '🚫 <strong>allow_url_fopen está DESACTIVADO</strong> — El ISBN Lookup NO funcionará. Actívalo en el perfil PHP de Web Station (Synology): Editar perfil PHP → allow_url_fopen = On';
                firstCard.parentNode.insertBefore(warning, firstCard);
            }
        });
    </script>";
}
?>
