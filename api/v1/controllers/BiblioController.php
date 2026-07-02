<?php

/**
 * @author              : Waris Agung Widodo
 * @Date                : 2017-07-05 12:15:12
 * @Last Modified by    : ido
 * @Last Modified time  : 2017-07-05 15:08:08
 *
 * Copyright (C) 2017  Waris Agung Widodo (ido.alit@gmail.com)
 */

require_once 'Controller.php';
require_once __DIR__ . '/../helpers/Image.php';
require_once __DIR__ . '/../helpers/Cache.php';

class BiblioController extends Controller
{

    use Image;

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

    public function getPopular()
    {
        $cache_name = 'biblio_popular';
        if (!is_null($json = Cache::get($cache_name))) return parent::withJson($json);

        $limit = $this->sysconf['template']['classic_popular_collection_item'];
        $sql = "SELECT b.biblio_id, b.title, b.image, COUNT(*) AS total
          FROM loan AS l
          LEFT JOIN item AS i ON l.item_code=i.item_code
          LEFT JOIN biblio AS b ON i.biblio_id=b.biblio_id
          WHERE b.title IS NOT NULL AND b.opac_hide < 1
          GROUP BY b.biblio_id
          ORDER BY total DESC
          LIMIT {$limit}";

        $query = $this->db->query($sql);
        $return = array();
        while ($data = $query->fetch_assoc()) {
            $data['image'] = $this->getImagePath($data['image']);
            $return[] = $data;
        }
        if ($query->num_rows < $limit) {
            $a_not_in = array ();
            foreach ($return as $k => $v) {
                $a_not_in[$k] = $v['biblio_id'];
            }
            $not_in = '('.implode(', ', $a_not_in).')';
            $need = $limit - $query->num_rows;
            if ($need < 0) {
                $need = $limit;
            }
            $sql = "SELECT biblio_id, title, image FROM biblio WHERE opac_hide < 1 AND biblio_id NOT IN ".$not_in." ORDER BY last_update DESC LIMIT {$need}";
            $query = $this->db->query($sql);
            while ($data = $query->fetch_assoc()) {
                $data['image'] = $this->getImagePath($data['image']);
                $return[] = $data;
            }
        }

        Cache::set($cache_name, json_encode($return));
        parent::withJson($return);
    }

    public function getLatest() {
        $limit = 6;

        $sql = "SELECT biblio_id, title, image
          FROM biblio WHERE opac_hide < 1
          ORDER BY last_update DESC
          LIMIT {$limit}";

        $query = $this->db->query($sql);
        $return = array();
        while ($data = $query->fetch_assoc()) {
            $data['image'] = $this->getImagePath($data['image']);
            $return[] = $data;
        }

        parent::withJson($return);
    }

    public function getTotalAll()
    {
        $query = $this->db->query("SELECT COUNT(biblio_id) FROM biblio WHERE opac_hide < 1");
        parent::withJson([
            'data' => ($query->fetch_row())[0]
        ]);
    }

    public function getByGmd($gmd) {
        $limit = 3;
        $sql = "SELECT b.biblio_id, b.title, b.image, b.notes
          FROM biblio AS b, mst_gmd AS g
          WHERE b.gmd_id=g.gmd_id AND g.gmd_name='$gmd' AND b.opac_hide < 1
          ORDER BY b.last_update DESC
          LIMIT {$limit}";
        $query = $this->db->query($sql);
        $return = array();
        while ($data = $query->fetch_assoc()) {
            $data['image'] = $this->getImagePath($data['image']);
            $return[] = $data;
        }
    
        parent::withJson($return);
    }

    public function getByCollType($coll_type) {
        $limit = 3;
        $sql = "SELECT b.biblio_id, b.title, b.image, b.notes
          FROM biblio AS b, item AS i, mst_coll_type AS c
          WHERE b.biblio_id=i.biblio_id AND i.coll_type_id=c.coll_type_id AND c.coll_type_name='$coll_type' AND b.opac_hide < 1
          ORDER BY b.last_update DESC
          LIMIT {$limit}";
        $query = $this->db->query($sql);
        $return = array();
        while ($data = $query->fetch_assoc()) {
            $data['image'] = $this->getImagePath($data['image']);
            $return[] = $data;
        }
    
        parent::withJson($return);
    }

    /**
     * Búsqueda en catálogo por título, autor o ISBN
     * GET /api/v1/biblio/search?q={término}
     * Usado por la PWA Barrioteca Acalencá
     *
     * @return JSON array con resultados de búsqueda
     */
    public function search()
    {
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';

        if (empty($q)) {
            parent::withJson([]);
            return;
        }

        $safe_q = $this->db->real_escape_string($q);

        $sql = "SELECT
                    b.biblio_id,
                    b.title,
                    COALESCE(GROUP_CONCAT(DISTINCT a.author_name ORDER BY ba.level SEPARATOR '; '), 'Autora Desconocida') AS author,
                    b.isbn_issn,
                    b.image,
                    b.notes,
                    MIN(i.item_code) AS item_code,
                    SUM(CASE WHEN l.is_return = 0 AND l.loan_id IS NOT NULL THEN 1 ELSE 0 END) AS active_loans
                FROM biblio b
                LEFT JOIN item i ON b.biblio_id = i.biblio_id
                LEFT JOIN loan l ON i.item_code = l.item_code
                LEFT JOIN biblio_author ba ON b.biblio_id = ba.biblio_id
                LEFT JOIN mst_author a ON ba.author_id = a.author_id
                WHERE
                    b.opac_hide < 1 AND (
                        b.title        LIKE '%{$safe_q}%' OR
                        a.author_name  LIKE '%{$safe_q}%' OR
                        b.isbn_issn    LIKE '%{$safe_q}%'
                    )
                GROUP BY b.biblio_id
                ORDER BY b.last_update DESC
                LIMIT 30";

        $query = $this->db->query($sql);
        $results = [];

        if ($query) {
            while ($data = $query->fetch_assoc()) {
                $results[] = [
                    'biblio_id'    => $data['biblio_id'],
                    'title'        => $data['title'],
                    'author'       => $data['author'] ?: 'Autora Desconocida',
                    'isbn_issn'    => $data['isbn_issn'],
                    'image'        => $this->getImagePath($data['image']),
                    'notes'        => $data['notes'] ?? '',
                    'item_code'    => $data['item_code'],
                    'is_available' => ((int)$data['active_loans'] === 0),
                ];
            }
        }

        parent::withJson($results);
    }
}