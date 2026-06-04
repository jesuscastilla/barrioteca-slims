# Catalogador por ISBN para SLiMS

Se ha implementado un nuevo módulo de catalogación automática por ISBN (`isbn_lookup.php`) diseñado específicamente para funcionar sin depender de la extensión `php-yaz` o del protocolo Z39.50, resolviendo la limitación en entornos como Synology NAS.

## Características

1. **Múltiples Fuentes de Datos**:
   - **Google Books API**: Excelente cobertura internacional y datos en español.
   - **Open Library API**: Datos abiertos y buena disponibilidad de portadas de libros.
   - **ISBN España (Ministerio de Cultura)**: Acceso a la base de datos oficial española para máxima precisión en ediciones locales.

2. **Sin Dependencias Complejas**:
   - Utiliza exclusivamente peticiones HTTP/REST.
   - No requiere instalar extensiones PHP adicionales como `yaz`.

3. **Integración Nativa con SLiMS**:
   - Descarga automáticamente las portadas de los libros y las guarda en el directorio de imágenes de SLiMS (`images/docs`).
   - Importa autoras, editoriales, lugares de publicación y materias (temas), enlazándolos con los catálogos maestros (`mst_author`, `mst_publisher`, etc.).
   - Actualiza automáticamente el índice de búsqueda de SLiMS tras la importación.

## Archivos Modificados/Añadidos

1. **`admin/modules/bibliography/isbn_lookup.php`** (NUEVO)
   - Contiene toda la lógica de búsqueda en las fuentes, la interfaz de usuaria y el proceso de guardado en la base de datos.

2. **`admin/modules/bibliography/submenu.php`** (ACTUALIZADO)
   - Se ha añadido la entrada en el menú lateral de SLiMS bajo la sección "COPY CATALOGUING":
     ```php
     $menu['bibliography.isbn-lookup'] = array(__('ISBN Lookup'), MWB.'bibliography/isbn_lookup.php', __('Catalogar libros automáticamente mediante ISBN (Google Books, Open Library, BNE)'));
     ```

## Uso

1. Inicie sesión en SLiMS como administradora.
2. Vaya al módulo **Bibliografía** (Bibliography).
3. En el menú de la izquierda, busque la sección **COPY CATALOGUING** y haga clic en **ISBN Lookup**.
4. Introduzca el ISBN (con o sin guiones, 10 o 13 dígitos).
5. Seleccione las fuentes en las que desea buscar (Google, Open Library, ISBN España).
6. Haga clic en **Buscar**.
7. Seleccione los resultados deseados marcando la casilla correspondiente y pulse **Guardar en Base de Datos**.
