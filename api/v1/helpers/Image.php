<?php
/**
 * @Created by          : Waris Agung Widodo (ido.alit@gmail.com)
 * @Date                : 10/09/20 21.43
 * @File name           : Image.php
 */

trait Image
{
    function getImagePath($image, $path = 'docs')
    {
        // Construir URL absoluta a SLiMS desde cualquier contexto (API o web)
        // SWB puede ser /slims/ o /slims/api/ según dónde se cargue
        $baseUrl = rtrim(SWB, '/');
        // Si se cargó desde api/, subir un nivel para llegar a la raíz de SLiMS
        if (str_ends_with($baseUrl, '/api')) {
            $baseUrl = dirname($baseUrl);
        }
        $baseUrl .= '/';

        $image = urlencode($image??'');
        $images_loc = 'images/' . $path . '/' . $image;
        $img_status = pathinfo(IMGBS . $path . '/' . ($image??''));
        if(!empty($image) && isset($img_status['extension'])){
            return $baseUrl . 'lib/minigalnano/createthumb.php?filename=' . urlencode($images_loc) . '&width=120';
        }
        return $baseUrl . 'lib/minigalnano/createthumb.php?filename=images/default/image.png&width=120';
    }
}