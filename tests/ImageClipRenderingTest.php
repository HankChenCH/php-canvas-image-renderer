<?php

namespace HankChen\CanvasNext\Renderer\Image\Tests;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Renderer\Image\ImageManagerFactory;
use HankChen\CanvasNext\Renderer\Image\ImageRenderer;
use HankChen\CanvasNext\Renderer\Image\Tests\Support\CanvasTestCase;
use HankChen\CanvasNext\Runtime\NullCancellation;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * 裁剪形状圆形档像素采样（ADR 0026，assertPixelSame 无黄金文件）：
 * 圆形档 = 完整圆形框——背景色填进内切圆、内容按 fit 绘出后遮罩进圆、
 * 边框沿圆周描成环；四角透明露下层、padding 区留白、非方盒恒正圆留透明边；
 * circle × fit 四档正交组合；未知 clip 值降级矩形（描边带跨驱采样点取
 * GD 内描带与 Imagick 居中带的公共区，缘内缩留过渡带余量）
 */
class ImageClipRenderingTest extends CanvasTestCase
{
    public function testClipCircleBackgroundFillsInscribedCircleCornersRevealLowerLayer(): void
    {
        // 背景绿填进内切圆；四角在圆外透明露出垫底红层（整层渲染面裁进圆）
        $lower = ImageLayer::make(100, 100, '#f00')->setPriority(10);
        $circle = ImageLayer::make(100, 100, '#0f0')->setClip(ImageLayer::CLIP_CIRCLE)->setPriority(1);

        $image = (new ImageRenderer())->render(new NullCancellation(), Canvas::make(100, 100, $lower, $circle));

        $this->assertPixelSame([255, 0, 0], $image, 2, 2);
        $this->assertPixelSame([255, 0, 0], $image, 97, 2);
        $this->assertPixelSame([255, 0, 0], $image, 2, 97);
        $this->assertPixelSame([255, 0, 0], $image, 97, 97);
        $this->assertPixelSame([0, 255, 0], $image, 50, 50);
        // 圆内四向中点（r=50，采样点距缘 ≥14px 留过渡带余量）
        $this->assertPixelSame([0, 255, 0], $image, 15, 50);
        $this->assertPixelSame([0, 255, 0], $image, 85, 50);
        $this->assertPixelSame([0, 255, 0], $image, 50, 15);
        $this->assertPixelSame([0, 255, 0], $image, 50, 85);
    }

    public function testNonSquareBoxKeepsPerfectCircleWithTransparentFlanks(): void
    {
        // 非方盒 100×50：内切圆 center (50,25) r=25，左右留透明边、不变形为椭圆
        $lower = ImageLayer::make(100, 50, '#f00')->setPriority(10);
        $circle = ImageLayer::make(100, 50, '#ff0')->setClip(ImageLayer::CLIP_CIRCLE)->setPriority(1);

        $image = (new ImageRenderer())->render(new NullCancellation(), Canvas::make(100, 50, $lower, $circle));

        $this->assertPixelSame([255, 255, 0], $image, 50, 25);
        $this->assertPixelSame([255, 255, 0], $image, 32, 25);
        $this->assertPixelSame([255, 255, 0], $image, 50, 10);
        $this->assertPixelSame([255, 0, 0], $image, 10, 25);
        $this->assertPixelSame([255, 0, 0], $image, 90, 25);
        $this->assertPixelSame([255, 0, 0], $image, 2, 2);
    }

    public function testClipCircleImageMaskedIntoCircleCoverFit(): void
    {
        // cover（缺省）：方图进方盒整图绘出后遮罩进圆——圆内露图、圆外露下层
        $src = $this->bandPng(100, 100, '#f00', '#00f');
        $lower = ImageLayer::make(100, 100, '#0f0')->setPriority(10);
        $circle = ImageLayer::make(100, 100)->setImage($src)->setClip(ImageLayer::CLIP_CIRCLE)->setPriority(1);

        $image = (new ImageRenderer())->render(new NullCancellation(), Canvas::make(100, 100, $lower, $circle));

        $this->assertPixelSame([255, 0, 0], $image, 50, 25);
        $this->assertPixelSame([0, 0, 255], $image, 50, 75);
        $this->assertPixelSame([0, 255, 0], $image, 2, 2);
        $this->assertPixelSame([0, 255, 0], $image, 97, 97);
    }

    public function testClipCircleBorderStrokesRingAlongCircumference(): void
    {
        // 环带描边：padding 12 → 内切圆 side 76 center (50,50) r=38，border 宽 8 蓝——
        // GD 内描带 [30,38]、Imagick 居中带 [34,42]，采样半径 36（r - w/4）取公共带；
        // 环内露背景白、环外与四角露下层红（padding 即环外留白）
        $src = $this->solidPng(40, 40, '#123456');
        $lower = ImageLayer::make(100, 100, '#f00')->setPriority(10);
        $circle = ImageLayer::make(100, 100, '#ffffff')
            ->setImage($src)
            ->setPadding(12)
            ->setBorder(8, '#0000ff')
            ->setClip(ImageLayer::CLIP_CIRCLE)
            ->setPriority(1);

        $image = (new ImageRenderer())->render(new NullCancellation(), Canvas::make(100, 100, $lower, $circle));

        $this->assertPixelSame([0, 0, 255], $image, 14, 50);
        $this->assertPixelSame([0, 0, 255], $image, 86, 50);
        $this->assertPixelSame([0, 0, 255], $image, 50, 14);
        $this->assertPixelSame([0, 0, 255], $image, 50, 86);
        // 环内被图覆盖：圆心 = 源图色（先背景填充后内容绘出，图遮住同圆的背景填充）
        $this->assertPixelSame([18, 52, 86], $image, 50, 50);
        $this->assertPixelSame([255, 0, 0], $image, 2, 2);
        $this->assertPixelSame([255, 0, 0], $image, 50, 5);
    }

    public function testClipCircleWithFillFitStretchThenMask(): void
    {
        // fill：40×40 红图非等比拉伸进内容盒 100×50 后遮罩进内切圆 [25,0,50,50]
        $src = $this->solidPng(40, 40, '#f00');
        $lower = ImageLayer::make(100, 50, '#0f0')->setPriority(10);
        $circle = ImageLayer::make(100, 50)
            ->setImage($src)
            ->setFit(ImageLayer::FIT_FILL)
            ->setClip(ImageLayer::CLIP_CIRCLE)
            ->setPriority(1);

        $image = (new ImageRenderer())->render(new NullCancellation(), Canvas::make(100, 50, $lower, $circle));

        $this->assertPixelSame([255, 0, 0], $image, 50, 25);
        $this->assertPixelSame([255, 0, 0], $image, 35, 25);
        $this->assertPixelSame([0, 255, 0], $image, 10, 25);
    }

    public function testClipCircleWithContainFitGapInsideCircleStaysTransparent(): void
    {
        // contain：40×10 上下两色图进 100×100 盒收缩 100×25（y=37 起，半带界 y≈50）；
        // 圆内但收缩矩形外的空隙透明露下层——先绘后裁不改变 contain 几何
        $src = $this->bandPng(40, 10, '#f00', '#00f');
        $lower = ImageLayer::make(100, 100, '#0f0')->setPriority(10);
        $circle = ImageLayer::make(100, 100)
            ->setImage($src)
            ->setFit(ImageLayer::FIT_CONTAIN)
            ->setClip(ImageLayer::CLIP_CIRCLE)
            ->setPriority(1);

        $image = (new ImageRenderer())->render(new NullCancellation(), Canvas::make(100, 100, $lower, $circle));

        $this->assertPixelSame([255, 0, 0], $image, 30, 40);
        $this->assertPixelSame([0, 0, 255], $image, 30, 55);
        // 圆内（dist 40 < 50）但 y<37 收缩矩形外：透明露下层
        $this->assertPixelSame([0, 255, 0], $image, 50, 10);
        // 圆外：透明露下层
        $this->assertPixelSame([0, 255, 0], $image, 2, 2);
    }

    public function testClipCircleWithTileFitBlocksOutsideCircleMaskedAway(): void
    {
        // tile：40×40 单元 center 锚定 (30,30)，可见块铺满 [30,100)²——
        // 圆内块区露图、圆内锚点未覆盖区（x<30）与圆外透明露下层
        $src = $this->solidPng(40, 40, '#f00');
        $lower = ImageLayer::make(100, 100, '#0f0')->setPriority(10);
        $circle = ImageLayer::make(100, 100)
            ->setImage($src)
            ->setFit(ImageLayer::FIT_TILE)
            ->setClip(ImageLayer::CLIP_CIRCLE)
            ->setPriority(1);

        $image = (new ImageRenderer())->render(new NullCancellation(), Canvas::make(100, 100, $lower, $circle));

        $this->assertPixelSame([255, 0, 0], $image, 35, 35);
        $this->assertPixelSame([255, 0, 0], $image, 50, 50);
        $this->assertPixelSame([0, 255, 0], $image, 15, 50);
        $this->assertPixelSame([0, 255, 0], $image, 5, 5);
    }

    public function testUnknownClipValueRendersRectangleWithoutMask(): void
    {
        // 未知 clip 值渲染面按矩形降级（wire 透传保存）：整盒铺图无遮罩、四角是图
        $src = $this->bandPng(100, 100, '#f00', '#00f');
        $graph = ImageLayer::make(100, 100)->setImage($src)->graph();
        $graph['spec']['clip'] = 'star';
        $circle = ImageLayer::fromGraph($graph);

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $circle);

        $this->assertPixelSame([255, 0, 0], $image, 2, 2);
        $this->assertPixelSame([0, 0, 255], $image, 97, 97);
        $this->assertPixelSame([255, 0, 0], $image, 50, 25);
    }

    /**
     * 上下两色带 PNG 落盘（遮罩采样源图）
     */
    private function bandPng(int $width, int $height, string $top, string $bottom): string
    {
        $image = ImageManagerFactory::make()->createImage($width, $height);
        $half = intdiv($height, 2);
        $image->drawRectangle(function (RectangleFactory $rect) use ($width, $half, $top) {
            $rect->at(0, 0)->size($width, $half)->background($top);
        });
        $image->drawRectangle(function (RectangleFactory $rect) use ($width, $height, $half, $bottom) {
            $rect->at(0, $half)->size($width, $height - $half)->background($bottom);
        });

        return $this->writePngToTemp($image);
    }

    /**
     * 纯色 PNG 落盘
     */
    private function solidPng(int $width, int $height, string $color): string
    {
        $image = ImageManagerFactory::make()->createImage($width, $height);
        $image->fill($color);

        return $this->writePngToTemp($image);
    }

    /** 把渲染好的位图编码落盘到临时目录（测试源图统一出口） */
    private function writePngToTemp(ImageInterface $image): string
    {
        $path = sys_get_temp_dir() . '/canvas-next-clip-test-' . uniqid() . '.png';
        file_put_contents($path, $image->encodeUsingFileExtension('png')->toString());

        return $path;
    }
}
