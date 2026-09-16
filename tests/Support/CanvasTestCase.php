<?php

namespace HankChen\CanvasNext\Renderer\Image\Tests\Support;

use HankChen\CanvasNext\Renderer\Image\ImageManagerFactory;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Interfaces\ImageInterface;
use PHPUnit\Framework\TestCase;

/**
 * 位图渲染测试的公共基类：像素取样、PNG 生成、缓存目录清理
 */
abstract class CanvasTestCase extends TestCase
{
    /**
     * 当前是否使用 Imagick 驱动（CI 上 setup-php 预装 Imagick，本地多为 GD）
     */
    protected function usesImagickDriver(): bool
    {
        return ImageManagerFactory::make()->driver instanceof ImagickDriver;
    }

    /**
     * 找一个可用的系统 TTF 字体（跨平台测试用），找不到返回 null。
     * v4 的 Imagick 驱动没有内置字体机制，渲染文字必须给真实字体文件
     */
    protected function systemTtf(): ?string
    {
        $candidates = [
            // Linux
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            // macOS
            '/System/Library/Fonts/Supplemental/Arial.ttf',
            '/System/Library/Fonts/Supplemental/Times New Roman.ttf',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * 取某像素的 RGB 值（colorAt 返回 ColorInterface，channels() 为 [r,g,b,a] 通道对象）
     */
    protected function pixel(ImageInterface $image, int $x, int $y): array
    {
        $channels = $image->colorAt($x, $y)->channels();

        return [
            (int) $channels[0]->value(),
            (int) $channels[1]->value(),
            (int) $channels[2]->value(),
        ];
    }

    protected function assertPixelSame(array $expected, ImageInterface $image, int $x, int $y): void
    {
        $this->assertSame(
            $expected,
            $this->pixel($image, $x, $y),
            "像素({$x}, {$y}) 颜色不符合预期"
        );
    }

    /**
     * 生成纯色 PNG 的二进制内容
     */
    protected function pngBytes(int $width, int $height, string $color): string
    {
        $image = ImageManagerFactory::make()->createImage($width, $height);
        $image->fill($color);

        return $image->encodeUsingFileExtension('png')->toString();
    }

    /**
     * 核心包 ResourceResolver 的缓存目录（sys_get_temp_dir()/canvas-next/<sub>）
     */
    protected function cacheDir(string $sub): string
    {
        return sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'canvas-next'
            . DIRECTORY_SEPARATOR . $sub;
    }

    /**
     * 删除目录及其内容，保证每条用例都真正走到 mkdir 分支
     */
    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
