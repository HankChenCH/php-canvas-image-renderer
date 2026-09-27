<?php

namespace HankChen\CanvasNext\Renderer\Image\Tests;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Contracts\DownloaderInterface;
use HankChen\CanvasNext\Exception\MaterializeException;
use HankChen\CanvasNext\Hydrate\CanvasHydrator;
use HankChen\CanvasNext\Layer\AbstractLayer;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TableRowTemplate;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Renderer\Image\ImageManagerFactory;
use HankChen\CanvasNext\Renderer\Image\ImageRenderer;
use HankChen\CanvasNext\Renderer\Image\Tests\Support\CanvasTestCase;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
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

    /**
     * visible=false 的根图层渲染跳过（契约层，layer-panel-ux 工单 01）：
     * 隐藏层在渲染序末位（视觉最上层），若未被跳过会盖住可见层
     */
    public function testHiddenRootLayerExcludedFromBitmap(): void
    {
        $canvas = Canvas::make(40, 20,
            ImageLayer::make(40, 20, '#0f0'),
            ImageLayer::make(40, 20, '#f00')->setVisible(false),
        );

        $image = (new ImageRenderer())->render($canvas);

        $this->assertPixelSame([0, 255, 0], $image, 20, 10);
    }

    /** 跳过发生在绘制分派（含惰性物化）之前：隐藏层的资源引用不触发解析 */
    public function testHiddenRootLayerSkipsMaterialization(): void
    {
        $canvas = Canvas::make(40, 20,
            ImageLayer::make(40, 20, '#0f0'),
            ImageLayer::make(40, 20, '#f00')->setImage('/nonexistent/hidden.png')->setVisible(false),
        );

        $image = (new ImageRenderer())->render($canvas);

        $this->assertPixelSame([0, 255, 0], $image, 20, 10);
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

    public function testMaterializationIsLazyAtDrawTime(): void
    {
        // 渲染期物化（ADR 0005）：resolve() 预遍历一旦发生即测试失败；
        // 物化在绘制分派前按图层惰性触发
        $downloader = new class($this->pngBytes(8, 8, '#f00')) implements DownloaderInterface {
            public array $calls = [];

            public function __construct(private readonly string $content)
            {
            }

            public function download($url)
            {
                $this->calls[] = $url;

                return $this->content;
            }
        };

        $spy = new class($downloader) extends ResourceResolver {
            public array $resolvedLayers = [];

            public function resolve(Canvas $canvas): void
            {
                throw new \RuntimeException('render() 不应触发预遍历物化');
            }

            public function resolveLayer(AbstractLayer $layer): void
            {
                $this->resolvedLayers[] = get_class($layer);
                parent::resolveLayer($layer);
            }
        };

        $url = 'https://cdn.example.com/lazy-' . uniqid() . '.png';
        $renderer = new ImageRenderer($spy);
        $image = $renderer->render(Canvas::make(20, 20, ImageLayer::make(20, 20, '#fff')->setImage($url)));

        $this->assertSame([ImageLayer::class], $spy->resolvedLayers);
        $this->assertCount(1, $downloader->calls);
        $this->assertPixelSame([255, 0, 0], $image, 10, 10);
    }

    public function testMaterializeFailureThrowsAtDrawTime(): void
    {
        // 失败语义：绘制中抛出、无产物返回（渲染面随异常丢弃）
        $downloader = new class implements DownloaderInterface {
            public function download($url)
            {
                return false;
            }
        };

        $renderer = new ImageRenderer(new ResourceResolver($downloader));
        $layer = ImageLayer::make(10, 10)->setImage('https://cdn.example.com/missing-' . uniqid() . '.png');

        try {
            $renderer->renderLayer($layer);
            $this->fail('物化失败必须在绘制期抛出');
        } catch (MaterializeException $e) {
            $this->assertSame('resource_download_failed', $e->getErrorCode());
        }
    }

    public function testTemplateTablePipelineRendersExpandedRows(): void
    {
        // V2 全链路：graph 序列化 → 解码 → 展开 → 位图渲染
        $downloader = new class($this->pngBytes(10, 10, '#f00')) implements DownloaderInterface {
            public function __construct(private readonly string $content)
            {
            }

            public function download($url)
            {
                return $this->content;
            }
        };

        $templateRow = (new TableRowTemplate())->setHeight(10);
        $cell = (new TableCellLayer())->setWidth(100)->setHeight(10, '#0f0');
        $cell->addTemplateContentLayer(ImageLayer::make(100, 10)->setExpression('{{row.img}}'));
        $templateRow->addCell($cell);

        $table = TableLayer::make(100, 40, '#fff');
        $table->setRowsPath('items');
        $table->setTemplate($templateRow);

        $sourceGraph = Canvas::make(100, 40, $table)->graph();
        $canvas = Canvas::fromGraph($sourceGraph);
        $hydrated = (new CanvasHydrator())->hydrate($canvas, [
            'items' => [
                ['img' => 'https://cdn.example.com/tpl-' . uniqid() . '.png'],
                ['img' => 'https://cdn.example.com/tpl-' . uniqid() . '.png'],
            ],
        ]);

        $image = (new ImageRenderer(new ResourceResolver($downloader)))->render($hydrated);

        // 两行实例：行区图片铺满格（绿底被红图覆盖），表壳剩余区按声明白底
        $this->assertPixelSame([255, 0, 0], $image, 50, 5);
        $this->assertPixelSame([255, 0, 0], $image, 50, 15);
        $this->assertPixelSame([255, 255, 255], $image, 50, 35);
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
