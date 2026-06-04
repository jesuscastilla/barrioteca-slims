<?php
/**
 * Copyright (C) 2007,2008  Arie Nugraha (dicarve@yahoo.com)
 */

/* Sección de Circulación (Préstamos y Devoluciones) */

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
do_checkIP('smc-circulation');

require SB.'admin/default/session_check.inc.php';
require SIMBIO.'simbio_GUI/form_maker/simbio_form_element.inc.php';

// Comprobación de privilegios
$can_read = utility::havePrivilege('circulation', 'r');
$can_write = utility::havePrivilege('circulation', 'w');

if (!($can_read AND $can_write)) {
    die('<div class="errorBox">'.__('No tienes suficientes privilegios para ver esta sección').'</div>');
}

// Comprobar si hay una transacción en curso
if (isset($_SESSION['memberID']) AND !empty($_SESSION['memberID'])) {
    define('DIRECT_INCLUDE', true);
    include MDLBS.'circulation/circulation_action.php';
} else {
?>
<div class="menuBox">
  <div class="menuBoxInner circulationIcon">
    <div class="per_title">
	    <h2><?php echo __('Circulación'); ?></h2>
    </div>
    <div class="infoBox">
        <?php echo __('CIRCULACIÓN - Introduce el ID de una socia para comenzar la transacción'); ?>
    </div>
    <div class="sub_section">
      <form id="startCirc" action="<?php echo MWB; ?>circulation/circulation_action.php" method="post" class="form-inline">
      <span class="mr-2"><?php echo __('ID de Socia'); ?></span>
      <?php
      // Crear selector AJAX
      $ajaxDD = new simbio_fe_AJAX_select();
      $ajaxDD->element_name = 'memberID';
      $ajaxDD->element_css_class = 'form-control col-3 ajaxInputField';
      $ajaxDD->handler_URL = MWB.'membership/member_AJAX_response.php';
      echo $ajaxDD->out();
      ?>
      <input type="submit" value="<?php echo __('Iniciar Transacción'); ?>" name="start" id="start" class="s-btn btn btn-default" />
      </form>
    </div>
  </div>
</div>
<?php 
    if (isset($_POST['finishID'])) {
      $msg = str_ireplace('{member_id}', $_POST['finishID'], __('La transacción con la socia {member_id} ha finalizado con éxito'));
      echo '<div class="infoBox">'.$msg.'</div>';
    }
}
