<?php
/**
 * @Date                : 2026-05-29
 * @File name           : CirculationController.php
 * @Description         : Controlador para operaciones de circulación (préstamo, devolución)
 *                        integrado con la lógica de SLiMS
 */

class CirculationController extends Controller
{
    protected $sysconf;

    /**
     * @var mysqli
     */
    protected $db;

    function __construct($sysconf, $obj_db)
    {
        $this->sysconf = $sysconf;
        $this->db = $obj_db;
    }

    /**
     * Verificar la existencia y estado de una socia
     * GET /api/v1/member/{id}/verify
     * 
     * @param string $member_id ID de la socia
     * @return JSON con estado de la socia
     */
    public function verifyMember($member_id)
    {
        if (empty($member_id)) {
            parent::withJson([
                'status' => 'error',
                'message' => 'El ID de la socia es obligatorio.'
            ]);
            return;
        }

        try {
            // Consultar la tabla member para verificar existencia
            $query = $this->db->query("
                SELECT m.member_id, m.member_name, m.member_type_id, m.expire_date,
                       mt.member_type_name
                FROM member m
                LEFT JOIN mst_member_type mt ON m.member_type_id = mt.member_type_id
                WHERE m.member_id = '" . $this->db->real_escape_string($member_id) . "'
                LIMIT 1
            ");

            if ($query->num_rows > 0) {
                $member = $query->fetch_assoc();
                
                // Verificar si la membresía está vigente
                $is_expired = false;
                if (!empty($member['expire_date']) && strtotime($member['expire_date']) < time()) {
                    $is_expired = true;
                }

                parent::withJson([
                    'status' => 'success',
                    'data' => [
                        'member_id' => $member['member_id'],
                        'member_name' => $member['member_name'],
                        'member_type' => $member['member_type_name'],
                        'expire_date' => $member['expire_date'],
                        'is_expired' => $is_expired
                    ]
                ]);
            } else {
                parent::withJson([
                    'status' => 'error',
                    'message' => 'Socia no encontrada en la base de datos.'
                ]);
            }
        } catch (Exception $e) {
            parent::withJson([
                'status' => 'error',
                'message' => 'Error al verificar socia: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Consultar disponibilidad de un libro por ISBN/ASIN o código de barras
     * GET /api/v1/item/{isbn}/status
     * 
     * @param string $isbn ISBN o ASIN del libro
     * @return JSON con estado de disponibilidad
     */
    /**
     * Obtener los prestamos activos de una socia
     * GET /api/v1/member/{id}/loans
     */
    public function getMemberLoans($member_id)
    {
        if (empty($member_id)) {
            parent::withJson(['status' => 'error', 'message' => 'El ID de la socia es obligatorio.']);
            return;
        }

        try {
            $query = $this->db->query("
                SELECT l.loan_id, l.item_code, l.loan_date, l.due_date, 
                       b.title, b.isbn_issn, b.image
                FROM loan l
                LEFT JOIN item i ON l.item_code = i.item_code
                LEFT JOIN biblio b ON i.biblio_id = b.biblio_id
                WHERE l.member_id = '" . $this->db->real_escape_string($member_id) . "' 
                  AND l.is_return = 0
                ORDER BY l.loan_date DESC
            ");

            $loans = [];
            if ($query) {
                while ($data = $query->fetch_assoc()) {
                    $loans[] = [
                        'loan_id'   => $data['loan_id'],
                        'item_code' => $data['item_code'],
                        'loan_date' => $data['loan_date'],
                        'due_date'  => $data['due_date'],
                        'title'     => $data['title'] ?? 'Titulo no disponible',
                        'isbn'      => $data['isbn_issn'] ?? '',
                        'image'     => $data['image'] ?? '',
                    ];
                }
            }

            parent::withJson(['status' => 'success', 'data' => $loans]);
        } catch (Exception $e) {
            parent::withJson(['status' => 'error', 'message' => 'Error al consultar prestamos: ' . $e->getMessage()]);
        }
    }

    public function getItemStatus($isbn)
    {
        if (empty($isbn)) {
            parent::withJson([
                'status' => 'error',
                'message' => 'El ISBN/ASIN es obligatorio.'
            ]);
            return;
        }

        try {
            // Buscar el item por ISBN o item_code
            $query = $this->db->query("
                SELECT i.item_code, i.item_status_id, i.coll_type_id, i.call_number,
                       b.biblio_id, b.title, b.isbn_issn, b.publisher,
                       COALESCE(GROUP_CONCAT(DISTINCT a.author_name ORDER BY ba.level SEPARATOR '; '), 'Autora Desconocida') AS author,
                       COUNT(DISTINCT CASE WHEN l.is_return = 0 THEN l.loan_id END) as active_loans
                FROM item i
                LEFT JOIN biblio b ON i.biblio_id = b.biblio_id
                LEFT JOIN biblio_author ba ON b.biblio_id = ba.biblio_id
                LEFT JOIN mst_author a ON ba.author_id = a.author_id
                LEFT JOIN loan l ON i.item_code = l.item_code
                WHERE i.item_code = '" . $this->db->real_escape_string($isbn) . "' 
                   OR b.isbn_issn = '" . $this->db->real_escape_string($isbn) . "'
                GROUP BY i.item_code
                LIMIT 1
            ");

            if ($query->num_rows > 0) {
                $item = $query->fetch_assoc();
                
                // Determinar disponibilidad
                $is_available = ($item['active_loans'] == 0);

                parent::withJson([
                    'status' => 'success',
                    'data' => [
                        'item_code' => $item['item_code'],
                        'title' => $item['title'],
                        'author' => $item['author'],
                        'isbn' => $item['isbn_issn'],
                        'call_number' => $item['call_number'],
                        'is_available' => $is_available,
                        'active_loans' => (int)$item['active_loans']
                    ]
                ]);
            } else {
                parent::withJson([
                    'status' => 'error',
                    'message' => 'Libro no encontrado en la biblioteca.'
                ]);
            }
        } catch (Exception $e) {
            parent::withJson([
                'status' => 'error',
                'message' => 'Error al consultar disponibilidad: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Registrar un préstamo
     * POST /api/v1/loan/borrow
     */
    public function createLoan()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $member_id = $input['member_id'] ?? '';
        $item_code = $input['item_code'] ?? '';

        if (empty($member_id) || empty($item_code)) {
            parent::withJson([
                'status' => 'error',
                'message' => 'member_id e item_code son obligatorios.'
            ]);
            return;
        }

        try {
            // Verificar socia
            $member_query = $this->db->query("SELECT member_id, member_name, expire_date FROM member WHERE member_id = '" . $this->db->real_escape_string($member_id) . "' LIMIT 1");
            if ($member_query->num_rows == 0) {
                parent::withJson(['status' => 'error', 'message' => 'La socia no existe.']);
                return;
            }
            $member = $member_query->fetch_assoc();

            // Verificar item: buscar por item_code o por ISBN/ASIN
            $safe_code = $this->db->real_escape_string($item_code);
            $item_query = $this->db->query("
                SELECT i.item_code, b.title 
                FROM item i 
                LEFT JOIN biblio b ON i.biblio_id = b.biblio_id 
                WHERE i.item_code = '{$safe_code}' 
                   OR b.isbn_issn = '{$safe_code}'
                LIMIT 1
            ");
            if ($item_query->num_rows == 0) {
                parent::withJson(['status' => 'error', 'message' => 'El libro no existe en la biblioteca.']);
                return;
            }
            $item = $item_query->fetch_assoc();
            $real_item_code = $item['item_code'];

            // Verificar si ya está prestado (usar el item_code real)
            $active_loan_query = $this->db->query("SELECT loan_id FROM loan WHERE item_code = '" . $this->db->real_escape_string($real_item_code) . "' AND is_return = 0 LIMIT 1");
            if ($active_loan_query->num_rows > 0) {
                parent::withJson(['status' => 'error', 'message' => 'El libro ya está prestado.']);
                return;
            }

            // Crear préstamo con el item_code real
            $loan_date = date('Y-m-d');
            $due_date = date('Y-m-d', strtotime('+15 days'));
            $insert_query = "INSERT INTO loan (item_code, member_id, loan_date, due_date, is_return, renewed, is_lent) VALUES ('" . $this->db->real_escape_string($real_item_code) . "', '" . $this->db->real_escape_string($member_id) . "', '$loan_date', '$due_date', 0, 0, 1)";

            if ($this->db->query($insert_query)) {
                parent::withJson([
                    'status' => 'success',
                    'message' => 'Préstamo registrado correctamente.',
                    'data' => [
                        'loan_id' => $this->db->insert_id,
                        'member_name' => $member['member_name'],
                        'item_title' => $item['title'],
                        'loan_date' => $loan_date,
                        'due_date' => $due_date
                    ]
                ]);
            } else {
                parent::withJson(['status' => 'error', 'message' => 'Error al registrar el préstamo: ' . $this->db->error]);
            }
        } catch (Exception $e) {
            parent::withJson(['status' => 'error', 'message' => 'Error al procesar préstamo: ' . $e->getMessage()]);
        }
    }

    /**
     * Registrar una devolución
     * POST /api/v1/loan/return
     */
    public function returnLoan()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $item_code = $input['item_code'] ?? '';

        if (empty($item_code)) {
            parent::withJson(['status' => 'error', 'message' => 'item_code es obligatorio.']);
            return;
        }

        try {
            // Buscar préstamo activo: buscar por item_code o por ISBN/ASIN
            $safe_code = $this->db->real_escape_string($item_code);
            $loan_query = $this->db->query("
                SELECT l.loan_id, l.member_id, l.due_date, m.member_name, b.title, l.item_code
                FROM loan l 
                LEFT JOIN member m ON l.member_id = m.member_id 
                LEFT JOIN item i ON l.item_code = i.item_code 
                LEFT JOIN biblio b ON i.biblio_id = b.biblio_id 
                WHERE (l.item_code = '{$safe_code}' OR b.isbn_issn = '{$safe_code}')
                  AND l.is_return = 0 
                LIMIT 1
            ");

            if ($loan_query->num_rows == 0) {
                parent::withJson(['status' => 'error', 'message' => 'El libro no consta como prestado actualmente.']);
                return;
            }

            $loan = $loan_query->fetch_assoc();
            $real_item_code = $loan['item_code'];
            $return_date = date('Y-m-d');
            $update_query = "UPDATE loan SET is_return = 1, return_date = '$return_date' WHERE loan_id = " . (int)$loan['loan_id'];

            if ($this->db->query($update_query)) {
                parent::withJson([
                    'status' => 'success',
                    'message' => 'Devolución registrada correctamente.',
                    'data' => [
                        'loan_id' => $loan['loan_id'],
                        'member_name' => $loan['member_name'],
                        'item_title' => $loan['title'],
                        'return_date' => $return_date
                    ]
                ]);
            } else {
                parent::withJson(['status' => 'error', 'message' => 'Error al registrar la devolución: ' . $this->db->error]);
            }
        } catch (Exception $e) {
            parent::withJson(['status' => 'error', 'message' => 'Error al procesar devolución: ' . $e->getMessage()]);
        }
    }
}
