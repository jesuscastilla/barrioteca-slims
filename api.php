<?php
// --- INICIO BLOQUE CORS ---
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
// --- FIN BLOQUE CORS ---

header('Content-Type: application/json; charset=utf-8');

// 1. CONEXIÓN DIRECTA Y SEGURA A MARIADB (Sin depender de archivos de SLiMS)
// Utilizar variables de entorno para la configuración de la base de datos
$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_port = getenv('DB_PORT') ?: '3306';
$db_name = getenv('DB_NAME') ?: 'senayan';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: 'Pass123';

// Validar que las credenciales estén configuradas
if (empty($db_host) || empty($db_name) || empty($db_user)) {
    echo json_encode(["status" => "error", "message" => "Configuración de base de datos incompleta. Verifica las variables de entorno."]);
    exit;
}

try {
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Fallo al conectar con MariaDB: " . $e->getMessage()]);
    exit;
}

// 2. LECTURA DE DATOS
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

if (empty($data)) {
    $data = $_POST;
}

$accion = strtolower(trim($data['accion'] ?? ''));
$id_socia = trim($data['id_socia'] ?? $data['member_id'] ?? '');
$asin = trim($data['asin'] ?? $data['isbn'] ?? '');

if (!$accion) {
    echo json_encode(["status" => "error", "message" => "Acción no especificada."]);
    exit;
}

// 3. PROCESAMIENTO DE ACCIONES
switch ($accion) {
    
    // -- LOGIN DE SOCIA --
    case 'verificar_socia':
        if (empty($id_socia)) {
            echo json_encode(["status" => "error", "message" => "El código de socia es obligatorio."]);
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT member_name FROM member WHERE member_id = :id LIMIT 1");
            $stmt->execute([':id' => $id_socia]);
            $member = $stmt->fetch();

            if ($member) {
                echo json_encode(["status" => "success", "nombre" => $member['member_name'], "message" => "Acceso concedido."]);
            } else {
                echo json_encode(["status" => "error", "message" => "Socia no encontrada en la base de datos."]);
            }
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => "Error SQL: " . $e->getMessage()]);
        }
        break;

    // -- PRÉSTAMO --
    case 'prestamo':
        if (empty($id_socia) || empty($asin)) {
            echo json_encode(["status" => "error", "message" => "Faltan datos para el préstamo."]);
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT member_name FROM member WHERE member_id = :id LIMIT 1");
            $stmt->execute([':id' => $id_socia]);
            $member = $stmt->fetch();
            if (!$member) {
                echo json_encode(["status" => "error", "message" => "La socia no existe."]);
                exit;
            }

            $stmt = $pdo->prepare("SELECT i.item_code, b.title FROM item i LEFT JOIN biblio b ON i.biblio_id = b.biblio_id WHERE i.item_code = :asin OR b.isbn_issn = :asin LIMIT 1");
            $stmt->execute([':asin' => $asin]);
            $item = $stmt->fetch();
            if (!$item) {
                echo json_encode(["status" => "error", "message" => "El libro no existe en la biblioteca."]);
                exit;
            }

            $loan_date = date('Y-m-d');
            $due_date = date('Y-m-d', strtotime('+15 days'));

            $stmt = $pdo->prepare("INSERT INTO loan (item_code, member_id, loan_date, due_date, is_returned, renewals) VALUES (:item, :member, :ldate, :ddate, 0, 0)");
            $stmt->execute([':item' => $item['item_code'], ':member' => $id_socia, ':ldate' => $loan_date, ':ddate' => $due_date]);

            echo json_encode(["status" => "success", "message" => "Préstamo registrado: {$item['title']}"]);
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => "Error al prestar: " . $e->getMessage()]);
        }
        break;

    // -- DEVOLUCIÓN --
    case 'devolucion':
        if (empty($asin)) {
            echo json_encode(["status" => "error", "message" => "Falta el código del libro."]);
            exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT loan_id FROM loan WHERE item_code = :asin AND is_returned = 0 LIMIT 1");
            $stmt->execute([':asin' => $asin]);
            $loan = $stmt->fetch();

            if (!$loan) {
                echo json_encode(["status" => "error", "message" => "El libro no consta como prestado actualmente."]);
                exit;
            }

            $return_date = date('Y-m-d');
            $stmt = $pdo->prepare("UPDATE loan SET is_returned = 1, return_date = :ret WHERE loan_id = :id");
            $stmt->execute([':ret' => $return_date, ':id' => $loan['loan_id']]);

            echo json_encode(["status" => "success", "message" => "Libro devuelto correctamente."]);
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => "Error al devolver: " . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(["status" => "error", "message" => "Acción desconocida."]);
        break;
}