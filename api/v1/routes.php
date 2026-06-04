<?php

/**
 * @Date                : 2017-07-04 15:28:21
 * @File name           : routes.php
 * @Description         : Definición de rutas para la API de SLiMS
 */

$header = getallheaders();

if ((isset($header['SLiMS-Http-Cache']) || isset($header['slims-http-cache']))) {
    if ($sysconf['http']['cache']['lifetime'] > 0) header('Cache-Control: max-age=' . $sysconf['http']['cache']['lifetime']);
}

/*----------  Requerir dependencias  ----------*/
require 'lib/router.inc.php';
require __DIR__ . '/controllers/HomeController.php';
require __DIR__ . '/controllers/BiblioController.php';
require __DIR__ . '/controllers/MemberController.php';
require __DIR__ . '/controllers/SubjectController.php';
require __DIR__ . '/controllers/ItemController.php';
require __DIR__ . '/controllers/LoanController.php';
require __DIR__ . '/controllers/CirculationController.php';

/*----------  Crear objeto router  ----------*/
$router = new Router($sysconf, $dbs);
$router->setBasePath('api');

/*----------  Crear rutas públicas  ----------*/
$router->map('GET', '/', 'HomeController@index');
$router->map('GET', '/biblio/popular', 'BiblioController@getPopular');
$router->map('GET', '/biblio/latest', 'BiblioController@getLatest');
$router->map('GET', '/subject/popular', 'SubjectController@getPopular');
$router->map('GET', '/subject/latest', 'SubjectController@getLatest');
$router->map('GET', '/member/top', 'MemberController@getTopMember');
$router->map('GET', '/biblio/gmd/[*:gmd]', 'BiblioController@getByGmd');
$router->map('GET', '/biblio/coll_type/[*:coll_type]', 'BiblioController@getByCollType');
$router->map('GET', '/biblio/search', 'BiblioController@search');

/*----------  Rutas de circulación (Integración PWA)  ----------*/
$router->map('GET', '/member/[*:member_id]/verify', 'CirculationController@verifyMember');
$router->map('GET', '/item/[*:isbn]/status', 'CirculationController@getItemStatus');
$router->map('POST', '/loan/borrow', 'CirculationController@createLoan');
$router->map('POST', '/loan/return', 'CirculationController@returnLoan');

/*----------  Administración  ----------*/
$router->map('GET', '/biblio/total/all', 'BiblioController@getTotalAll');
$router->map('GET', '/item/total/all', 'ItemController@getTotalAll');
$router->map('GET', '/item/total/lent', 'ItemController@getTotalLent');
$router->map('GET', '/item/total/available', 'ItemController@getTotalAvailable');
$router->map('GET', '/loan/summary', 'LoanController@getSummary');
$router->map('GET', '/loan/getdate/[*:start_date]', 'LoanController@getDate');
$router->map('GET', '/loan/summary/[*:date]', 'LoanController@getSummaryDate');

/*----------  Resumen de circulación para admin  ----------*/
$router->map('GET', '/admin/circulation/summary', 'LoanController@getSummary');

/*----------  Rutas personalizadas basadas en hooks de plugins  ----------*/
\SLiMS\Plugins::getInstance()->execute('custom_api_route', ['router' => $router]);

/*----------  Ejecutar coincidencia de ruta  ----------*/
$router->run();

// No requiere plantilla HTML
exit();
