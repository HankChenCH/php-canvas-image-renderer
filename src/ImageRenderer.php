<?php

namespace HankChen\CanvasNext\Renderer\Image;

use GdImage;
use HankChen\CanvasNext\Contracts\ShapeCapableRenderer;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
use HankChen\CanvasNext\Renderer\AbstractRenderer;
use Imagick;
use Intervention\Image\Geometry\Factories\EllipseFactory;
use Intervention\Image\Geometry\Factories\LineFactory;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;

/**
 * intervention/image v4 位图渲染后端
 *
 * begin/end 维护画布位图；绘制原语映射为 v4 的矩形/直线/图片/文本操作。
 * 形状能力口（ADR 0026）：drawEllipse 椭圆绘制 + drawImageMasked 掩码 blit，
 * 供核心包分派层圆形档（clip=circle）探测调用
 */
final class ImageRenderer extends AbstractRenderer implements ShapeCapableRenderer
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

    /**
     * 双矩形 blit（ADR 0024）：把源图 (sx, sy) 起的 sw×sh 矩形非等比拉伸搬运至
     * 目标 (dx, dy) 处的 dw×dh 矩形——decode → crop 源矩形 → resize 拉伸 →
     * insert 左上对位（替换恒 cover，fit 几何全在核心包分派层）。
     * srcRect 坐标系与分派层 intrinsicSize 同系：intervention v4 autoOrientation
     * 双驱动解码即 EXIF 归正，解码产物恒为观察方向位图（07 票裁决；历史版本
     * 的显式 orient() 在此恒空转，已移除）。sw/sh ≤ 0 为整图哨兵：取归正位图
     * 整幅；dw/dh ≤ 0 防护不画（目标盒已由分派层 drawRect 承担）
     */
    protected function drawImage(string $src, int $sx, int $sy, int $sw, int $sh, int $dx, int $dy, int $dw, int $dh): void
    {
        if ($dw <= 0 || $dh <= 0) {
            return;
        }

        $this->image->insert($this->blitSource($src, $sx, $sy, $sw, $sh, $dw, $dh), $dx, $dy, 'top-left');
    }

    /**
     * 椭圆绘制原语（ADR 0026）：在 (x, y) 起的 width×height 外接矩形内画内切椭圆，
     * fillColor 填充 + stroke 圆周描边一体承接（圆形档的背景填充与描边环同原语两调）。
     * v4 椭圆 at() 语义是圆心、size() 是直径——外接矩形经 ellipseCenter() 换算圆心
     * （唯一落点，掩码合成同用）；width/height ≤ 0 防护不画。
     * 描边带落位随驱动原生行为（GD 内描、Imagick 居中），环位分歧由采样验收
     * 取公共带吸收——spec 未钉对齐方式，钉死归后端工票
     *
     * @param array{width: int, color: string}|null $stroke
     */
    public function drawEllipse(int $x, int $y, int $width, int $height, ?string $fillColor, ?array $stroke): void
    {
        if ($width <= 0 || $height <= 0) {
            return;
        }

        [$centerX, $centerY] = self::ellipseCenter($x, $y, $width, $height);
        $this->image->drawEllipse(function (EllipseFactory $ellipse) use ($centerX, $centerY, $width, $height, $fillColor, $stroke) {
            $ellipse->at($centerX, $centerY)
                ->size($width, $height);

            if ($fillColor !== null && $fillColor !== '') {
                $ellipse->background($fillColor);
            }

            if ($stroke !== null) {
                $ellipse->border($stroke['color'], $stroke['width']);
            }
        });
    }

    /**
     * 掩码 blit 原语（ADR 0026，drawImage 的掩码形态）：双矩形 blit（语义与
     * drawImage 完全一致，含 sw/sh ≤ 0 整图哨兵）后以 (maskX, maskY) 起的
     * maskW×maskH 外接矩形内切椭圆为掩码裁切，掩码外透明——分派层传
     * 正方形即圆。掩码矩形是画布绝对坐标，与目标矩形独立（换算到目标
     * 坐标系后超出部分自然裁掉）。双驱掩码合成：Imagick 走 DSTIN 通道
     * 合成（掩码软边抗锯齿保留），GD 无原生掩码走逐像素二值 alpha。
     * dw/dh/maskW/maskH ≤ 0 防护不画
     */
    public function drawImageMasked(
        string $src,
        int $sx,
        int $sy,
        int $sw,
        int $sh,
        int $dx,
        int $dy,
        int $dw,
        int $dh,
        int $maskX,
        int $maskY,
        int $maskW,
        int $maskH
    ): void {
        if ($dw <= 0 || $dh <= 0 || $maskW <= 0 || $maskH <= 0) {
            return;
        }

        $image = $this->blitSource($src, $sx, $sy, $sw, $sh, $dw, $dh);

        // 掩码画布与目标矩形同尺寸，内切椭圆白、外透明（createImage 透明底 + 椭圆填充）
        $mask = ImageManagerFactory::make()->createImage($dw, $dh);
        $relX = $maskX - $dx;
        $relY = $maskY - $dy;
        [$centerX, $centerY] = self::ellipseCenter($relX, $relY, $maskW, $maskH);
        $mask->drawEllipse(function (EllipseFactory $ellipse) use ($centerX, $centerY, $maskW, $maskH) {
            $ellipse->at($centerX, $centerY)
                ->size($maskW, $maskH)
                ->background('#ffffff');
        });

        $this->applyMask($image, $mask);
        $this->image->insert($image, $dx, $dy, 'top-left');
    }

    /**
     * 外接矩形 → 圆心换算（v4 椭圆 at() 语义是圆心）：drawEllipse 与掩码合成
     * 共用，公式唯一落点防漂移（coverSourceWindow 同门先例）
     *
     * @return array{0: int, 1: int} [centerX, centerY]
     */
    private static function ellipseCenter(int $x, int $y, int $width, int $height): array
    {
        return [$x + intdiv($width, 2), $y + intdiv($height, 2)];
    }

    /**
     * blit 共享前段（drawImage/drawImageMasked 同源）：decode → crop 源矩形 →
     * resize 非等比拉伸至目标尺寸
     */
    private function blitSource(string $src, int $sx, int $sy, int $sw, int $sh, int $dw, int $dh): ImageInterface
    {
        $image = ImageManagerFactory::make()->decode($src);

        if ($sw > 0 && $sh > 0) {
            $image = $image->crop($sw, $sh, $sx, $sy);
        }

        return $image->resize($dw, $dh);
    }

    /**
     * 掩码合成双驱：按 blit 源的原生核心分派——
     * Imagick：先把源置为显式 alpha 通道（JPEG 等无 alpha 源补不透明通道），
     * 再以掩码做 DSTIN 合成（结果 alpha = 源 alpha × 掩码 alpha，矢量软边保留）；
     * GD：掩码为二值（imagefilledellipse 无抗锯齿），掩码外逐像素置全透明、
     * 掩码内源 alpha 不动
     */
    private function applyMask(ImageInterface $image, ImageInterface $mask): void
    {
        $native = $image->core()->native();
        $maskNative = $mask->core()->native();

        if ($native instanceof Imagick) {
            $native->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            $native->compositeImage($maskNative, Imagick::COMPOSITE_DSTIN, 0, 0);

            return;
        }

        /** @var GdImage $native */
        imagealphablending($native, false);
        imagesavealpha($native, true);

        for ($y = 0; $y < $image->height(); $y++) {
            for ($x = 0; $x < $image->width(); $x++) {
                // GD alpha 值：0 = 不透明，127 = 全透明；掩码内（0）保持源像素
                if (((imagecolorat($maskNative, $x, $y) >> 24) & 0x7F) === 0) {
                    continue;
                }
                $rgba = imagecolorsforindex($native, imagecolorat($native, $x, $y));
                imagesetpixel(
                    $native,
                    $x,
                    $y,
                    imagecolorallocatealpha($native, $rgba['red'], $rgba['green'], $rgba['blue'], 127),
                );
            }
        }
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
