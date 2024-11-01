<?php
namespace GpcDev\Includes;

class LoremPicsum
{
    public static function generate_image_ids($count)
    {
        $arr = range(100, 200);
        shuffle($arr);
        return array_slice($arr, 0, $count);
    }

    public static function download_images($count, $width = 1000, $height = 750)
    {
        $range = range(100, 200);
        shuffle($range);
        $ids = array_slice($range, 0, $count);

        $images = [];
        foreach ( $ids as $id ) {
            $images[] = self::download_image($id, $width, $height);
        }

        return $images;
    }

    public static function download_image($id, $width = 1000, $height = 700)
    {
        $url = "https://picsum.photos/id/{$id}/{$width}/{$height}";

		return AttachmentHelper::uploadFromUrl($url, false);
    }
}