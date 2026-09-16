<?php

namespace HankChen\CanvasNext\Renderer\Image;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;

/**
 * 共享 ImageManager 单例：优先 Imagick 驱动，扩展未加载时退回 GD
 * （intervention/image v4 无 ImageManagerStatic，本后端所有图像创建/解码都经此处）
 */
class ImageManagerFactory
{
    private static ?ImageManager $manager = null;

    public static function make(): ImageManager
    {
        if (self::$manager === null) {
            self::$manager = new ImageManager(
                extension_loaded('imagick') ? new ImagickDriver() : new GdDriver()
            );
        }

        return self::$manager;
    }
}
