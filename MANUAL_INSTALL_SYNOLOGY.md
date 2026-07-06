# Guía de Instalación Manual en Synology NAS (Web Station)

Esta guía explica cómo instalar SLiMS y la PWA en tu NAS sin utilizar Docker, usando directamente Web Station, MariaDB y PHPMyAdmin.

## Requisitos Previos

1. **Web Station**: Instalado y configurado con un perfil de PHP 8.1 o superior.
2. **MariaDB 10**: Instalado y escuchando en el puerto configurado (por defecto 3307).
3. **Extensiones PHP**: Asegúrate de que las siguientes extensiones estén activadas en el perfil de PHP de Web Station:
   - `mysqli`, `pdo_mysql`, `gd`, `curl`, `mbstring`, `intl`, `openssl`, `xml`, `zip`.
   - *Nota: No es necesario `php-yaz` ya que hemos implementado un catalogador personalizado.*

## Paso 1: Configuración de SLiMS (Backend)

1. Copia la carpeta del proyecto SLiMS a la carpeta compartida `web` de tu NAS.
2. **Base de Datos**:
   - Entra en PHPMyAdmin.
   - Crea una base de datos para SLiMS.
   - Importa el archivo SQL inicial (si lo tienes) o deja que SLiMS lo cree durante la instalación.
   - Copia `config/database.sample.php` a `config/database.php` y configura tus credenciales y el puerto.
3. **Permisos de Escritura**:
   - En File Station, haz clic derecho en las siguientes carpetas dentro del proyecto SLiMS:
     - `files/`, `images/`, `repository/`.
   - Ve a **Propiedades > Permiso** y otorga permisos de **Lectura y Escritura** al usuario `http`. Asegúrate de marcar "Aplicar a esta carpeta, las subcarpetas y los archivos".

## Paso 2: Configuración de la PWA (Frontend)

La PWA se comunica con SLiMS a través de un proxy PHP.

1. **Compilación**:
   - En tu ordenador local, dentro de la carpeta de la PWA, ejecuta:
     ```bash
     npm install
     npm run build
     ```
   - Esto generará una carpeta `dist`.
2. **Instalación en el NAS**:
   - Copia el contenido de la carpeta `dist` a una subcarpeta dentro de `web` (ej: `web/barrioteca`).
   - Copia el archivo `api-proxy.php` (de la raíz del proyecto PWA) a la misma carpeta donde has puesto la PWA.
   - Crea `api-config.php` copiando `api-config.example.php` y configurando:
     ```php
     define('GOOGLE_BOOKS_API_KEY', 'tu-clave');
     define('SLIMS_API_BASE', 'http://localhost/slims/api/index.php');
     ```
3. **Conexión**:
   - La PWA busca `./api-proxy.php` en su propia carpeta para comunicarse con SLiMS. No necesita reglas de reescritura en Nginx.

## Paso 3: Configuración en Web Station

1. Crea un **Servicio Web** de tipo "Sitio web estático" para la PWA (carpeta `barrioteca`).
2. Crea un **Servicio Web** de tipo "Script PHP" para SLiMS (carpeta `slims`).
3. Si quieres usar HTTPS (necesario para instalar la PWA en el móvil), configura un certificado Let's Encrypt en el NAS y asígnalo a los portales web creados.

## Verificación

1. Accede a `https://TU-DOMINIO.synology.me/slims/install/` para verificar que SLiMS está accesible.
2. Accede a `https://TU-DOMINIO.synology.me/barrioteca/` para verificar que la PWA carga.
