<?php

namespace HankChen\CanvasNext\Renderer\Image;

use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
use HankChen\CanvasNext\Renderer\AbstractRenderer;
use Intervention\Image\Geometry\Factories\LineFactory;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;

/**
 * intervention/image v4 位图渲染后端
 *
 * begin/end 维护画布位图；绘制原语映射为 v4 的矩形/直线/图片/文本操作
 */
final class ImageRenderer extends AbstractRenderer
{
    private ImageInterface $image;

    public function __construct(?ResourceResolver $resolver = null)
    {
        parent::__construct($resolver ?? new ResourceResolver());
    }

    protected function begin(int $width, int $height): void
    {
        $this->image = ImageManagerFactory::make()->createImage($width, $height);
    }

    protected function end(): ImageInterface
    {
        return $this->image;
    }

    protected function drawRect(int $x, int $y, int $width, int $height, ?string $bgColor, array $border): void
    {
        if ($bgColor !== null && $bgColor !== '') {
            $this->image->drawRectangle(function (RectangleFactory $rect) use ($x, $y, $width, $height, $bgColor) {
                $rect->at($x, $y)->size($width, $height)->background($bgColor);
            });
        }

        if (!empty($border['top'])) {
            $this->drawBorderLine($x, $y, $x + $width, $y, $border['top']);
        }
        if (!empty($border['bottom'])) {
            $this->drawBorderLine($x, $y + $height, $x + $width, $y + $height, $border['bottom']);
        }
        if (!empty($border['left'])) {
            $this->drawBorderLine($x, $y, $x, $y + $height, $border['left']);
        }
        if (!empty($border['right'])) {
            $this->drawBorderLine($x + $width, $y, $x + $width, $y + $height, $border['right']);
        }
    }

    /**
     * @param array{width: int, color: string} $border
     */
    private function drawBorderLine(int $x1, int $y1, int $x2, int $y2, array $border): void
    {
        $this->image->drawLine(function (LineFactory $line) use ($x1, $y1, $x2, $y2, $border) {
            $line->from($x1, $y1)->to($x2, $y2)
                ->width($border['width'])
                ->color($border['color']);
        });
    }

    protected function drawImage(string $src, int $x, int $y, int $width, int $height): void
    {
        if ($width <= 0 || $height <= 0) {
            return;
        }

        $this->image->insert(
            ImageManagerFactory::make()
                ->decode($src)
                ->orient()
                ->cover($width, $height),
            $x,
            $y,
            'top-left'
        );
    }

    protected function drawText(
        string $line,
        int $x,
        int $y,
        string $fontFile,
        int $fontSize,
        string $fontColor,
        string $horizontalAlign,
        string $verticalAlign,
        int $angle
    ): void {
        if ($line === '') {
            return;
        }

        $this->image->text($line, $x, $y, function (FontFactory $font) use (
            $fontFile,
            $fontSize,
            $fontColor,
            $horizontalAlign,
            $verticalAlign,
            $angle
        ) {
            // 纯数字编号是旧库 GD 内置字体 id，v4 已移除该支持，跳过后走 v4 内置默认字体
            if ($fontFile !== '' && !is_numeric($fontFile)) {
                $font->file($fontFile);
            }

            $font->size($fontSize);
            $font->color($fontColor);
            $font->align($horizontalAlign, $verticalAlign);
            $font->angle($angle);
        });
    }
}
