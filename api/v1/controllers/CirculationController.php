<?php
/**
 * @Created by          : Manus AI
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
     * Verificar la existencia y estado de un socio
     * GET /api/v1/member/{id}/verify
     * 
     * @param string $member_id ID del socio
     * @return JSON con estado del socio
     */
    public function verifyMember($member_id)
    {
        if (empty($member_id)) {
            parent::withJson([
                'status' => 'error',
                'message' => 'El ID del socio es obligatorio.'
            ]);
            return;
        }

        try {
            // Consultar la tabla member para verificar existencia
            $query = $this->db->query("
                SELECT m.member_id, m.member_name, m.member_type_id, m.expire_date, 
                       mt.member_type_name, m.member_status
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
                        'is_expired' => $is_expired,
                        'member_status' => $member['member_status']
                    ]
                ]);
            } else {
                parent::withJson([
                    'status' => 'error',
                    'message' => 'Socio no encontrado en la base de datos.'
                ]);
            }
        } catch (Exception $e) {
            parent::withJson([
                'status' => 'error',
                'message' => 'Error al verificar socio: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Consultar disponibilidad de un libro por ISBN/ASIN
     * GET /api/v1/item/{isbn}/status
     * 
     * @param string $isbn ISBN o ASIN del libro
     * @return JSON con estado de disponibilidad
     */
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
                       b.biblio_id, b.title, b.isbn_issn, b.author, b.publisher,
                       COUNT(DISTINCT CASE WHEN l.is_lent = 1 AND l.is_return = 0 THEN l.loan_id END) as active_loans
                FROM item i
                LEFT JOIN biblio b ON i.biblio_id = b.biblio_id
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
     * 
     * Parámetros esperados (JSON):
     * - member_id: ID del socio
     * - item_code: Código del item a prestar
     * 
     * @return JSON con resultado del préstamo
     */
    public function createLoan()
    {
        // Leer el JSON del body
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
            // Verificar que el socio existe
            $member_query = $this->db->query("
                SELECT m.member_id, m.member_name, m.expire_date
                FROM member m
                WHERE m.member_id = '" . $this->db->real_escape_string($member_id) . "'
                LIMIT 1
            ");

            if ($member_query->num_rows == 0) {
                parent::withJson([
                    'status' => 'error',
                    'message' => 'El socio no existe.'
                ]);
                return;
            }

            $member = $member_query->fetch_assoc();

            // Verificar que la membresía no esté expirada
            if (!empty($member['expire_date']) && strtotime($member['expire_date']) < time()) {
                parent::withJson([
                    'status' => 'error',
                    'message' => 'La membresía del socio ha expirado.'
                ]);
                return;
            }

            // Verificar que el item existe y está disponible
            $item_query = $this->db->query("
                SELECT i.item_code, i.item_status_id, b.title
                FROM item i
                LEFT JOIN biblio b ON i.biblio_id = b.biblio_id
                WHERE i.item_code = '" . $this->db->real_escape_string($item_code) . "'
                LIMIT 1
            ");

            if ($item_query->num_rows == 0) {
                parent::withJson([
                    'status' => 'error',
                    'message' => 'El libro no existe en la biblioteca.'
                ]);
                return;
            }

            $item = $item_query->fetch_assoc();

            // Verificar que el item no esté ya prestado
            $active_loan_query = $this->db->query("
                SELECT loan_id FROM loan
                WHERE item_code = '" . $this->db->real_escape_string($item_code) . "'
                AND is_lent = 1 AND is_return = 0
                LIMIT 1
            ");

            if ($active_loan_query->num_rows > 0) {
                parent::withJson([
                    'status' => 'error',
                    'message' => 'El libro ya está prestado.'
                ]);
                return;
            }

            // Crear el préstamo
            $loan_date = date('Y-m-d H:i:s');
            $due_date = date('Y-m-d', strtotime('+15 days'));

            $insert_query = "
                INSERT INTO loan (item_code, member_id, loan_date, due_date, is_lent, is_return, renewals)
                VALUES (
                    '" . $this->db->real_escape_string($item_code) . "',
                    '" . $this->db->real_escape_string($member_id) . "',
                    '" . $this->db->real_escape_string($loan_date) . "',
                    '" . $this->db->real_escape_string($due_date) . "',
                    1,
                    0,
                    0
                )
            ";

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
                parent::withJson([
                    'status' => 'error',
                    'message' => 'Error al registrar el préstamo: ' . $this->db->error
                ]);
            }
        } catch (Exception $e) {
            parent::withJson([
                'status' => 'error',
                'message' => 'Error al procesar préstamo: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Registrar una devolución
     * POST /api/v1/loan/return
     * 
     * Parámetros esperados (JSON):
     * - item_code: Código del item a devolver
     * 
     * @return JSON con resultado de la devolución
     */
    public function returnLoan()
    {
        // Leer el JSON del body
        $input = json_decode(file_get_contents('php://input'), true);
        
        $item_code = $input['item_code'] ?? '';

        if (empty($item_code)) {
            parent::withJson([
                'status' => 'error',
                'message' => 'item_code es obligatorio.'
            ]);
            return;
        }

        try {
            // Buscar el préstamo activo
            $loan_query = $this->db->query("
                SELECT l.loan_id, l.member_id, l.due_date, m.member_name, b.title
                FROM loan l
                LEFT JOIN member m ON l.member_id = m.member_id
                LEFT JOIN item i ON l.item_code = i.item_code
                LEFT JOIN biblio b ON i.biblio_id = b.biblio_id
                WHERE l.item_code = '" . $this->db->real_escape_string($item_code) . "'
                AND l.is_lent = 1 AND l.is_return = 0
                LIMIT 1
            ");

            if ($loan_query->num_rows == 0) {
                parent::withJson([
                    'status' => 'error',
                    'message' => 'El libro no consta como prestado actualmente.'
                ]);
                return;
            }

            $loan = $loan_query->fetch_assoc();

            // Registrar la devolución
            $return_date = date('Y-m-d H:i:s');
            $is_overdue = (strtotime($loan['due_date']) < time()) ? 1 : 0;

            $update_query = "
                UPDATE loan
                SET is_return = 1, return_date = '" . $this->db->real_escape_string($return_date) . "', is_lent = 0
                WHERE loan_id = " . (int)$loan['loan_id']
            ;

            if ($this->db->query($update_query)) {
                parent::withJson([
                    'status' => 'success',
                    'message' => 'Devolución registrada correctamente.',
                    'data' => [
                        'loan_id' => $loan['loan_id'],
                        'member_name' => $loan['member_name'],
                        'item_title' => $loan['title'],
                        'return_date' => $return_date,
                        'is_overdue' => $is_overdue
                    ]
                ]);
            } else {
                parent::withJson([
                    'status' => 'error',
                    'message' => 'Error al registrar la devolución: ' . $this->db->error
                ]);
            }
        } catch (Exception $e) {
            parent::withJson([
                'status' => 'error',
                'message' => 'Error al procesar devolución: ' . $e->getMessage()
            ]);
        }
    }
}
