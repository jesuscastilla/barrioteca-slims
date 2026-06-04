<?php
/**
 * @Date                : 10/09/20 21.43
 * @File name           : Image.php
 */

trait Image
{
    /**
     * Obtener la ruta de la imagen con miniatura
     * 
     * @param string $image Nombre del archivo de imagen
     * @param string $path Directorio de la imagen (docs, persons, etc)
     * @return string URL de la miniatura
     */
    function getImagePath($image, $path = 'docs')
    {
        // Variable para la URL de la miniatura
        $thumb_url = '';
        $image = urlencode($image??'');
        $images_loc = 'images/' . $path . '/' . $image;
        $img_status = pathinfo('images/' . $path . '/' . $image);
        
        if(isset($img_status['extension'])){
            $thumb_url = './lib/minigalnano/createthumb.php?filename=' . urlencode($images_loc) . '&width=120';
        }else{
            $thumb_url = './lib/minigalnano/createthumb.php?filename=images/default/image.png&width=120';
        }

        return $thumb_url;
    }
}
