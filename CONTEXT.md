# Contexto del Proyecto - Barrioteca Acalenca

Este archivo contiene el contexto necesario para retomar el trabajo sin perder informacion.

---

## Arquitectura General

```
Dominio publico:   https://pelotxo.synology.me
NAS:               Synology con Web Station (Nginx 1.23.1) + PHP 8 + MariaDB
                   Acceso a DSM (admin): https://pelotxo.synology.me:5001
                   Nube local encriptada y autogestionada
```

### Dos proyectos en el repositorio:

| Carpeta | Proyecto | Tecnologia | URL publica |
|---------|----------|------------|-------------|
| `PWA/` | Frontend (app movil) | React 19 + Vite + Tailwind + TypeScript | `https://pelotxo.synology.me/barrioteca/` |
| `SLiMS/` | Backend (biblioteca) | SLiMS 9.7.2 (PHP + MariaDB) | `https://pelotxo.synology.me/slims/` |

---

## Comunicacion PWA <-> SLiMS

```
PWA (React) -> api-proxy.php (proxy PHP) -> SLiMS API (api/index.php)
```

### El proxy PHP (`PWA/api-proxy.php`)

- Se aloja en `/barrioteca/api-proxy.php`
- Recibe peticiones con `?action=xxx&param=valor`
- Traduce a llamadas a SLiMS mediante `?_api_path=/member/...`
- El frontend siempre usa `./api-proxy.php` (ruta relativa)
- Soporta parametros via query string (GET) porque Nginx del NAS a veces no pasa el body POST

### Endpoints del proxy:

| Accion | Metodo | Parametros |
|--------|--------|------------|
| `verify-member` | POST (con fallback GET) | `member_id` |
| `perform-action` | POST (con fallback GET) | `accion`, `code`, `member_id` |
| `catalog-proxy` | GET | `q` (busqueda) |
| `catalog-list` | GET | ninguno (devuelve todos) |
| `book-metadata` | GET | `isbn` |

---

## Configuracion del NAS

### Nginx peculiaridades:
- No pasa `$_GET` a PHP cuando se usa URL rewriting -> solucion: parsear `QUERY_STRING` manualmente en PHP
- A veces no pasa el body POST -> solucion: enviar parametros tambien en query string

### api-config.php (en /barrioteca/, NO en el repo):
```php
define('GOOGLE_BOOKS_API_KEY', 'AIzaSyAg...');
define('SLIMS_API_BASE', 'http://localhost/slims/api/index.php');
```

### .env (solo para desarrollo Node.js, no se usa en NAS):
```
VITE_API_ENDPOINT=/api   # Desarrollo local
SLIMS_API_BASE=http://localhost/slims/api/index.php
```

---

## Pagina principal de SLiMS (index.php)

- Fondo blanco suave con parrilla responsive de todos los libros (sin limite)
- Tarjetas con portada grande, titulo, autora, ISBN
- Al hacer clic: modal con portada ampliada, metadatos y sinopsis completa
- Barra superior con botones "Socias" (login OPAC) y "Staff" (panel admin)
- La pagina carga el OPAC original cuando hay parametros `?p=` (login, member, etc)
- Respaldo del OPAC original en `index_opac_original.php`

## Endpoint member-loans

- `GET /api/v1/member/{id}/loans` en SLiMS (CirculationController@getMemberLoans)
- `action=member-loans&member_id=XXX` en el proxy
- La PWA muestra "Mis Prestamos" en el dashboard cuando hay una socia activa
- Devuelve titulo, fechas de prestamo y vencimiento para cada libro

## Service Worker (sw.js)

- Network-first: siempre intenta cargar desde internet primero
- Solo cachea iconos y manifest (recursos estaticos que nunca cambian)
- El HTML, JS y CSS se actualizan automaticamente en los moviles
- Las peticiones a la API nunca se cachean

## Scripts de importacion de libros

### 1. `importar-csv.php` — Importacion masiva por ISBN (lotes)

- **Ubicacion:** `PWA/importar-csv.php` → se sube a `/slims/importar-csv.php`
- Insercion correcta en `biblio` con `gmd_id=1` (no solo en items)
- Procesa por lotes de 3 libros para evitar timeout 504
- Avance automatico entre lotes con cuenta atras de 5 segundos
- Al finalizar muestra ISBNs no encontrados + boton "Subir otro archivo CSV"
- Consulta Open Library (gratis) y Google Books (espanol) como respaldo
- Descarga portadas automaticamente

### 2. `anadir-libro.php` — Añadir libros sin ISBN (manual + busqueda)

- **Ubicacion:** `SLiMS/anadir-libro.php` → se sube a `/slims/anadir-libro.php`
- Busca por titulo (+ autor opcional) en Open Library y Google Books
- Muestra hasta 5 resultados con portada y sinopsis
- Permite seleccionar un resultado y editar los datos antes de guardar
- Formulario manual completo (titulo, autoras, editorial, ano, paginas, sinopsis, URL de portada)
- Preview de portada en vivo
- Soporta libros con o sin ISBN
- Inserta en `biblio` con `gmd_id=1`, crea autores/editoriales si no existen, indexa

### 3. `importar-isbns.php` — Importacion simple de ISBNs

- **Ubicacion:** `PWA/importar-isbns.php`
- Permite pegar una lista de ISBNs (uno por linea)
- Busca y añade cada ISBN individualmente usando Google Books + Open Library

---

## Bugs conocidos ya corregidos

### PWA

| # | Bug | Solucion |
|---|-----|----------|
| 1 | Scanner esperaba `onScanSuccess`, App pasaba `onResult` | Renombrado a `onResult` |
| 2 | `CatalogSearch` no aceptaba `onBack` prop | Convertido a `CatalogList` con prop |
| 3 | Endpoint hardcodeado a `./api-proxy.php` | `getEndpoint()` lee `VITE_API_ENDPOINT` |
| 4 | Toast PWA aparecia aunque descartado | Check `pwa_install_dismissed` |
| 5 | `api-proxy.php` no pasaba body a SLiMS (hacia GET) | `slimRequest()` con POST y body |
| 6 | Service Worker cacheaba `api-proxy.php` | Filtro anadido |
| 7 | Nginx no pasa `$_GET` ni body POST | Parseo manual de `QUERY_STRING` |
| 8 | `CatalogSearch` con busqueda por texto | Sustituido por `CatalogList` con lista completa |
| 9 | Libro sin ISBN no se podia prestar | Muestra `item_code` como fallback |
| 10 | Sinopsis no visible | Anadido modal pop-up al hacer clic en libro |
| 11 | Imagenes no cargaban en PWA | `Image.php` usa URL absoluta (`SWB`) |

### SLiMS

| # | Bug | Solucion |
|---|-----|----------|
| 1 | `b.author` no existe en tabla `biblio` | JOIN con `biblio_author` + `mst_author` |
| 2 | Mismo bug en `CirculationController` | Misma correccion de JOIN |
| 3 | OPAC publico accesible para cualquiera | `index.php` minimalista con boton Staff |
| 4 | Botones de navegacion no funcionaban | `index.php` carga OPAC original cuando hay `?p=` |
| 5 | `importar-csv.php` insertaba items huerfanos | Anadido `gmd_id=1` al INSERT |
| 6 | `docker-compose.yml` con contrasena hardcodeada | Variables de entorno |
| 7 | `entrypoint.sh` DB_PORT sin comillas | Corregido |
| 8 | Datos personales en backups y diffs | Eliminados o anadidos a `.gitignore` |
| 9 | Type hints faltantes en controladores (Member, Circulation, Biblio) | Anadidos `array`, `mysqli`, `string` y `@return void` |

---

## Estructura final del NAS (`/volume1/web/`)

```
/volume1/web/
├── barrioteca/              # PWA (build + proxy)
│   ├── index.html
│   ├── assets/
│   ├── api-proxy.php        # PROXY PHP (CRITICO)
│   ├── api-config.php       # Config (CREAR A MANO, NO en repo)
│   ├── sw.js
│   ├── manifest.json
│   └── icon*.png, logo.png
│
└── slims/                   # SLiMS (backend)
    ├── index.php             # Landing page minimalista
    ├── index_opac_original.php # Backup del OPAC
    ├── importar-csv.php      # Script de importacion CSV
    ├── api/index.php         # API REST bootstrap
    ├── api/v1/               # Rutas y controladores
    │   ├── routes.php
    │   ├── controllers/
    │   │   ├── BiblioController.php  # Busqueda en catalogo
    │   │   ├── CirculationController.php # Prestamos/devoluciones
    │   │   └── ...
    │   └── helpers/
    │       └── Image.php     # Rutas absolutas de imagenes
    ├── admin/                # Panel de administracion
    ├── config/
    ├── images/docs/          # Portadas de libros
    └── sysconfig.inc.php     # Carga la BD
```

**Ruta relativa entre PWA y SLiMS:** `../slims/` desde `/barrioteca/`
**Ruta absoluta en NAS:** `/volume1/web/slims/`

---

## Credenciales y datos de prueba

| Dato | Valor |
|------|-------|
| Admin SLiMS | `acalenca` |
| Socia test | `pelotxo` (expira 2027-06-27) |

---

## Flujo de trabajo para cambios

1. Editar localmente en `g:\GITHUB\PWA\` o `g:\GITHUB\SLiMS\`
2. Compilar PWA: `npm run build` (desde `PWA/`)
3. Subir al NAS:
   - `dist/` -> `/barrioteca/` (sobrescribir)
   - `api-proxy.php` -> `/barrioteca/` (si cambio)
   - `SLiMS/index.php` -> `/slims/index.php` (si cambio)
4. Probar: desde terminal con `curl` o desde navegador
5. Si falla el body POST, probar con GET (parametros en URL)
6. Commit en cada repo por separado

---

## Cosas que NO hacer

- No subir `.env`, `api-config.php`, `sysconfig.inc.php` al repo
- No hardcodear URLs de `pelotxo.synology.me` en el codigo
- No usar Node.js en el NAS (solo PHP)
- No eliminar `api-config.php` del NAS
- No sobreescribir `sw.js` sin incrementar `CACHE_NAME`
- No poner `gmd_id` en NULL en INSERTs a `biblio`

---

## Repositorios GitHub

- PWA:  https://github.com/jesuscastilla/acalenca-barrioteca-app
- SLiMS: https://github.com/jesuscastilla/acalenca-barrioteca

---

## Archivos que SIEMPRE deben subirse juntos al NAS

Cuando se modifica el frontend, hay que subir estos 3:

| Origen local | Destino NAS |
|-------------|-------------|
| `PWA/dist/` | `/barrioteca/` (sobrescribir todo) |
| `PWA/api-proxy.php` | `/barrioteca/api-proxy.php` |
| `SLiMS/index.php` | `/slims/index.php` |

### Para los scripts de importacion:

| Origen local | Destino NAS |
|-------------|-------------|
| `PWA/importar-csv.php` | `/slims/importar-csv.php` |
| `SLiMS/anadir-libro.php` | `/slims/anadir-libro.php` |
| `SLiMS/index_opac_original.php` | `/slims/index_opac_original.php` |
