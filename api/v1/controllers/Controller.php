<?php

/**
 * @Date                : 2017-07-05 12:17:02
 * @File name           : Controller.php
 * @Description         : Clase base para todos los controladores de la API
 */

class Controller
{
    /**
     * Enviar respuesta en formato JSON
     * 
     * @param mixed $data Datos a enviar (array o string JSON)
     * @return bool
     */
    public function withJson($data)
    {
        header('Content-type: application/json');
        if (is_array($data)) {
            echo json_encode($data);
        } else {
            echo $data;
        }

        return true;
    }
}
