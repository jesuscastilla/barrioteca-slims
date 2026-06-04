<?php
/**
 * Copyright (C) 2007,2008  Arie Nugraha (dicarve@yahoo.com)
 */

use SLiMS\Plugins;
use SLiMS\Filesystems\Storage;
use SLiMS\Form\FormAjaxWithCustomField;

/* Sección de Gestión de Membresía (Socias) */

// Clave para autenticación
define('INDEX_AUTH', '1');
// Clave para acceso completo a la base de datos
define('DB_ACCESS', 'fa');

if (!defined('SB')) {
    // Configuración principal del sistema
    require '../../../sysconfig.inc.php';
    // Iniciar la sesión
    require SB.'admin/default/session.inc.php';
}
// Limitación de acceso por IP
require LIB.'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-membership');

require SB.'admin/default/session_check.inc.php';
require SIMBIO.'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO.'simbio_GUI/form_maker/simbio_form_table_AJAX.inc.php';
require SIMBIO.'simbio_GUI/paging/simbio_paging.inc.php';
require SIMBIO.'simbio_DB/datagrid/simbio_dbgrid.inc.php';
require SIMBIO.'simbio_DB/simbio_dbop.inc.php';
require SIMBIO.'simbio_UTILS/simbio_date.inc.php';
require SIMBIO.'simbio_FILE/simbio_file_upload.inc.php';

// Comprobación de privilegios
$can_read = utility::havePrivilege('membership', 'r');
$can_write = utility::havePrivilege('membership', 'w');

if (!$can_read) {
    die('<div class="errorBox">No tienes suficientes privilegios para ver esta sección</div>');
}
# COMPROBAR ACCESO
if ($_SESSION['uid'] != 1) {
    if (!utility::haveAccess('membership.view-member-list')) {
        die('<div class="errorBox">' . __('No tienes autorización para ver esta sección') . '</div>');
    }
}
// Ejecutar hook de inicialización
Plugins::getInstance()->execute(Plugins::MEMBERSHIP_INIT);

/* ELIMINAR IMAGEN DE SOCIA */
if (isset($_POST['removeImage']) && isset($_POST['mimg']) && isset($_POST['img'])) {
    $member_id = utility::filterData('mimg', 'post', true, true, true);
    $image_name = utility::filterData('img', 'post', true, true, true);
    $query_image = $dbs->query("SELECT member_id FROM member WHERE member_id='{$member_id}' AND member_image='{$image_name}'");
    if (!empty($query_image->num_rows)) {
        $_delete = $dbs->query(sprintf("UPDATE member SET member_image=NULL WHERE member_id='%s'", $member_id));
        if ($_delete) {
            $image = Storage::images();
            $postImage = stripslashes($_POST['img']);
            $postImage = str_replace('/', '', $postImage);
            $imagePath = sprintf('persons/%s', $postImage);
            if (!empty($postImage) && $image->isExists($imagePath)) {
                @Storage::images()->delete($imagePath);
            }
            exit('<script type="text/javascript">alert(\''.str_replace('{imageFilename}', $postImage, __('¡{imageFilename} eliminada con éxito!')).'\'); $(\'#memberImage, #imageFilename\').remove();</script>');
        }
    }
    exit();
}

/* Proceso de actualización/guardado de socia */
if (isset($_POST['saveData']) && $can_read && $can_write) {
    $memberID = trim($_POST['memberID']);
    $memberName = trim($_POST['memberName']);
    $birthDate = trim($_POST['birthDate']);
    $mpasswd1 = trim($_POST['memberPasswd']);
    $mpasswd2 = trim($_POST['memberPasswd2']);
    
    if (empty($memberID) OR empty($memberName) OR empty($birthDate)) {
        toastr(__('El ID de socia, el nombre y la fecha de nacimiento no pueden estar vacíos'))->error();
        exit();
    } else if (($mpasswd1 OR $mpasswd2) && ($mpasswd1 !== $mpasswd2)) {
        toastr(__('La confirmación de la contraseña no coincide.'))->error();
        exit();
    } else {
        // Incluir campos personalizados
        if (file_exists(MDLBS.'membership/member_custom_fields.inc.php')) {
            include MDLBS.'membership/member_custom_fields.inc.php';
        }

        $data['member_id'] = $dbs->escape_string($memberID);
        $data['member_name'] = $dbs->escape_string($memberName);
        $data['member_type_id'] = (integer)$_POST['memberTypeID'];
        $data['inst_name'] = trim($dbs->escape_string(strip_tags($_POST['instName'])));
        $data['gender'] = trim($dbs->escape_string(strip_tags($_POST['gender'])));
        $data['birth_date'] = trim($dbs->escape_string(strip_tags($_POST['birthDate'])));
        $data['birth_date'] = $data['birth_date'] == '' ? null : $data['birth_date'];
        $data['register_date'] = trim($dbs->escape_string(strip_tags($_POST['regDate'])));
        
        $member_since = trim($dbs->escape_string(strip_tags($_POST['sinceDate'])));
        if (isset($_POST['updateRecordID'])) {
            $data['member_since_date'] = $member_since;
        } else {
            $data['member_since_date'] = $member_since ?: $data['register_date'];
        }

        $data['expire_date'] = trim($dbs->escape_string(strip_tags($_POST['expDate'])));
        $data['pin'] = trim($dbs->escape_string(strip_tags($_POST['memberPIN'])));
        $data['member_address'] = trim($dbs->escape_string(strip_tags($_POST['memberAddress'])));
        $data['member_phone'] = trim($dbs->escape_string(strip_tags($_POST['memberPhone'])));
        $data['member_notes'] = trim($dbs->escape_string(strip_tags($_POST['memberNotes'])));
        $data['member_email'] = trim($dbs->escape_string(strip_tags($_POST['memberEmail'])));
        $data['is_pending'] = isset($_POST['isPending'])? intval($_POST['isPending']) : '0';
        $data['input_date'] = date('Y-m-d');
        $data['last_update'] = date('Y-m-d');

        // Subida de foto de socia
        $imageDisk = Storage::images();
        if (!empty($_FILES['image']) AND $_FILES['image']['size']) {
            $upload = $imageDisk->upload('image', function($image) use($sysconf) {
                $image->isExtensionAllowed($sysconf['allowed_images']);
                $image->isLimitExceeded($sysconf['max_image_upload']*1024);
                $image->isImageFile();
                if (!empty($image->getError())) $image->destroyIfFailed();
                if (empty($image->getError())) $image->cleanExifInfo();
            })->as('persons/' . 'member_'.$data['member_id']);
            
            if ($upload->getUploadStatus()) {
                $data['member_image'] = $dbs->escape_string($upload->getUploadedFileName());
            } else {
                utility::jsToastr('Membresía', __('Fallo al subir la imagen').'<br/>'.$upload->getError(), 'error');
            }
        }

        // Contraseña
        if (($mpasswd1 AND $mpasswd2) AND ($mpasswd1 === $mpasswd2)) {
            $data['mpasswd'] = password_hash($mpasswd2, PASSWORD_BCRYPT);
        }

        // Operación en base de datos
        $sql_op = new simbio_dbop($dbs);
        if (isset($_POST['updateRecordID'])) {
            unset($data['input_date']);
            $updateRecordID = $dbs->escape_string(trim($_POST['updateRecordID']));
            $update = $sql_op->update('member', $data, "member_id='$updateRecordID'");
            if ($update) {
                toastr(__('Datos de la socia actualizados con éxito'))->success();
            }
        } else {
            $insert = $sql_op->insert('member', $data);
            if ($insert) {
                toastr(__('Nueva socia añadida con éxito'))->success();
            }
        }
    }
    exit();
}
