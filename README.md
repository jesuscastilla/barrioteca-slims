# Barrioteca Acalencá — SLiMS (Backend)

Instancia de **SLiMS 9 Bulian** (Senayan Library Management System) adaptada para la **Barrioteca Acalencá**, una biblioteca vecinal autogestionada de Salobreña.

## ¿Qué es esto?

SLiMS es el software de gestión bibliotecaria que usamos como backend. Aquí se almacenan:
- Los datos de las socias (nombre, ID de socia, fecha de registro...)
- El catálogo de libros (título, autora, ISBN, editorial...)
- Los ejemplares y su estado (disponible / prestado)
- El histórico de préstamos y devoluciones

Sobre esta base de datos se apoya la [PWA de préstamos](../PWA/README.md) que usan las socias desde el móvil.

## Cómo funciona la autogestión

La barrioteca se organiza de forma vecinal y horizontal:

1. **Administración**: Una o varias vecinas con acceso al panel de SLiMS dan de alta a nuevas socias, añaden libros al catálogo y gestionan incidencias.
2. **Socias**: Cualquier vecina puede recibir un ID de socia. Con ese ID entra en la PWA y comienza a gestionar sus propios préstamos.
3. **Préstamos y devoluciones**: Se realizan escaneando el código de barras del libro con la cámara del móvil. No hace falta intervención de la administración para el día a día.
4. **Transparencia**: Cualquier socia puede consultar el catálogo y saber si un libro está disponible.

### Flujo completo

```
Vecina se registra  →  Administradora crea la socia en SLiMS
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

### Catalogador por ISBN

Módulo `isbn_lookup.php` que permite catalogar libros automáticamente introduciendo su ISBN. Consulta múltiples fuentes:
- Google Books API
- Open Library API
- ISBN España (Ministerio de Cultura)

No requiere la extensión `php-yaz` ni Z39.50, lo que facilita su uso en NAS Synology.

### Lenguaje feminizado

La interfaz administrativa y la API usan lenguaje en femenino (socia, autora) para mantener la coherencia con el frontend.

## Requisitos técnicos

- **PHP** ≥ 8.1 con extensiones: `mysqli`, `pdo_mysql`, `gd`, `curl`, `mbstring`, `intl`, `openssl`, `xml`, `zip`
- **MariaDB** 10.3+ (o MySQL 5.7+)
- **Servidor web**: Apache o Nginx (Web Station en Synology)
- **NAS Synology**: Probado con Web Station, MariaDB 10 y phpMyAdmin

## Instalación

Consulta la [guía de instalación en Synology NAS](MANUAL_INSTALL_SYNOLOGY.md) para instrucciones paso a paso.

## Créditos

SLiMS es software libre creado originalmente por el equipo de desarrollo de Senayan (Indonesia).
Esta instancia está modificada y mantenida por la Barrioteca Acalencá.

## Licencia

GNU General Public License v3.0
