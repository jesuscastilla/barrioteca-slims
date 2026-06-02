# Catalogador por ISBN para SLiMS

Se ha implementado un nuevo módulo de catalogación automática por ISBN (`isbn_lookup.php`) diseñado específicamente para funcionar sin depender de la extensión `php-yaz` o del protocolo Z39.50, resolviendo la limitación en entornos como Synology NAS.

## Características

1. **Múltiples Fuentes de Datos**:
   - **Google Books API**: Excelente cobertura internacional y datos en español.
   - **Open Library API**: Datos abiertos y buena disponibilidad de portadas de libros.
   - **ISBN España (Ministerio de Cultura)**: Scraping de la base de datos oficial española para máxima precisión en ediciones locales.

2. **Sin Dependencias Complejas**:
   - Utiliza exclusivamente peticiones HTTP/REST (vía `file_get_contents` y `stream_context_create`).
   - No requiere instalar extensiones PHP adicionales como `yaz` o `xml`.

3. **Integración Nativa con SLiMS**:
   - Descarga automáticamente las portadas de los libros y las guarda en el directorio de imágenes de SLiMS (`images/docs`).
   - Importa autores, editoriales, lugares de publicación y materias (temas), enlazándolos con los catálogos maestros (`mst_author`, `mst_publisher`, etc.).
   - Actualiza automáticamente el índice de búsqueda de SLiMS tras la importación.

## Archivos Modificados/Añadidos

1. **`admin/modules/bibliography/isbn_lookup.php`** (NUEVO)
   - Contiene toda la lógica de búsqueda en las 3 fuentes, la interfaz de usuario y el proceso de guardado en la base de datos.

2. **`admin/modules/bibliography/submenu.php`** (ACTUALIZADO)
   - Se ha añadido la entrada en el menú lateral de SLiMS bajo la sección "COPY CATALOGUING":
     ```php
     $menu['bibliography.isbn-lookup'] = array(__('ISBN Lookup'), MWB.'bibliography/isbn_lookup.php', __('Catalogar libros automáticamente mediante ISBN (Google Books, Open Library, BNE)'));
     ```

3. **`Dockerfile`** y **`entrypoint.sh`** (ACTUALIZADOS)
   - Se han añadido comandos para asegurar que los directorios de subida (`files`, `images`, `repository` y específicamente `images/docs`) tengan los permisos correctos (`chown www-data:www-data` y `chmod 775`).
   - El script `entrypoint.sh` ahora fuerza estos permisos cada vez que arranca el contenedor, lo cual es crucial para despliegues en Synology NAS donde los volúmenes montados pueden heredar permisos restrictivos del sistema host.

## Uso

1. Inicie sesión en SLiMS como administrador.
2. Vaya al módulo **Bibliografía** (Bibliography).
3. En el menú de la izquierda, busque la sección **COPY CATALOGUING** y haga clic en **ISBN Lookup**.
4. Introduzca el ISBN (con o sin guiones, 10 o 13 dígitos).
5. Seleccione las fuentes en las que desea buscar (Google, Open Library, ISBN España).
6. Haga clic en **Buscar**.
7. Seleccione los resultados deseados marcando la casilla correspondiente y pulse **Guardar en Base de Datos**.
