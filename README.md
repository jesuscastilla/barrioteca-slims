# Barrioteca Acalencá — SLiMS (Backend)

Instancia de **SLiMS 9 Bulian** (Senayan Library Management System) adaptada para la **Barrioteca Acalencá**, una biblioteca vecinal autogestionada de Salobreña.

## ¿Qué es esto?

SLiMS es el software de gestión bibliotecaria que usamos como backend. Aquí se almacenan:
- Los datos de las socias (nombre, ID de socia, fecha de registro...)
- El catálogo de libros (título, autora, ISBN, editorial...)
- Los ejemplares y su estado (disponible / prestado)
- El histórico de préstamos y devoluciones

Sobre esta base de datos se apoya la aplicación (PWA) que usan las socias desde el móvil. (https://github.com/jesuscastilla/acalenca-barrioteca-app)

## Cómo funciona la autogestión

La barrioteca se organiza de forma vecinal y horizontal:

1. **Administración**: Una o varias vecinas con acceso al panel de SLiMS dan de alta a nuevas socias, añaden libros al catálogo y gestionan incidencias.
2. **Socias**: Cualquier vecina puede recibir un ID de socia. Con ese ID entra en la PWA y comienza a gestionar sus propios préstamos.
3. **Préstamos y devoluciones**: Se realizan escaneando el código de barras del libro con la cámara del móvil. No hace falta intervención de la administración para el día a día.
4. **Transparencia**: Cualquier socia puede consultar el catálogo y saber si un libro está disponible.

### Flujo completo

```
Vecina se asocia  →  Administradora crea la usuaria en SLiMS
                    →  Vecina recibe su ID (ej. SOCIA-001)
                    →  Abre la PWA, introduce su ID
                    →  Escanea el libro que quiere (ISBN/ASIN)
                    →  SLiMS registra el préstamo
                    →  Al devolver, escanea otra vez
                    →  SLiMS libera el ejemplar
```

## Personalizaciones realizadas

Este SLiMS incluye varias adaptaciones para el proyecto:

### API REST de circulación

Se ha añadido un `CirculationController` con endpoints específicos para la PWA:

| Endpoint | Método | Función |
|---|---|---|
| `/api/v1/member/{id}/verify` | GET | Verificar si una socia existe |
| `/api/v1/item/{isbn}/status` | GET | Consultar disponibilidad de un ejemplar |
| `/api/v1/loan/borrow` | POST | Registrar un préstamo |
| `/api/v1/loan/return` | POST | Registrar una devolución |
| `/api/v1/biblio/search` | GET | Buscar en el catálogo |

### Scripts de importacion de libros

La Barrioteca dispone de varios scripts para añadir libros al catalogo desde fuentes externas, sin necesidad de introducir los datos a mano en el panel de administracion.

#### 1. `importar-csv.php` — Importacion masiva por ISBN (por lotes)

- **Ubicacion en repo:** `PWA/importar-csv.php`
- **Se sube a:** `/slims/importar-csv.php`
- **Acceso:** `https://pelotxo.synology.me/slims/importar-csv.php`
- Sube un archivo CSV con ISBNs escaneados y los procesa por lotes de 3 libros
- Avance automatico entre lotes con cuenta atras de 5 segundos (evita timeout 504)
- Consulta Open Library (gratis) y Google Books como fuentes de metadatos
- Descarga portadas automaticamente a `images/docs/`
- Muestra ISBNs no encontrados al finalizar
- Soporta reinicio y eliminacion del propio script cuando se termina

#### 2. `anadir-libro.php` — Añadir libros sin ISBN (busqueda + manual)

- **Ubicacion en repo:** `SLiMS/anadir-libro.php`
- **Se sube a:** `/slims/anadir-libro.php`
- **Acceso:** `https://pelotxo.synology.me/slims/anadir-libro.php`
- Busca por titulo (+ autor opcional) en Open Library y Google Books
- Muestra hasta 5 resultados con portada, sinopsis y metadatos
- Permite seleccionar un resultado, editar los datos y guardar
- Formulario manual completo: titulo, autoras, editorial, ano, paginas, sinopsis, URL de portada
- Preview de portada en vivo
- Soporta libros con o sin ISBN
- Inserta en `biblio` con `gmd_id=1`, crea autores/editoriales si no existen, indexa

#### 3. `importar-isbns.php` — Importacion simple de ISBNs (uno por linea)

- **Ubicacion en repo:** `PWA/importar-isbns.php`
- Permite pegar una lista de ISBNs (uno por linea) en un campo de texto
- Busca y añade cada ISBN usando Google Books + Open Library
- Util para añadir unos pocos libros sin necesidad de preparar un CSV

#### 4. `isbn_lookup.php` — Catalogador original por ISBN

Modulo original de SLiMS que permite catalogar libros automaticamente introduciendo su ISBN:
- Google Books API
- Open Library API
- ISBN España (Ministerio de Cultura)

No requiere la extension `php-yaz` ni Z39.50, lo que facilita su uso en NAS Synology.

### Lenguaje feminizado

La interfaz administrativa y la API usan lenguaje en femenino (socia, autora) para mantener la coherencia con el frontend.

## Infraestructura

La Barrioteca Acalenca se aloja en un **NAS Synology** que proporciona una nube local encriptada y autogestionada, sin dependencia de servidores externos. El acceso al panel de administracion (DSM) se realiza via `https://pelotxo.synology.me:5001`.

## Requisitos técnicos

- **PHP** ≥ 8.1 con extensiones: `mysqli`, `pdo_mysql`, `gd`, `curl`, `mbstring`, `intl`, `openssl`, `xml`, `zip`
- **MariaDB** 10.3+ (o MySQL 5.7+)
- **Servidor web**: Apache o Nginx (Web Station en Synology)
- **NAS Synology**: Probado con Web Station, MariaDB 10 y phpMyAdmin

## Instalación

Consulta la [guía de instalación en Synology NAS](MANUAL_INSTALL_SYNOLOGY.md) para instrucciones paso a paso.

## Créditos

SLiMS es software libre creado originalmente por el equipo de desarrollo de Senayan (Indonesia).
Esta instancia esta modificada y mantenida por la Barrioteca Acalenca, un espacio perteneciente a Lebeche, una asociacion cultural y vecinal de Salobrena (Granada). Todo el codigo modificado ha sido desarrollado por Peloxi (Instagram: @Pelochochi).

## Licencia

GNU General Public License v3.0
