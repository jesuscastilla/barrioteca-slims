<?php
/**
 * @Date                : 11/09/20 18.13
 * @File name           : Cache.php
 */

class Cache
{
    /**
     * Guardar datos en el cache
     * 
     * @param string $name Nombre del cache
     * @param string $data Datos a guardar (JSON)
     */
    static function set($name, $data) {
        $path = __DIR__ . '/../../../files/cache/cache_' . $name . '.json';
        
        // Verificar si el directorio existe, si no, crearlo
        if(!is_dir(dirname($path))){
            mkdir(dirname($path), 0755);
        }
        file_put_contents($path, $data);
    }

    /**
     * Obtener datos del cache
     * 
     * @param string $name Nombre del cache
     * @return false|string|null Datos del cache o null si expiró
     */
    static function get($name) {
        $path = __DIR__ . '/../../../files/cache/cache_' . $name . '.json';
        // El cache expira después de 5 horas (18000 segundos)
        if (file_exists($path) && time() - 18000 < filemtime($path)) {
            return file_get_contents($path);
        }
        return null;
    }

    /**
     * Eliminar un archivo de cache
     * 
     * @param string $name Nombre del cache
     * @return void
     */
    static function destroy($name)
    {
        $path = __DIR__ . '/../../../files/cache/cache_' . $name . '.json';
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
