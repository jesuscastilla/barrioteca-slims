<?php
/**
 * Copyright (C) 2007,2008  Arie Nugraha (dicarve@yahoo.com)
 */

/* Elementos del submenú del módulo bibliográfico */

do_checkIP('smc');
do_checkIP('smc-bibliography');

$menu['bibliography.header-bibliography'] = array('Header', __('BIBLIOGRAFÍA'));
$menu['bibliography.bibliography-list'] = array(__('Lista Bibliográfica'), MWB.'bibliography/index.php', __('Mostrar Datos Bibliográficos Existentes'));
$menu['bibliography.bibliography-add'] = array(__('Añadir Nueva Bibliografía'), MWB.'bibliography/index.php?action=detail', __('Añadir Nuevos Datos Bibliográficos/Catálogo'));

$menu['bibliography.header-items'] = array('Header', __('EJEMPLARES'));
$menu['bibliography.item-list'] = array(__('Lista de Ejemplares'), MWB.'bibliography/item.php', __('Mostrar Lista de Ejemplares de la Biblioteca'));
$menu['bibliography.checkout-items'] = array(__('Ejemplares en Préstamo'), MWB.'bibliography/checkout_item.php', __('Mostrar Lista de Ejemplares Prestados'));

$menu['bibliography.header-copy-cataloguing'] = array('Header', __('CATALOGACIÓN COOPERATIVA'));
$menu['bibliography.marc-sru'] = array(__('MARC SRU'), MWB.'bibliography/marcsru.php', __('Obtener Datos Bibliográficos de Otros Servicios MARC'));
$menu['bibliography.z3950-sru'] = array(__('Z3950 SRU'), MWB.'bibliography/z3950sru.php', __('Obtener Datos Bibliográficos de Servicios Web Z3950 SRU'));
$menu['bibliography.p2p-service'] = array(__('Servicio P2P'), MWB.'bibliography/p2p.php', __('Obtener Datos Bibliográficos de Otros Servicios Web SLiMS'));
$menu['bibliography.isbn-lookup'] = array(__('Búsqueda por ISBN'), MWB.'bibliography/isbn_lookup.php', __('Catalogar libros automáticamente mediante ISBN (Google Books, Open Library, BNE)'));

$menu['bibliography.header-tools'] = array('Header', __('HERRAMIENTAS'));
$menu['bibliography.labels-printing'] = array(__('Impresión de Etiquetas'), MWB.'bibliography/dl_print.php', __('Imprimir Etiquetas de Documentos'));
$menu['bibliography.item-barcodes-printing'] = array(__('Impresión de Códigos de Barras'), MWB.'bibliography/item_barcode_generator.php', __('Imprimir Códigos de Barras de Ejemplares'));
$menu['bibliography.marc-export'] = array(__('Exportar MARC'), MWB.'bibliography/marcexport.php', __('Exportar Datos Bibliográficos a archivo MARC'));
$menu['bibliography.marc-import'] = array(__('Importar MARC'), MWB.'bibliography/marcimport.php', __('Importar Datos Bibliográficos desde archivo MARC'));
$menu['bibliography.catalog-printing'] = array(__('Impresión de Catálogo'), MWB.'bibliography/printed_card.php', __('Imprimir Ficha de Catálogo'));
$menu['bibliography.biblio-data-export'] = array(__('Exportar Datos Bibliográficos'), MWB.'bibliography/export.php', __('Exportar Datos Bibliográficos a formato CSV'));
$menu['bibliography.biblio-data-import'] = array(__('Importar Datos Bibliográficos'), MWB.'bibliography/import.php', __('Importar Datos a la Base de Datos Bibliográfica desde archivo CSV'));
$menu['bibliography.biblio-item-export'] = array(__('Exportar Ejemplares'), MWB.'bibliography/item_export.php', __('Exportar datos de Ejemplares/Copias a formato CSV'));
$menu['bibliography.biblio-item-import'] = array(__('Importar Ejemplares'), MWB.'bibliography/item_import.php', __('Importar Datos a la base de datos de Ejemplares/Copias desde archivo CSV'));
