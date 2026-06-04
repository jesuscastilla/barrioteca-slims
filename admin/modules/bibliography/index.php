<?php
/**
 * Copyright (C) 2007,2008,2009,2010  Arie Nugraha (dicarve@yahoo.com)
 */

/* Sección de Gestión de Bibliografía */

// Clave para autenticación
if (!defined('INDEX_AUTH')) {
    define('INDEX_AUTH', '1');
}

use SLiMS\AlLibrarian;
use SLiMS\Filesystems\Storage;
use SLiMS\Form\FormAjaxWithCustomField;
use SLiMS\Plugins;

// Clave para acceso completo a la base de datos
define('DB_ACCESS', 'fa');

if (!defined('SB')) {
    // Configuración principal del sistema
    require '../../../sysconfig.inc.php';
    // Iniciar la sesión
    require SB . 'admin/default/session.inc.php';
}
// Limitación de acceso por IP
require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-bibliography');

require SB . 'admin/default/session_check.inc.php';
require SIMBIO . 'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO . 'simbio_GUI/form_maker/simbio_form_table_AJAX.inc.php';
require SIMBIO . 'simbio_GUI/paging/simbio_paging.inc.php';
require SIMBIO . 'simbio_DB/datagrid/simbio_dbgrid.inc.php';
require SIMBIO . 'simbio_DB/simbio_dbop.inc.php';
require SIMBIO . 'simbio_FILE/simbio_file_upload.inc.php';
require MDLBS . 'system/biblio_indexer.inc.php';

// Comprobación de privilegios
$can_read = utility::havePrivilege('bibliography', 'r');
$can_write = utility::havePrivilege('bibliography', 'w');

if (!$can_read) {
    die('<div class="errorBox">' . __('No tienes autorización para ver esta sección') . '</div>');
}
# COMPROBAR ACCESO
if ($_SESSION['uid'] != 1) {
    if (!utility::haveAccess('bibliography.bibliography-list')) {
        die('<div class="errorBox">' . __('No tienes autorización para ver esta sección') . '</div>');
    }
}

// Ejecutar hook de inicialización
Plugins::getInstance()->execute(Plugins::BIBLIOGRAPHY_INIT);

// Cargar configuración
utility::loadSettings($dbs);

$in_pop_up = false;
// Comprobar si estamos en una ventana emergente
if (isset($_GET['inPopUp'])) {
    $in_pop_up = true;
}

// RDA: Contenido, Medio y Soporte
$rda_cmc = array('content' => 'Tipo de Contenido', 'media' => 'Tipo de Medio', 'carrier' => 'Tipo de Soporte');

/* ELIMINAR IMAGEN */
if (isset($_POST['removeImage']) && isset($_POST['bimg']) && isset($_POST['img'])) {
    $biblio_id = utility::filterData('bimg', 'post', true, true, true);
    $image_name = utility::filterData('img', 'post', true, true, true);

    $query_image = $dbs->query("SELECT biblio_id FROM biblio WHERE biblio_id='{$biblio_id}' AND image='{$image_name}'");
    if ($query_image->num_rows > 0) {
        $_delete = $dbs->query(sprintf('UPDATE biblio SET image=NULL WHERE biblio_id=%d', $biblio_id));
        $_delete2 = $dbs->query(sprintf('UPDATE search_biblio SET image=NULL WHERE biblio_id=%d', $biblio_id));
        if ($_delete) {
            $postImage = stripslashes($_POST['img']);
            $postImage = str_replace('/', '', $postImage);
            @unlink(sprintf(IMGBS . 'docs/%s', $postImage));
            utility::jsToastr('Bibliografía', str_replace('{imageFilename}', $_POST['img'], __('¡{imageFilename} eliminada con éxito!')), 'success');
            exit('<img src="../lib/minigalnano/createthumb.php?filename=images/default/image.png&width=130" class="img-fluid rounded" alt="">');
        }
    }
    exit();
}

/* OPERACIÓN DE REGISTRO */
if (isset($_POST['saveData']) AND $can_read AND $can_write) {
    # COMPROBAR ACCESO
    if ($_SESSION['uid'] != 1) {
        if (!utility::haveAccess('bibliography.bibliography-add')) {
            utility::jsToastr('Bibliografía', __('No tienes autorización para ver esta sección'), 'error');
            exit();
        }
    }
    if (!simbio_form_maker::isTokenValid()) {
        utility::jsToastr('Bibliografía', __('¡Token de envío de formulario no válido!'), 'error');
        exit();
    }
    $title = trim(strip_tags($_POST['title']));
    // Validar formulario
    if (empty($title)) {
        utility::jsToastr('Bibliografía', __('El título no puede estar vacío'), 'error');
        exit();
    } else {
        // Incluir campos personalizados si existen
        if (file_exists(MDLBS . 'bibliography/custom_fields.inc.php')) {
            include MDLBS . 'bibliography/custom_fields.inc.php';
        }

        // Crear instancia del indexador
        $indexer = new biblio_indexer($dbs);

        $data['title'] = $dbs->escape_string($title);
        $data['sor'] = trim($dbs->escape_string(strip_tags($_POST['sor'])));
        $data['edition'] = trim($dbs->escape_string(strip_tags($_POST['edition'])));
        $data['gmd_id'] = $_POST['gmdID'];
        $data['isbn_issn'] = trim($dbs->escape_string(strip_tags($_POST['isbn_issn'])));

        $class = str_ireplace('NEW:', '', trim(strip_tags($_POST['class'])));
        $data['classification'] = trim($dbs->escape_string(strip_tags($class)));
        $data['uid'] = $_SESSION['uid'];

        // Comprobar editorial
        if (stripos($_POST['publisherID'], 'NEW:') === 0) {
            $new_publisher = str_ireplace('NEW:', '', trim(strip_tags($_POST['publisherID'])));
            $new_id = utility::getID($dbs, 'mst_publisher', 'publisher_id', 'publisher_name', $new_publisher);
            $data['publisher_id'] = $new_id;
        } else if (intval($_POST['publisherID']) > 0) {
            $data['publisher_id'] = intval($_POST['publisherID']);
        }

        $data['publish_year'] = trim($dbs->escape_string(strip_tags($_POST['year'])));
        $data['collation'] = trim($dbs->escape_string(strip_tags($_POST['collation'])));
        $data['series_title'] = trim($dbs->escape_string(strip_tags($_POST['seriesTitle'])));
        $data['call_number'] = trim($dbs->escape_string(strip_tags($_POST['callNumber'])));
        $data['language_id'] = trim($dbs->escape_string(strip_tags($_POST['languageID'])));

        // Comprobar lugar de publicación
        if (stripos($_POST['placeID'], 'NEW:') === 0) {
            $new_place = str_ireplace('NEW:', '', trim(strip_tags($_POST['placeID'])));
            $new_id = utility::getID($dbs, 'mst_place', 'place_id', 'place_name', $new_place);
            $data['publish_place_id'] = $new_id;
        } else if (intval($_POST['placeID']) > 0) {
            $data['publish_place_id'] = intval($_POST['placeID']);
        }

        $data['notes'] = trim($dbs->escape_string(strip_tags($_POST['notes'], '<br><p><div><span><i><em><strong><b><code>s')));
        $data['opac_hide'] = ($_POST['opacHide'] == '0') ? 'literal{0}' : '1';
        $data['promoted'] = ($_POST['promote'] == '0') ? 'literal{0}' : '1';

        $data['input_date'] = date('Y-m-d H:i:s');
        $data['last_update'] = date('Y-m-d H:i:s');

        // Subida de imagen de portada
        $images_disk = Storage::images();
        if (!empty($_FILES['image']) AND $_FILES['image']['size']) {
            $img_title = $data['title'].'_'.date("YmdHis");
            if(strlen($data['title']) > 70){
                $img_title = substr($data['title'], 0, 70).'_'.date("YmdHis");
            }

            $image_upload = $images_disk->upload('image', function($images) use($sysconf) {
                $images->isExtensionAllowed($sysconf['allowed_images']);
                $images->isLimitExceeded($sysconf['max_image_upload']*1024);
                if (!empty($images->getError())) $images->destroyIfFailed();
                if (empty($images->getError())) $images->cleanExifInfo();
            })->as('docs/' . strtolower('cover_'. preg_replace("/[^a-zA-Z0-9]+/", "-", $img_title)));

            if ($image_upload->getUploadStatus()) {
                $data['image'] = $dbs->escape_string($image_upload->getUploadedFileName());
                utility::jsToastr('Bibliografía', __('Imagen subida con éxito'), 'success');
            } else {
                $data['image'] = NULL;
                utility::jsToastr('Bibliografía', __('Fallo al subir la imagen').'<br/>'.$image_upload->getError(), 'error');
            }
        }

        // Operación en la base de datos
        $sql_op = new simbio_dbop($dbs);
        if (isset($_POST['updateRecordID'])) {
            $updateRecordID = (integer)$_POST['updateRecordID'];
            unset($data['input_date']);
            unset($data['uid']);
            
            $update = $sql_op->update('biblio', $data, 'biblio_id=' . $updateRecordID);
            if ($update) {
                $indexer->indexBiblio($updateRecordID);
                utility::jsToastr('Bibliografía', __('Registro actualizado con éxito'), 'success');
            }
        } else {
            $insert = $sql_op->insert('biblio', $data);
            if ($insert) {
                $newID = $sql_op->insert_id;
                $indexer->indexBiblio($newID);
                utility::jsToastr('Bibliografía', __('Nuevo registro añadido con éxito'), 'success');
            }
        }
    }
    exit();
}
