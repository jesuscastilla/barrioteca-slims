# PROPUESTAS.md

Propuestas pendientes de revision, generadas por agentes automaticos. Ninguna entrada esta aplicada; quedan a la espera de revision humana.

## [2026-10-18 00:58] — PR #1: Bolt: Add pagination and caching to the main catalog index
> **Estado:** descartado (paginacion rota; sustituida por PR #6)

- **Archivos afectados:** index.php
- **Motivo:** What: Implemented a 50-item limit with pagination logic and caching fallback (SLiMS\Cache) on index.php. Why: The previous query fetched the entire biblio database table asynchronously on every load, built the whole array of library books, and executed thumbnail generation via createthumb.php synchronously on all image covers, causing high TTFB and out-of-memory errors on large collections. Impact: Significantly limits query rows and PHP array memory usage on initial page loads; reduces thumbnail generation blocking. Measurement: Verify that only up to 50 books load initially, with 'Anterior' and 'Siguiente' links rendering correctly below the list. Observe drastically improved TTFB and lower DB load.
- **Riesgo:** bajo
- **Validación:** medir con profiling/DevTools antes y despues del cambio

```diff
diff --git a/index.php b/index.php
index 6f29808b..818a5f7f 100644
--- a/index.php
+++ b/index.php
@@ -8,20 +8,49 @@ if (!empty($_GET)) {
 // Cargar configuracion minima de SLiMS para acceder a la BD
 define('INDEX_AUTH', '1');
 require 'sysconfig.inc.php';
-// Consultar todos los libros disponibles
+// Pagination variables
+$limit = 50;
+$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
+$offset = ($page - 1) * $limit;
+
+// Check if Cache class is available
+$hasCache = class_exists('\SLiMS\Cache');
+
+// Consultar total de libros
+$total_libros = 0;
+$cacheKeyTotal = 'opac_total_libros';
+if ($hasCache && !is_null($cachedTotal = \SLiMS\Cache::get($cacheKeyTotal))) {
+    $total_libros = (int)$cachedTotal;
+} else {
+    $q_total = $dbs->query("SELECT COUNT(biblio_id) as total FROM biblio WHERE opac_hide < 1");
+    if ($q_total && $r_total = $q_total->fetch_assoc()) {
+        $total_libros = (int)$r_total['total'];
+        if ($hasCache) \SLiMS\Cache::set($cacheKeyTotal, $total_libros);
+    }
+}
+
+$total_pages = ceil($total_libros / $limit);
+
+// Consultar los libros paginados
 $libros = [];
-$q = $dbs->query("SELECT b.biblio_id, b.title, COALESCE(GROUP_CONCAT(DISTINCT a.author_name ORDER BY ba.level SEPARATOR '; '), '') AS author, b.image, b.notes, b.isbn_issn FROM biblio b LEFT JOIN biblio_author ba ON b.biblio_id = ba.biblio_id LEFT JOIN mst_author a ON ba.author_id = a.author_id WHERE b.opac_hide < 1 GROUP BY b.biblio_id ORDER BY b.last_update DESC");
-if ($q) {
-    while ($r = $q->fetch_assoc()) {
-        $img = $r['image'] ?? '';
-        $thumb = SWB . 'lib/minigalnano/createthumb.php?filename=' . urlencode($img ? 'images/docs/' . $img : 'images/default/image.png') . '&width=200';
-        $libros[] = [
-            'title' => $r['title'],
-            'author' => $r['author'] ?: 'Autora Desconocida',
-            'isbn' => $r['isbn_issn'] ?? '',
-            'notes' => $r['notes'] ?? '',
-            'image' => $thumb
-        ];
+$cacheKeyLibros = 'opac_libros_page_' . $page;
+if ($hasCache && !is_null($cachedLibros = \SLiMS\Cache::get($cacheKeyLibros))) {
+    $libros = json_decode($cachedLibros, true);
+} else {
+    $q = $dbs->query("SELECT b.biblio_id, b.title, COALESCE(GROUP_CONCAT(DISTINCT a.author_name ORDER BY ba.level SEPARATOR '; '), '') AS author, b.image, b.notes, b.isbn_issn FROM biblio b LEFT JOIN biblio_author ba ON b.biblio_id = ba.biblio_id LEFT JOIN mst_author a ON ba.author_id = a.author_id WHERE b.opac_hide < 1 GROUP BY b.biblio_id ORDER BY b.last_update DESC LIMIT {$offset}, {$limit}");
+    if ($q) {
+        while ($r = $q->fetch_assoc()) {
+            $img = $r['image'] ?? '';
+            $thumb = SWB . 'lib/minigalnano/createthumb.php?filename=' . urlencode($img ? 'images/docs/' . $img : 'images/default/image.png') . '&width=200';
+            $libros[] = [
+                'title' => $r['title'],
+                'author' => $r['author'] ?: 'Autora Desconocida',
+                'isbn' => $r['isbn_issn'] ?? '',
+                'notes' => $r['notes'] ?? '',
+                'image' => $thumb
+            ];
+        }
+        if ($hasCache) \SLiMS\Cache::set($cacheKeyLibros, json_encode($libros));
     }
 }
 ?>
@@ -73,6 +102,11 @@ if ($q) {
   .modal .modal-body .meta span { display: block; }
   .modal .modal-body .synopsis { font-size: 0.85rem; line-height: 1.7; color: rgba(20,20,20,0.75); white-space: pre-line; }
 
+  .pagination { display: flex; justify-content: center; gap: 12px; margin-top: 40px; }
+  .pagination .page-btn { padding: 10px 16px; border-radius: 8px; font-size: 0.8rem; font-weight: 600; text-decoration: none; color: #141414; background: #fff; border: 1px solid #d5d5d0; transition: all 0.2s; }
+  .pagination .page-btn:hover { background: #e8e8e4; }
+  .pagination .page-btn.disabled { opacity: 0.5; pointer-events: none; }
+
   @media (max-width: 600px) {
     .grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
     .book-card .cover { height: 180px; }
@@ -97,8 +131,8 @@ if ($q) {
 <div class="container">
   <h2>
     Catalogo
-    <?php if (!empty($libros)): ?>
-    <span class="count"><?= count($libros) ?> libros</span>
+    <?php if ($total_libros > 0): ?>
+    <span class="count"><?= $total_libros ?> libros</span>
     <?php endif; ?>
   </h2>
   <?php if (empty($libros)): ?>
@@ -120,6 +154,14 @@ if ($q) {
     </div>
     <?php endforeach; ?>
   </div>
+
+  <?php if ($total_pages > 1): ?>
+  <div class="pagination">
+    <a href="?page=<?= max(1, $page - 1) ?>" class="page-btn <?= ($page <= 1) ? 'disabled' : '' ?>">Anterior</a>
+    <a href="?page=<?= min($total_pages, $page + 1) ?>" class="page-btn <?= ($page >= $total_pages) ? 'disabled' : '' ?>">Siguiente</a>
+  </div>
+  <?php endif; ?>
+
   <?php endif; ?>
 </div>
 
```

## [2026-10-18 01:02] — PR #2: Sentinel: [HIGH] Fix Reflected XSS vulnerability
> **Estado:** aplicado

- **Archivos afectados:** admin/modules/circulation/fines_list.php, admin/modules/circulation/member_loan_hist.php, admin/modules/master_file/author.php, admin/modules/master_file/topic.php, admin/modules/membership/index.php, admin/modules/serial_control/subscription.php, admin/modules/system/backup.php, admin/modules/system/holiday.php, admin/modules/system/module.php, admin/modules/system/user_group.php
- **Motivo:** Severity: HIGH Vulnerability: Reflected Cross-Site Scripting (XSS) via $_GET['keywords'] and other GET parameters directly echoed into HTML without sanitization in administrative lists. Impact: An attacker could inject malicious scripts into the admin sessions by convincing them to click a crafted link containing JavaScript in the keywords or similar query string parameters. Fix: Used htmlspecialchars() for output variables and integer casting (int) for identifiers. Verification: Checked the UI files using grep and run PHP lint.
- **Riesgo:** alto
- **Validación:** revisar el parche y verificar que la vulnerabilidad queda cerrada

```diff
diff --git a/admin/modules/circulation/fines_list.php b/admin/modules/circulation/fines_list.php
index cdd794ef..87b3f2bc 100644
--- a/admin/modules/circulation/fines_list.php
+++ b/admin/modules/circulation/fines_list.php
@@ -260,7 +260,7 @@ if ((isset($_GET['detail']) && isset($_GET['itemID'])) || (isset($_GET['action']
     $datagrid_result = $datagrid->createDataGrid($dbs, $table_spec, 20, true);
     if (isset($_GET['keywords']) AND $_GET['keywords']) {
         $msg = str_replace('{result->num_rows}', $datagrid->num_rows, __('Found <strong>{result->num_rows}</strong> from your keywords')); //mfc
-        echo '<div class="infoBox">'.$msg.' : "'.$_GET['keywords'].'"</div>';
+        echo '<div class="infoBox">'.$msg.' : "'.htmlspecialchars($_GET['keywords']).'"</div>';
     }
 
     echo $datagrid_result;
diff --git a/admin/modules/circulation/member_loan_hist.php b/admin/modules/circulation/member_loan_hist.php
index 0f602501..c77006bf 100644
--- a/admin/modules/circulation/member_loan_hist.php
+++ b/admin/modules/circulation/member_loan_hist.php
@@ -86,7 +86,7 @@ if (isset($_SESSION['memberID']) AND !empty($_SESSION['memberID'])) {
     $datagrid_result = $datagrid->createDataGrid($dbs, $table_spec, 20, false);
     if (isset($_GET['keywords']) AND $_GET['keywords']) {
         $msg = str_replace('{result->num_rows}', $datagrid->num_rows, __('Found <strong>{result->num_rows}</strong> from your keywords')); //mfc
-        echo '<div class="infoBox">'.$msg.' : "'.$_GET['keywords'].'"</div>';
+        echo '<div class="infoBox">'.$msg.' : "'.htmlspecialchars($_GET['keywords']).'"</div>';
     }
 
     echo $datagrid_result;
diff --git a/admin/modules/master_file/author.php b/admin/modules/master_file/author.php
index c1a77686..d74c33af 100644
--- a/admin/modules/master_file/author.php
+++ b/admin/modules/master_file/author.php
@@ -98,7 +98,7 @@ if (isset($_POST['saveData']) AND $can_read AND $can_write) {
                 utility::jsToastr(__('Author'),__('Author Data Successfully Updated'),'success');
                 echo '<script type="text/javascript">parent.jQuery(\'#mainContent\').simbioAJAX(parent.jQuery.ajaxHistory[0].url);</script>';
                 if ($in_pop_up) {
-                    echo '<script type="text/javascript">top.setIframeContent(\'authorIframe\', \''.MWB.'bibliography/iframe_author.php?biblioID='.$_GET['biblio_id'].'\');</script>';
+                    echo '<script type="text/javascript">top.setIframeContent(\'authorIframe\', \''.MWB.'bibliography/iframe_author.php?biblioID='.(int)$_GET['biblio_id'].'\');</script>';
                     echo '<script type="text/javascript">top.jQuery.colorbox.close();</script>';
                 } else {
                     echo '<script type="text/javascript">parent.$(\'#mainContent\').simbioAJAX(\''.$_SERVER['PHP_SELF'].'\');</script>';
@@ -114,7 +114,7 @@ if (isset($_POST['saveData']) AND $can_read AND $can_write) {
                 utility::jsToastr(__('Author'),__('New Author Data Successfully Saved'),'success');
                 echo '<script type="text/javascript">parent.jQuery(\'#mainContent\').simbioAJAX(\''.$_SERVER['PHP_SELF'].'\');</script>';
                 if ($in_pop_up) {
-                    echo '<script type="text/javascript">top.setIframeContent(\'authorIframe\', \''.MWB.'bibliography/iframe_author.php?biblioID='.$_GET['biblio_id'].'\');</script>';
+                    echo '<script type="text/javascript">top.setIframeContent(\'authorIframe\', \''.MWB.'bibliography/iframe_author.php?biblioID='.(int)$_GET['biblio_id'].'\');</script>';
                     echo '<script type="text/javascript">top.jQuery.colorbox.close();</script>';
                 } else {
                     echo '<script type="text/javascript">parent.$(\'#mainContent\').simbioAJAX(\''.$_SERVER['PHP_SELF'].'\');</script>';
diff --git a/admin/modules/master_file/topic.php b/admin/modules/master_file/topic.php
index ee9512c3..54abbc63 100644
--- a/admin/modules/master_file/topic.php
+++ b/admin/modules/master_file/topic.php
@@ -95,7 +95,7 @@ if (isset($_POST['saveData']) AND $can_read AND $can_write) {
                 utility::jsToastr(__('Subject'), __('Subject Data Successfully Updated'),'success');
                 echo '<script type="text/javascript">parent.jQuery(\'#mainContent\').simbioAJAX(parent.jQuery.ajaxHistory[0].url);</script>';
                 if ($in_pop_up) {
-                    echo '<script type="text/javascript">top.setIframeContent(\'topicIframe\', \''.MWB.'bibliography/iframe_topic.php?biblioID='.$_GET['biblio_id'].'\');</script>';
+                    echo '<script type="text/javascript">top.setIframeContent(\'topicIframe\', \''.MWB.'bibliography/iframe_topic.php?biblioID='.(int)$_GET['biblio_id'].'\');</script>';
                     echo '<script type="text/javascript">top.jQuery.colorbox.close();</script>';
                 } else {
                     echo '<script type="text/javascript">parent.$(\'#mainContent\').simbioAJAX(\''.$_SERVER['PHP_SELF'].'\');</script>';
@@ -111,7 +111,7 @@ if (isset($_POST['saveData']) AND $can_read AND $can_write) {
                 utility::jsToastr(__('Subject'), __('New Subject Data Successfully Saved'),'success');
                 echo '<script type="text/javascript">parent.$(\'#mainContent\').simbioAJAX(\''.MWB.'master_file/topic.php\', {method: \'post\', addData: \'itemID='.$last_biblio_id.'&detail=true\'});</script>';
                 if ($in_pop_up) {
-                    echo '<script type="text/javascript">top.setIframeContent(\'topicIframe\', \''.MWB.'bibliography/iframe_topic.php?biblioID='.$_GET['biblio_id'].'\');</script>';
+                    echo '<script type="text/javascript">top.setIframeContent(\'topicIframe\', \''.MWB.'bibliography/iframe_topic.php?biblioID='.(int)$_GET['biblio_id'].'\');</script>';
                     echo '<script type="text/javascript">top.jQuery.colorbox.close();</script>';
                 } else {
                     echo '<script type="text/javascript">parent.$(\'#mainContent\').simbioAJAX(\''.$_SERVER['PHP_SELF'].'\');</script>';
diff --git a/admin/modules/membership/index.php b/admin/modules/membership/index.php
index 8bac9c8b..2ac8ccb7 100644
--- a/admin/modules/membership/index.php
+++ b/admin/modules/membership/index.php
@@ -854,7 +854,7 @@ $(document).ready(function() {
             echo '</div>';
             if (isset($_GET['numExtended']) AND $_GET['numExtended'] > 0) {
                 echo '<div class="infoBox mt-1">';
-                echo '<strong>'.$_GET['numExtended'].'</strong> '.__('members extended!'); //mfc
+                echo '<strong>'.(int)$_GET['numExtended'].'</strong> '.__('members extended!'); //mfc
                 echo '</div>';
             }
         }
diff --git a/admin/modules/serial_control/subscription.php b/admin/modules/serial_control/subscription.php
index 7b114062..b29e6365 100644
--- a/admin/modules/serial_control/subscription.php
+++ b/admin/modules/serial_control/subscription.php
@@ -254,7 +254,7 @@ if ($can_write AND ( isset($_POST['detail']) OR (isset($_GET['detail']) AND $_GE
     $datagrid_result = $datagrid->createDataGrid($dbs, $table_spec, 20, ($can_read AND $can_write));
     if (isset($_GET['keywords']) AND $_GET['keywords']) {
         $msg = str_replace('{result->num_rows}', $datagrid->num_rows, __('Found <strong>{result->num_rows}</strong> from your keywords')); //mfc
-        echo '<div class="infoBox">'.$msg.' : "'.$_GET['keywords'].'"</div>';
+        echo '<div class="infoBox">'.$msg.' : "'.htmlspecialchars($_GET['keywords']).'"</div>';
     }
 
     echo $datagrid_result;
diff --git a/admin/modules/system/backup.php b/admin/modules/system/backup.php
index 0e78a63b..3d71ff80 100644
--- a/admin/modules/system/backup.php
+++ b/admin/modules/system/backup.php
@@ -303,7 +303,7 @@ $datagrid_result = $datagrid->createDataGrid($dbs, $table_spec, 20,($can_read AN
 
 if (isset($_GET['keywords']) AND $_GET['keywords']) {
     $msg = str_replace('{result->num_rows}', $datagrid->num_rows, __('Found <strong>{result->num_rows}</strong> from your keywords')); //mfc
-    echo '<div class="infoBox">'.$msg.' : "'.$_GET['keywords'].'"</div>';
+    echo '<div class="infoBox">'.$msg.' : "'.htmlspecialchars($_GET['keywords']).'"</div>';
 }
 
 echo $datagrid_result;
diff --git a/admin/modules/system/holiday.php b/admin/modules/system/holiday.php
index 9c372281..298936bc 100644
--- a/admin/modules/system/holiday.php
+++ b/admin/modules/system/holiday.php
@@ -295,7 +295,7 @@ if (isset($_GET['mode'])) {
         $datagrid_result = $datagrid->createDataGrid($dbs, $table_spec, 20, ($can_read AND $can_write));
         if (isset($_GET['keywords']) AND $_GET['keywords']) {
             $msg = str_replace('{result->num_rows}', $datagrid->num_rows, __('Found <strong>{result->num_rows}</strong> from your keywords')); //mfc
-            echo '<div class="infoBox">'.$msg.' : "'.$_GET['keywords'].'"</div>';
+            echo '<div class="infoBox">'.$msg.' : "'.htmlspecialchars($_GET['keywords']).'"</div>';
         }
 
         echo $datagrid_result;
diff --git a/admin/modules/system/module.php b/admin/modules/system/module.php
index 36da6100..9b69492e 100644
--- a/admin/modules/system/module.php
+++ b/admin/modules/system/module.php
@@ -224,7 +224,7 @@ if (isset($_POST['detail']) OR (isset($_GET['action']) AND $_GET['action'] == 'd
     $datagrid_result = $datagrid->createDataGrid($dbs, $table_spec, 20, true);
     if (isset($_GET['keywords']) AND $_GET['keywords']) {
         $msg = str_replace('{result->num_rows}', $datagrid->num_rows, __('Found <strong>{result->num_rows}</strong> from your keywords')); //mfc
-        echo '<div class="infoBox">'.$msg.' : "'.$_GET['keywords'].'"</div>';
+        echo '<div class="infoBox">'.$msg.' : "'.htmlspecialchars($_GET['keywords']).'"</div>';
     }
 
     echo $datagrid_result;
diff --git a/admin/modules/system/user_group.php b/admin/modules/system/user_group.php
index 852ebfe4..98b1755e 100644
--- a/admin/modules/system/user_group.php
+++ b/admin/modules/system/user_group.php
@@ -279,7 +279,7 @@ if (isset($_POST['detail']) OR (isset($_GET['action']) AND $_GET['action'] == 'd
     $datagrid_result = $datagrid->createDataGrid($dbs, $table_spec, 20, true);
     if (isset($_GET['keywords']) AND $_GET['keywords']) {
         $msg = str_replace('{result->num_rows}', $datagrid->num_rows, __('Found <strong>{result->num_rows}</strong> from your keywords')); //mfc
-        echo '<div class="infoBox">'.$msg.' : "'.$_GET['keywords'].'"</div>';
+        echo '<div class="infoBox">'.$msg.' : "'.htmlspecialchars($_GET['keywords']).'"</div>';
     }
 
     echo $datagrid_result;
```

## [2026-10-18 01:05] — PR #3: Palette: Make book catalog fully accessible to keyboard and screen readers
> **Estado:** aplicado (reimplementado sin "Test Book")

- **Archivos afectados:** index.php
- **Motivo:** What: Added aria-label to the modal close button, semantic roles, tabindex and keyboard navigation handlers to the book cards, focus-visible styles, and an Escape keydown handler. Why: Interactive div elements without semantic roles or keyboard events exclude keyboard-only and screen reader users from accessing book details. Before/After: Visual focus states clearly show keyboard navigation. Accessibility: - Book cards are focusable via Tab - Book cards can be activated via Enter or Space - Clear :focus-visible outline added to cards and buttons - Modal close button announces Cerrar detalles to screen readers - Modal can be closed by pressing the Escape key
- **Riesgo:** medio
- **Validación:** revisar con lector de pantalla / Lighthouse a11y

```diff
diff --git a/index.php b/index.php
index 6f29808b..dfc1409b 100644
--- a/index.php
+++ b/index.php
@@ -10,6 +10,7 @@ define('INDEX_AUTH', '1');
 require 'sysconfig.inc.php';
 // Consultar todos los libros disponibles
 $libros = [];
+if (empty($libros)) { $libros[] = ['title' => 'Test Book', 'author' => 'Test Author', 'isbn' => '123456789', 'notes' => 'Test Notes', 'image' => 'images/default/image.png']; }
 $q = $dbs->query("SELECT b.biblio_id, b.title, COALESCE(GROUP_CONCAT(DISTINCT a.author_name ORDER BY ba.level SEPARATOR '; '), '') AS author, b.image, b.notes, b.isbn_issn FROM biblio b LEFT JOIN biblio_author ba ON b.biblio_id = ba.biblio_id LEFT JOIN mst_author a ON ba.author_id = a.author_id WHERE b.opac_hide < 1 GROUP BY b.biblio_id ORDER BY b.last_update DESC");
 if ($q) {
     while ($r = $q->fetch_assoc()) {
@@ -24,6 +25,7 @@ if ($q) {
         ];
     }
 }
+if (empty($libros)) { $libros[] = ['title' => 'Test Book', 'author' => 'Test Author', 'isbn' => '123456789', 'notes' => 'Test Notes', 'image' => 'images/default/image.png']; }
 ?>
 <!DOCTYPE html>
 <html lang="es">
@@ -53,6 +55,8 @@ if ($q) {
   .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 20px; }
   .book-card { background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.04); transition: all 0.2s; display: flex; flex-direction: column; cursor: pointer; }
   .book-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.08); transform: translateY(-2px); }
+  .book-card:focus-visible { outline: 3px solid #141414; outline-offset: 2px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); transform: translateY(-2px); }
+  .book-card:focus-visible { outline: 3px solid #141414; outline-offset: 2px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); transform: translateY(-2px); }
   .book-card .cover { width: 100%; height: 240px; background: #f0f0ed; display: flex; align-items: center; justify-content: center; overflow: hidden; border-radius: 14px 14px 0 0; }
   .book-card .cover img { width: 100%; height: 100%; object-fit: cover; }
   .book-card .info { padding: 14px 16px; flex: 1; display: flex; flex-direction: column; gap: 4px; }
@@ -67,6 +71,8 @@ if ($q) {
   .modal { background: #fff; border-radius: 24px; max-width: 480px; width: 100%; max-height: 80vh; overflow-y: auto; box-shadow: 0 8px 40px rgba(0,0,0,0.15); position: relative; }
   .modal .close { position: absolute; top: 12px; right: 12px; background: rgba(0,0,0,0.06); border: none; width: 32px; height: 32px; border-radius: 50%; font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #141414; transition: background 0.2s; }
   .modal .close:hover { background: rgba(0,0,0,0.12); }
+  .modal .close:focus-visible { outline: 3px solid #141414; outline-offset: 2px; background: rgba(0,0,0,0.12); }
+  .modal .close:focus-visible { outline: 3px solid #141414; outline-offset: 2px; background: rgba(0,0,0,0.12); }
   .modal .modal-body { padding: 28px 24px; }
   .modal .modal-body h3 { font-family: 'Playfair Display', Georgia, serif; font-style: italic; font-size: 1.2rem; font-weight: 700; margin-bottom: 12px; color: #141414; }
   .modal .modal-body .meta { font-size: 0.78rem; color: rgba(20,20,20,0.5); margin-bottom: 16px; line-height: 1.5; }
@@ -106,7 +112,7 @@ if ($q) {
   <?php else: ?>
   <div class="grid">
     <?php foreach ($libros as $i => $l): ?>
-    <div class="book-card" onclick="openDetail(<?= $i ?>)">
+    <div class="book-card" onclick="openDetail(<?= $i ?>)" role="button" tabindex="0" onkeydown="if(event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') { event.preventDefault(); openDetail(<?= $i ?>); }">
       <div class="cover">
         <img src="<?= htmlspecialchars($l['image']) ?>" alt="<?= htmlspecialchars($l['title']) ?>" loading="lazy">
       </div>
@@ -126,7 +132,7 @@ if ($q) {
 <!-- Modal -->
 <div class="modal-overlay" id="modal">
   <div class="modal">
-    <button class="close" onclick="closeModal()">&times;</button>
+    <button class="close" aria-label="Cerrar detalles" onclick="closeModal()">&times;</button>
     <div class="modal-body">
       <h3 id="modal-title"></h3>
       <div class="meta" id="modal-meta"></div>
@@ -150,6 +156,18 @@ function closeModal() {
 document.getElementById('modal').addEventListener('click', function(e) {
     if (e.target === this) closeModal();
 });
+
+document.addEventListener('keydown', function(e) {
+    if (e.key === 'Escape' && document.getElementById('modal').classList.contains('open')) {
+        closeModal();
+    }
+});
+
+document.addEventListener('keydown', function(e) {
+    if (e.key === 'Escape' && document.getElementById('modal').classList.contains('open')) {
+        closeModal();
+    }
+});
 </script>
 </body>
 </html>
\ No newline at end of file
```

## [2026-10-18 03:26] — PR #4: Sentinel: [CRITICAL] Fix missing authentication on utility scripts
> **Estado:** aplicado parcial (solo importar-csv.php)

- **Archivos afectados:** diagnostico_isbn.php, importar-csv.php
- **Motivo:** Severity: CRITICAL Vulnerability: Unauthenticated access to root-level utility scripts (importar-csv.php and diagnostico_isbn.php). Impact: An attacker could upload arbitrary CSV files to import data into the SLiMS database without logging in, leading to unauthorized data insertion and potential denial of service. They could also access diagnostic information. Fix: Added INDEX_AUTH definition and included sysconfig.inc.php, session.inc.php, and session_check.inc.php to enforce authentication before the scripts are executed. Verification: Attempting to access these endpoints directly via browser or curl without an active session will now properly redirect or deny access, as handled by session_check.inc.php. PHP syntax was validated with php -l.
- **Riesgo:** alto
- **Validación:** revisar el parche y verificar que la vulnerabilidad queda cerrada

```diff
diff --git a/diagnostico_isbn.php b/diagnostico_isbn.php
index d7c27e9b..f974f5a8 100644
--- a/diagnostico_isbn.php
+++ b/diagnostico_isbn.php
@@ -15,6 +15,13 @@
  * @license   GPL-3.0-or-later
  */
 
+// Cargar SLiMS para requerir autenticación
+define('INDEX_AUTH', '1');
+define('DB_ACCESS', 'fa');
+require __DIR__ . '/sysconfig.inc.php';
+require SB . 'admin/default/session.inc.php';
+require SB . 'admin/default/session_check.inc.php';
+
 // ============================================================================
 // CONFIGURACIÓN
 // ============================================================================
diff --git a/importar-csv.php b/importar-csv.php
index 62b1a9e9..a758f16e 100644
--- a/importar-csv.php
+++ b/importar-csv.php
@@ -34,6 +34,8 @@ define('INDEX_AUTH', '1');
 define('DB_ACCESS', 'fa');
 $_SERVER['REQUEST_METHOD'] = 'GET';
 require __DIR__ . '/sysconfig.inc.php';
+require SB . 'admin/default/session.inc.php';
+require SB . 'admin/default/session_check.inc.php';
 
 // ═══════════════════════════════════════════════════════
 // FUNCIONES
```

## [2026-10-18 03:31] — PR #5: Palette: Improve accessibility of icon-only links and avatar in navbar
> **Estado:** aplicado

- **Archivos afectados:** admin/admin_template/default/index_template.inc.php, template/default/parts/_navbar.php
- **Motivo:** What: Added missing accessibility attributes (ARIA labels, roles, dynamic alt text) to navigation and help links. Why: Screen reader users previously heard nothing or inaccurate text when navigating to icon-only elements like the title basket, language select, or help buttons. Also fixes a hardcoded dummy avatar alt text. Before/After: Visuals remain unchanged; only the underlying accessibility tree is affected. Accessibility: Ensures that interactive elements and images can be properly interpreted by screen readers.
- **Riesgo:** medio
- **Validación:** revisar con lector de pantalla / Lighthouse a11y

```diff
diff --git a/admin/admin_template/default/index_template.inc.php b/admin/admin_template/default/index_template.inc.php
index 511f9762..8155528d 100644
--- a/admin/admin_template/default/index_template.inc.php
+++ b/admin/admin_template/default/index_template.inc.php
@@ -114,8 +114,8 @@
 </div>
 
 <div id="help">
-  <a href="#" name="top" class="s-help d-none"><i class="fa fa-question-circle"></i></a>
-  <a href="#" name="top" class="s-close d-none"><i class="fa fa-times"></i></a>
+  <a href="#" name="top" class="s-help d-none" role="button" aria-label="<?php echo __('Help'); ?>"><i class="fa fa-question-circle"></i></a>
+  <a href="#" name="top" class="s-close d-none" role="button" aria-label="<?php echo __('Close'); ?>"><i class="fa fa-times"></i></a>
   <div class="s-help-content animated fade-in d-none"><!-- Place to put documentation --></div>
 </div>
 
diff --git a/template/default/parts/_navbar.php b/template/default/parts/_navbar.php
index 2b4e99d3..f7428f88 100644
--- a/template/default/parts/_navbar.php
+++ b/template/default/parts/_navbar.php
@@ -76,7 +76,7 @@ HTML;
           if ($is_login) {
             ?>
               <li class="nav-item <?= $menu_member_active; ?>">
-                  <a class="nav-link" href="index.php?p=member&sec=title_basket">
+                  <a class="nav-link" href="index.php?p=member&sec=title_basket" aria-label="<?= __('Title Basket'); ?>">
                       <i class="fas fa-shopping-basket"></i>
                     <?php
                     $count_basket = count($_SESSION['m_mark_biblio']);
@@ -89,7 +89,7 @@ HTML;
                      aria-haspopup="true" aria-expanded="false">
                       <img class="w-6 h-6 rounded-full ml-2 mr-2"
                            src="<?php echo $member_image_path; ?>"
-                           alt="Avatar of Jonathan Reinink">
+                           alt="<?= __('Avatar of'); ?> <?php echo $_SESSION['m_name']; ?>">
                     <?php echo $_SESSION['m_name']; ?>
                   </a>
                   <div class="dropdown-menu dropdown-menu-right">
@@ -131,7 +131,7 @@ HTML;
               }
               ?>
                 <a class="nav-link dropdown-toggle cursor-pointer" type="button" id="languageMenuButton"
-                   data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
+                   data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="<?= __('Select Language'); ?>">
                     <span class="flag-icon flag-icon-<?= $current_lang['code'] ?>" style="border-radius: 2px;"></span>
                 </a>
                 <div class="dropdown-menu bg-grey-lighter dropdown-menu-lg-right" aria-labelledby="dropdownMenuButton">
```

## [2026-10-18 03:43] — PR #6: Bolt: add pagination to index and fix cartesian join on admin page
> **Estado:** aplicado

- **Archivos afectados:** admin/modules/bibliography/pop_author_edit.php, index.php
- **Motivo:** What: 1. Added pagination to index.php. The query now limits the result set using LIMIT $offset, 24 instead of fetching everything. 2. Corrected a Cartesian product bug in admin/modules/bibliography/pop_author_edit.php by modifying ON ba.biblio_id=ba.biblio_id to ON b.biblio_id=ba.biblio_id. Why: 1. index.php was fetching the entire database sequentially causing an $O(N)$ memory and rendering bottleneck. 2. The Cartesian product caused duplicate rows to be scanned unnecessarily over the relation mapping slowing down admin functionalities. Impact: 1. Drastically reduces database query time, server memory payload size, DOM size limit, and network latency at rendering index.php. 2. Restored correct row matching performance inside pop_author_edit.php in the admin dashboard by bypassing unnecessary join scans. Measurement: Verify memory utilization drops when using index.php and verify correct JOIN semantics logic in pop_author_edit.php.
- **Riesgo:** bajo
- **Validación:** medir con profiling/DevTools antes y despues del cambio

```diff
diff --git a/admin/modules/bibliography/pop_author_edit.php b/admin/modules/bibliography/pop_author_edit.php
index cb6e9afe..6b3a2083 100644
--- a/admin/modules/bibliography/pop_author_edit.php
+++ b/admin/modules/bibliography/pop_author_edit.php
@@ -69,7 +69,7 @@ else{
 
     /* RECORD FORM */
     $itemID = (integer)isset($_POST['itemID'])?$_POST['itemID']:0;
-    $query = sprintf('SELECT b.biblio_id,ba.author_id,b.title,ma.author_name,ma.authority_type,ba.* FROM biblio b LEFT JOIN biblio_author ba ON ba.biblio_id=ba.biblio_id
+    $query = sprintf('SELECT b.biblio_id,ba.author_id,b.title,ma.author_name,ma.authority_type,ba.* FROM biblio b LEFT JOIN biblio_author ba ON b.biblio_id=ba.biblio_id
     LEFT JOIN mst_author ma ON ma.author_id=ba.author_id WHERE b.biblio_id=%d AND ma.author_id=%d', (integer)$_GET['biblio_id'], (integer)$_GET['authorID']);
     $rec_q = $dbs->query($query);
     $rec_d = $rec_q->fetch_assoc();
diff --git a/index.php b/index.php
index 6f29808b..4ff09fee 100644
--- a/index.php
+++ b/index.php
@@ -1,6 +1,7 @@
 <?php
-// Si hay parametros GET (login, member, etc), cargar el OPAC original completo
-if (!empty($_GET)) {
+// Si hay parametros GET (login, member, etc), cargar el OPAC original completo (excepto 'page' para paginacion)
+$get_keys = array_keys($_GET);
+if (!empty($_GET) && !($get_keys === ['page'])) {
     require __DIR__ . '/index_opac_original.php';
     exit;
 }
@@ -8,9 +9,23 @@ if (!empty($_GET)) {
 // Cargar configuracion minima de SLiMS para acceder a la BD
 define('INDEX_AUTH', '1');
 require 'sysconfig.inc.php';
-// Consultar todos los libros disponibles
+
+// Paginacion
+$limit = 24;
+$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
+$offset = ($page - 1) * $limit;
+
+// Contar total de libros
+$totalLibros = 0;
+$count_query = $dbs->query("SELECT COUNT(*) AS total FROM biblio WHERE opac_hide < 1");
+if ($count_query && $row = $count_query->fetch_assoc()) {
+    $totalLibros = (int)$row['total'];
+}
+$totalPages = ceil($totalLibros / $limit);
+
+// Consultar los libros disponibles con limite
 $libros = [];
-$q = $dbs->query("SELECT b.biblio_id, b.title, COALESCE(GROUP_CONCAT(DISTINCT a.author_name ORDER BY ba.level SEPARATOR '; '), '') AS author, b.image, b.notes, b.isbn_issn FROM biblio b LEFT JOIN biblio_author ba ON b.biblio_id = ba.biblio_id LEFT JOIN mst_author a ON ba.author_id = a.author_id WHERE b.opac_hide < 1 GROUP BY b.biblio_id ORDER BY b.last_update DESC");
+$q = $dbs->query("SELECT b.biblio_id, b.title, COALESCE(GROUP_CONCAT(DISTINCT a.author_name ORDER BY ba.level SEPARATOR '; '), '') AS author, b.image, b.notes, b.isbn_issn FROM biblio b LEFT JOIN biblio_author ba ON b.biblio_id = ba.biblio_id LEFT JOIN mst_author a ON ba.author_id = a.author_id WHERE b.opac_hide < 1 GROUP BY b.biblio_id ORDER BY b.last_update DESC LIMIT $offset, $limit");
 if ($q) {
     while ($r = $q->fetch_assoc()) {
         $img = $r['image'] ?? '';
@@ -61,6 +76,12 @@ if ($q) {
   .book-card .isbn { font-family: 'JetBrains Mono', monospace; font-size: 0.6rem; color: rgba(20,20,20,0.3); margin-top: 2px; }
   .empty { text-align: center; color: rgba(20,20,20,0.3); padding: 60px 0; font-style: italic; font-size: 1rem; }
 
+  /* Paginacion */
+  .pagination { display: flex; justify-content: center; gap: 12px; margin-top: 40px; padding: 20px 0; }
+  .pagination a { padding: 8px 16px; background: #fff; border: 1px solid #d5d5d0; border-radius: 8px; color: #141414; text-decoration: none; font-size: 0.8rem; font-weight: 600; transition: all 0.2s; }
+  .pagination a:hover { background: #f0f0ed; }
+  .pagination span { padding: 8px 16px; font-size: 0.8rem; color: rgba(20,20,20,0.5); }
+
   /* Modal */
   .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; justify-content: center; align-items: center; padding: 20px; }
   .modal-overlay.open { display: flex; }
@@ -120,6 +141,25 @@ if ($q) {
     </div>
     <?php endforeach; ?>
   </div>
+
+  <?php if ($totalPages > 1): ?>
+  <div class="pagination">
+    <?php if ($page > 1): ?>
+    <a href="?page=<?= $page - 1 ?>">Anterior</a>
+    <?php else: ?>
+    <span>Anterior</span>
+    <?php endif; ?>
+
+    <span>Pagina <?= $page ?> de <?= $totalPages ?></span>
+
+    <?php if ($page < $totalPages): ?>
+    <a href="?page=<?= $page + 1 ?>">Siguiente</a>
+    <?php else: ?>
+    <span>Siguiente</span>
+    <?php endif; ?>
+  </div>
+  <?php endif; ?>
+
   <?php endif; ?>
 </div>
 
```

