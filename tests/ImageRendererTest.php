<?php

namespace HankChen\CanvasNext\Renderer\Image\Tests;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Renderer\Image\ImageManagerFactory;
use HankChen\CanvasNext\Renderer\Image\ImageRenderer;
use HankChen\CanvasNext\Renderer\Image\Tests\Support\CanvasTestCase;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\Interfaces\ImageInterface;

class ImageRendererTest extends CanvasTestCase
{
    public function testRenderLayerPaintsBackground(): void
    {
        $image = (new ImageRenderer())->renderLayer(ImageLayer::make(10, 10, '#f00'));

        $this->assertSame(10, $image->width());
        $this->assertSame(10, $image->height());
        $this->assertPixelSame([255, 0, 0], $image, 5, 5);
    }

    public function testRenderLayerReturnsRawImageWhenNoContent(): void
    {
        $image = (new ImageRenderer())->renderLayer(ImageLayer::make(8, 8));

        $this->assertSame(8, $image->width());
    }

    public function testImageIsCoverCroppedIntoContentBox(): void
    {
        // 40x10 图片：左半红右半蓝；cover 裁切进 20x20 盒后露出中缝两侧
        $wide = $this->twoColorPng(40, 10, '#f00', '#00f', 20);

        $layer = ImageLayer::make(20, 20, '#fff')->setImage($wide);
        $image = (new ImageRenderer())->renderLayer($layer);

        $this->assertPixelSame([255, 0, 0], $image, 5, 10);
        $this->assertPixelSame([0, 0, 255], $image, 15, 10);
    }

    public function testAlignOffsetsImageByPadding(): void
    {
        $src = $this->solidPng(8, 8, '#f00');

        $layer = ImageLayer::make(100, 100, '#fff')
            ->setImage($src)
            ->setPadding(0, 0, 0, 10)
            ->setHorizontalAlign('left')
            ->setVerticalAlign('top');

        $image = (new ImageRenderer())->renderLayer($layer);

        $this->assertPixelSame([255, 0, 0], $image, 10, 4);
        $this->assertPixelSame([255, 255, 255], $image, 9, 4);
    }

    public function testTextRenderedDarkPixelsWithSystemFont(): void
    {
        $ttf = $this->systemTtf();
        if ($ttf === null && $this->usesImagickDriver()) {
            $this->markTestSkipped('Imagick 驱动渲染文字必须提供真实字体文件');
        }

        $layer = TextLayer::make(100, 30, '#fff')->setText('ABC测试')
            ->setFont($ttf ?? '1', 12, '#000');

        $image = (new ImageRenderer())->renderLayer($layer);

        $dark = $this->countDarkPixels($image);
        $this->assertGreaterThan(0, $dark);
    }

    public function testNumericFontIdFallsBackToDefaultFont(): void
    {
        if ($this->usesImagickDriver()) {
            $this->markTestSkipped('Imagick 驱动没有内置默认字体，仅 GD 可跑');
        }

        // 旧库遗留的纯数字 GD 字体编号：按"无字体文件"处理，走 v4 内置默认字体
        $layer = TextLayer::make(100, 30, '#fff')->setText('fallback')
            ->setFont('1', 12, '#000');

        $image = (new ImageRenderer())->renderLayer($layer);

        $this->assertGreaterThan(0, $this->countDarkPixels($image));
    }

    public function testTableStacksRowsVertically(): void
    {
        $table = TableLayer::make(100, 50, '#fff');
        $row1 = TableRowLayer::make(100, 'auto');
        $row1->addCell(TableCellLayer::make(100, 10, '#f00'));
        $row2 = TableRowLayer::make(100, 'auto');
        $row2->addCell(TableCellLayer::make(100, 20, '#0f0'));
        $table->addRow($row1)->addRow($row2);

        $image = (new ImageRenderer())->renderLayer($table);

        $this->assertPixelSame([255, 0, 0], $image, 50, 5);
        $this->assertPixelSame([0, 255, 0], $image, 50, 15);
        $this->assertPixelSame([255, 255, 255], $image, 50, 45);
    }

    public function testQrRendersFinderPatternDarkAtCorner(): void
    {
        $layer = QrCodeLayer::make(60, 60)->setText('https://example.com');

        $image = (new ImageRenderer())->renderLayer($layer);

        $pixel = $this->pixel($image, 0, 0);
        $this->assertLessThan(60, $pixel[0]);
        $this->assertLessThan(60, $pixel[1]);
        $this->assertLessThan(60, $pixel[2]);
    }

    public function testCanvasRenderCompositesAndSave(): void
    {
        // priority 高者先画在下层：红色大图垫底，蓝色小块后画在上层
        $red = ImageLayer::make(60, 30, '#f00')->setImage($this->solidPng(60, 30, '#f00'))->setPriority(5);
        $blue = ImageLayer::make(30, 30, '#0f0')->setImage($this->solidPng(30, 30, '#0f0'))->setPriority(1);

        $renderer = new ImageRenderer();
        $image = $renderer->render(Canvas::make(60, 30, $red, $blue));

        $this->assertPixelSame([0, 255, 0], $image, 10, 10);
        $this->assertPixelSame([255, 0, 0], $image, 50, 25);

        $path = sys_get_temp_dir() . '/canvas-next-test-save.png';
        $image->save($path);

        $this->assertFileExists($path);
        [$width, $height, $type] = getimagesize($path);
        $this->assertSame(60, $width);
        $this->assertSame(30, $height);
        $this->assertSame(IMAGETYPE_PNG, $type);
        @unlink($path);
    }

    public function testManagerIsSharedAndUsesAvailableDriver(): void
    {
        $manager = ImageManagerFactory::make();

        $this->assertSame($manager, ImageManagerFactory::make());
        $this->assertSame(
            extension_loaded('imagick'),
            $manager->driver instanceof \Intervention\Image\Drivers\Imagick\Driver
        );
    }

    /**
     * 生成左半 oneColor、右半 otherColor 的 PNG 文件
     */
    private function twoColorPng(int $width, int $height, string $oneColor, string $otherColor, int $splitX): string
    {
        $image = ImageManagerFactory::make()->createImage($width, $height);
        $image->fill($oneColor);
        $image->drawRectangle(function (RectangleFactory $rect) use ($splitX, $otherColor, $width, $height) {
            $rect->at($splitX, 0)->size($width - $splitX, $height)->background($otherColor);
        });

        $path = sys_get_temp_dir() . '/canvas-next-test-' . uniqid() . '.png';
        file_put_contents($path, $image->encodeUsingFileExtension('png')->toString());

        return $path;
    }

    private function solidPng(int $width, int $height, string $color): string
    {
        $path = sys_get_temp_dir() . '/canvas-next-test-' . uniqid() . '.png';
        file_put_contents($path, $this->pngBytes($width, $height, $color));

        return $path;
    }

    private function countDarkPixels(ImageInterface $image): int
    {
        $dark = 0;
        for ($y = 0; $y < $image->height(); $y++) {
            for ($x = 0; $x < $image->width(); $x++) {
                [$r] = $this->pixel($image, $x, $y);
                if ($r < 128) {
                    $dark++;
                }
            }
        }

        return $dark;
    }
}
