<?php

/**
 * @Date                : 2017-07-04 15:27:14
 * @File name           : router.inc.php
 * @Description         : Extensión de AltoRouter para el manejo de rutas en SLiMS
 */

require 'AltoRouter.php';

class Router extends AltoRouter
{

    private $sysconf;
    private $db;
    
    function __construct($sysconf, $obj_db)
    {
        parent::__construct();
        $this->sysconf = $sysconf;
        $this->db = $obj_db;
    }

    /**
     * Buscar coincidencia de ruta para la petición actual
     */
    public function match($requestUrl = null, $requestMethod = null)
    {
        $params = array();
        $match = false;

        // Establecer URL de la petición si no se pasa como parámetro
        if($requestUrl === null) {
            $path = explode('/', $_GET['p']);
            if ($path[0] == $this->basePath) {
                $requestUrl = $_GET['p'];
            } else {
                $requestUrl = '/';
            }
        }

        // Eliminar el path base de la URL de la petición
        $requestUrl = substr($requestUrl, strlen($this->basePath));

        // Eliminar la cadena de consulta (?a=b) de la URL
        if (($strpos = strpos($requestUrl, '?')) !== false) {
            $requestUrl = substr($requestUrl, 0, $strpos);
        }

        // Establecer el método de la petición si no se pasa como parámetro
        if($requestMethod === null) {
            $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        }

        foreach($this->routes as $handler) {
            list($methods, $route, $target, $name) = $handler;

            $method_match = (stripos($methods, $requestMethod) !== false);

            // Si el método no coincide, continuar con la siguiente ruta
            if (!$method_match) continue;

            if ($route === '*') {
                // Comodín * (coincide con todo)
                $match = true;
            } elseif (isset($route[0]) && $route[0] === '@') {
                // Delimitador @ para expresiones regulares
                $pattern = '`' . substr($route, 1) . '`u';
                $match = preg_match($pattern, $requestUrl, $params) === 1;
            } elseif (($position = strpos($route, '[')) === false) {
                // Sin parámetros en la URL, hacer comparación de cadenas
                $match = strcmp($requestUrl, $route) === 0;
            } else {
                // Comparar la cadena más larga sin parámetros con la URL
                if (strncmp($requestUrl, $route, $position) !== 0) {
                    continue;
                }
                $regex = $this->compileRoute($route);
                $match = preg_match($regex, $requestUrl, $params) === 1;
            }

            if ($match) {
                if ($params) {
                    foreach($params as $key => $value) {
                        if(is_numeric($key)) unset($params[$key]);
                    }
                }

                return array(
                    'target' => $target,
                    'params' => $params,
                    'name' => $name
                );
            }
        }
        return false;
    }

    /**
     * Convertir una cadena de controlador@metodo en un ejecutable
     */
    public function makeCallable($string)
    {
        $method = explode('@', $string);
        if (isset($method[1]) && class_exists($method[0])) {
            $instance = new $method[0]($this->sysconf, $this->db);
            if (method_exists($instance, $method[1])) {
                return array($instance, $method[1]);
            }
        }
        return false;
    }

    /**
     * Ejecutar la ruta que coincida con la petición actual
     */
    public function run()
    {
        // Buscar coincidencia para la URL actual
        $match = $this->match();
        
        // Ejecutar el callback o lanzar error 404
        if( $match && is_callable( $match['target'] ) ) {
            call_user_func_array( $match['target'], $match['params'] ); 
        } else {
            if ($callable = $this->makeCallable($match['target']??'')) {
                call_user_func_array($callable, $match['params']);
            } else {
                // No se encontró ninguna ruta que coincida
                http_response_code(404);
                throw new Exception("¡Ruta no encontrada!");
            }
        }
    }
}
