# Guía de Instalación Manual en Synology NAS (Web Station)

Esta guía explica cómo instalar SLiMS y la PWA en tu NAS sin utilizar Docker, usando directamente Web Station, MariaDB y PHPMyAdmin.

## Requisitos Previos

1.  **Web Station**: Instalado y configurado con un perfil de PHP 8.1 o superior.
2.  **MariaDB 10**: Instalado y escuchando en el puerto **3307**.
3.  **Extensiones PHP**: Asegúrate de que las siguientes extensiones estén activadas en el perfil de PHP de Web Station:
    - `mysqli`, `pdo_mysql`, `gd`, `curl`, `mbstring`, `intl`, `openssl`, `xml`, `zip`.
    - *Nota: No es necesario `php-yaz` ya que hemos implementado un catalogador personalizado.*

## Paso 1: Configuración de SLiMS (Backend)

1.  Copia la carpeta `acalenca-barrioteca` a la carpeta compartida `web` de tu NAS.
2.  **Base de Datos**: 
    - Entra en PHPMyAdmin.
    - Crea una base de datos llamada `acalenca`.
    - Importa el archivo SQL inicial (si lo tienes) o deja que SLiMS lo cree.
    - El archivo `config/database.php` ya está configurado con el puerto **3307** y tus credenciales.
3.  **Permisos de Escritura**:
    - En File Station, haz clic derecho en las siguientes carpetas dentro de `acalenca-barrioteca`:
        - `files/`, `images/`, `repository/`.
    - Ve a **Propiedades > Permiso** y otorga permisos de **Lectura y Escritura** al usuario `http`. Asegúrate de marcar "Aplicar a esta carpeta, las subcarpetas y los archivos".

## Paso 2: Configuración de la PWA (Frontend)

La PWA ha sido feminizada y preparada para funcionar sin Node.js.

1.  **Compilación**: 
    - En tu ordenador local, dentro de `acalenca-barrioteca-app`, ejecuta:
      ```bash
      npm install
      npm run build
      ```
    - Esto generará una carpeta `dist`.
2.  **Instalación en el NAS**:
    - Copia el contenido de la carpeta `dist` a una subcarpeta dentro de `web` (ej: `web/app`).
    - Copia el archivo `api-proxy.php` (que está en la raíz de SLiMS) a la misma carpeta donde has puesto la PWA.
3.  **Conexión**:
    - La PWA ahora buscará el archivo `api-proxy.php` en su propia carpeta para comunicarse con SLiMS.

## Paso 3: Configuración en Web Station

1.  Crea un **Servicio Web** de tipo "Sitio web estático" para la PWA.
2.  Crea un **Servicio Web** de tipo "Script PHP" para SLiMS.
3.  Si quieres usar HTTPS (necesario para instalar la PWA en el móvil), configura un certificado Let's Encrypt en el NAS y asignalo a los portales web creados.

---
*Feminización aplicada: Socia, Autora, Bienvenida.*
