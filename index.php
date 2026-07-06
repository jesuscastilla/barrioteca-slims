<?php
/**
 * SENAYAN application bootstrap files
 * 
 * Restaurado desde el OPAC original.
 * Se ha añadido un botón "Staff" en la cabecera para acceder al panel de administración.
 */

use SLiMS\{Opac,Plugins};

// key to authenticate
define('INDEX_AUTH', '1');

// required file
require 'sysconfig.inc.php';

// Cleanup SQL Injection and Common XSS
$sanitizer->cleanUp(exception: ['contentDesc','fieldEnc']);

// IP based access limitation
require LIB.'ip_based_access.inc.php';
do_checkIP('opac');

// member session params
require LIB.'member_session.inc.php';
if ($sysconf['template']['base'] == 'html') {
  require SIMBIO.'simbio_GUI/template_parser/simbio_template_parser.inc.php';
}

// default opac variable
$opacVariable = [
    // default library info
    'page_title' => $sysconf['library_subname'].' | '.$sysconf['library_name'],

    // total opac result page
    'info' => __('Web Online Public Access Catalog - Use the search options to find documents quickly'),

    // total opac result page
    'total_pages' => 1,

    // default header info — incluye botón de acceso staff
    'header_info' => '<a href="admin/" style="display:inline-block;background:#141414;color:#f5f5f0;padding:4px 12px;border-radius:6px;text-decoration:none;font-weight:600;font-size:0.7rem;text-transform:uppercase;letter-spacing:0.05em;margin-right:8px;">🔐 Staff</a>'
        . (utility::isMemberLogin() ? '<div class="alert alert-info alert-member-login" id="memberLoginInfo">'.__('You are currently Logged on as member').': <strong>'.$_SESSION['m_name'].' (<em>'.$_SESSION['m_email'].'</em>)</strong> <a id="memberLogout" href="index.php?p=member&logout=1">'.__('LOGOUT').'</a></div>' : ''),

    // HTML metadata
    'metadata' => '',

    // JS
    'js' => '',

    // searched words for javascript highlight
    'searched_words_js_array' => '',
    'available_languages' => $localisation->getLanguages(),

    // Sanitizer
    'sanitizer' => $sanitizer,
];

// OPAC Instance
$opacClass = config('custom_opac', $slimsOpac = Opac::class);
$opac = new $opacClass($opacVariable, $sysconf, $dbs);

if (!$opac instanceof Opac) {
  throw new Exception("{$opacClass} is not instance of {$slimsOpac}");
}

// running hook to override process/variable before
// content load
$opac->hookBeforeContent(function($opac){
  // Set header for CSP
  $opac->setCsp();
  $opac->setHeader('X-Content-Type-Options', 'nonsniff');
  
  // running plugin based on hook
  Plugins::getInstance()->execute(Plugins::CONTENT_BEFORE_LOAD, [$opac]);
});

// Path process or show welcome page
$opac->onWeb(function($opac){
  $opac->handle('p')->orWelcome();
})->onCli();

// running hook to override process/variable after
// content load
$opac->hookAfterContent(function($opac){
  Plugins::getInstance()->execute(Plugins::CONTENT_AFTER_LOAD, [$opac]);
});

// templating
$opac->parseToTemplate();