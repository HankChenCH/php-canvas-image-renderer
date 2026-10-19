<?php

namespace HankChen\CanvasNext\Renderer\Image\Tests;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\CanvasBackground;
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
use HankChen\CanvasNext\Runtime\Cancellation;
use HankChen\CanvasNext\Runtime\NullCancellation;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\Interfaces\ImageInterface;

class ImageRendererTest extends CanvasTestCase
{
    public function testRenderLayerPaintsBackground(): void
    {
        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), ImageLayer::make(10, 10, '#f00'));

        $this->assertSame(10, $image->width());
        $this->assertSame(10, $image->height());
        $this->assertPixelSame([255, 0, 0], $image, 5, 5);
    }

    public function testRenderLayerReturnsRawImageWhenNoContent(): void
    {
        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), ImageLayer::make(8, 8));

        $this->assertSame(8, $image->width());
    }

    public function testImageIsCoverCroppedIntoContentBox(): void
    {
        // 40x10 图片：左半红右半蓝；cover 裁切进 20x20 盒后露出中缝两侧
        $wide = $this->twoColorPng(40, 10, '#f00', '#00f', 20);

        $layer = ImageLayer::make(20, 20, '#fff')->setImage($wide);
        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

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

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

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

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

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

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

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

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $table);

        $this->assertPixelSame([255, 0, 0], $image, 50, 5);
        $this->assertPixelSame([0, 255, 0], $image, 50, 15);
        $this->assertPixelSame([255, 255, 255], $image, 50, 45);
    }

    public function testQrRendersFinderPatternDarkAtCorner(): void
    {
        $layer = QrCodeLayer::make(60, 60)->setText('https://example.com');

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

        $pixel = $this->pixel($image, 0, 0);
        $this->assertLessThan(60, $pixel[0]);
        $this->assertLessThan(60, $pixel[1]);
        $this->assertLessThan(60, $pixel[2]);
    }

    /**
     * quiet zone 语义（三端契约）：QR padding 留白 = 码外静区——码内切于内容盒
     * （side = min(内容区宽高)），left/top 缺省锚定 padding 原点，留白露图层盒背景
     */
    public function testQrPaddingLeavesQuietZone(): void
    {
        $layer = QrCodeLayer::make(100, 100, '#ffffff')
            ->setPadding(20)
            ->setText('https://example.com');

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

        // padding 区 = 静区（白）：旧语义按宽铺放 (0,0) 起，此处是码的 finder 区（暗）
        $this->assertPixelSame([255, 255, 255], $image, 10, 10);
        // 码从 (20,20) 起：finder 图案内部仍暗
        $pixel = $this->pixel($image, 25, 25);
        $this->assertLessThan(128, $pixel[0]);
    }

    /** 非正方形盒：码内切（side = min(100, 60) = 60）且 center/center 在整盒内居中 */
    public function testQrCenterAlignCentersSquareInNonSquareBox(): void
    {
        $layer = QrCodeLayer::make(100, 60, '#ffffff')
            ->setText('https://example.com')
            ->setHorizontalAlign('center')
            ->setVerticalAlign('center');

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

        // 码放 [20,80]×[0,60]：左静区白（旧语义按宽铺满会在此处落在 finder 上）
        $this->assertPixelSame([255, 255, 255], $image, 10, 10);
        $this->assertPixelSame([255, 255, 255], $image, 90, 30);
        $pixel = $this->pixel($image, 25, 5);
        $this->assertLessThan(128, $pixel[0]);
    }

    public function testCanvasRenderCompositesAndSave(): void
    {
        // priority 高者先画在下层：红色大图垫底，蓝色小块后画在上层
        $red = ImageLayer::make(60, 30, '#f00')->setImage($this->solidPng(60, 30, '#f00'))->setPriority(5);
        $blue = ImageLayer::make(30, 30, '#0f0')->setImage($this->solidPng(30, 30, '#0f0'))->setPriority(1);

        $renderer = new ImageRenderer();
        $image = $renderer->render(new NullCancellation(), Canvas::make(60, 30, $red, $blue));

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

        $image = (new ImageRenderer())->render(new NullCancellation(), $canvas);

        $this->assertPixelSame([0, 255, 0], $image, 20, 10);
    }

    /** 跳过发生在绘制分派（含惰性物化）之前：隐藏层的资源引用不触发解析 */
    public function testHiddenRootLayerSkipsMaterialization(): void
    {
        $canvas = Canvas::make(40, 20,
            ImageLayer::make(40, 20, '#0f0'),
            ImageLayer::make(40, 20, '#f00')->setImage('/nonexistent/hidden.png')->setVisible(false),
        );

        $image = (new ImageRenderer())->render(new NullCancellation(), $canvas);

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

            public function download(Cancellation $ctx, string $url): string|false
            {
                $this->calls[] = $url;

                return $this->content;
            }
        };

        $spy = new class($downloader) extends ResourceResolver {
            public array $resolvedLayers = [];

            public function resolve(Cancellation $ctx, Canvas $canvas): void
            {
                throw new \RuntimeException('render() 不应触发预遍历物化');
            }

            public function resolveLayer(Cancellation $ctx, AbstractLayer $layer): void
            {
                $this->resolvedLayers[] = get_class($layer);
                parent::resolveLayer($ctx, $layer);
            }
        };

        $url = 'https://cdn.example.com/lazy-' . uniqid() . '.png';
        $renderer = new ImageRenderer($spy);
        $image = $renderer->render(new NullCancellation(), Canvas::make(20, 20, ImageLayer::make(20, 20, '#fff')->setImage($url)));

        $this->assertSame([ImageLayer::class], $spy->resolvedLayers);
        $this->assertCount(1, $downloader->calls);
        $this->assertPixelSame([255, 0, 0], $image, 10, 10);
    }

    public function testMaterializeFailureThrowsAtDrawTime(): void
    {
        // 失败语义：绘制中抛出、无产物返回（渲染面随异常丢弃）
        $downloader = new class implements DownloaderInterface {
            public function download(Cancellation $ctx, string $url): string|false
            {
                return false;
            }
        };

        $renderer = new ImageRenderer(new ResourceResolver($downloader));
        $layer = ImageLayer::make(10, 10)->setImage('https://cdn.example.com/missing-' . uniqid() . '.png');

        try {
            $renderer->renderLayer(new NullCancellation(), $layer);
            $this->fail('物化失败必须在绘制期抛出');
        } catch (MaterializeException $e) {
            $this->assertSame('resource_download_failed', $e->getErrorCode());
        }
    }

    // ------------------------------------------------------------------
    // 画布背景（ADR 0022 / spec §4）：铺色在图层之下；背景图 cover 铺满、透明区露色；
    // 画布级惰性物化（禁预遍历、失败即抛无产物）
    // ------------------------------------------------------------------

    public function testCanvasBackgroundColorPaintedUnderLayers(): void
    {
        // 背景色垫底：图层区盖在背景上，图层外露背景色（先色后进根层循环）
        $white = $this->solidPng(20, 20, '#ffffff');
        $canvas = Canvas::make(40, 20, ImageLayer::make(20, 20)->setImage($white)->setPosition(20, 0))
            ->setBackground(new CanvasBackground('#ff0000'));

        $image = (new ImageRenderer())->render(new NullCancellation(), $canvas);

        $this->assertPixelSame([255, 0, 0], $image, 5, 10);
        $this->assertPixelSame([255, 0, 0], $image, 19, 19);
        $this->assertPixelSame([255, 255, 255], $image, 30, 10);
    }

    public function testCanvasWithBackgroundOnlyIsFullyTinted(): void
    {
        // 零图层画布：整面背景色（背景之下无物，画布初始透明被填充取代）
        $canvas = Canvas::make(10, 10)->setBackground(new CanvasBackground('#00ff00'));

        $image = (new ImageRenderer())->render(new NullCancellation(), $canvas);

        $this->assertPixelSame([0, 255, 0], $image, 0, 0);
        $this->assertPixelSame([0, 255, 0], $image, 9, 9);
    }

    public function testCanvasBackgroundImageCoversCanvasWithTransparentAreaShowingColor(): void
    {
        // 左半红右半透明的背景图 cover 铺满画布：图上区露图、透明区露背景色（先色后图）
        $canvas = Canvas::make(40, 20)
            ->setBackground(new CanvasBackground('#00ff00', $this->halfTransparentPng(40, 20, '#ff0000')));

        $image = (new ImageRenderer())->render(new NullCancellation(), $canvas);

        $this->assertPixelSame([255, 0, 0], $image, 10, 10);
        $this->assertPixelSame([0, 255, 0], $image, 30, 10);
    }

    public function testCanvasBackgroundImageCoverScalesUpToFillCanvas(): void
    {
        // 适配固定 cover（spec §2 Q2）：20x10 图铺 40x20 画布 = 放大铺满，四角皆是图色
        $canvas = Canvas::make(40, 20)->setBackground(new CanvasBackground('#00ff00', $this->solidPng(20, 10, '#ff0000')));

        $image = (new ImageRenderer())->render(new NullCancellation(), $canvas);

        $this->assertPixelSame([255, 0, 0], $image, 0, 0);
        $this->assertPixelSame([255, 0, 0], $image, 39, 19);
    }

    public function testCanvasBackgroundImageMaterializedLazilyAtRenderTime(): void
    {
        // 画布级惰性物化（ADR 0022）：resolve() 预遍历一旦发生即失败；
        // 背景在根层循环前物化一次（远程引用下载），图层照旧按需
        $downloader = new class($this->pngBytes(8, 8, '#f00')) implements DownloaderInterface {
            public array $calls = [];

            public function __construct(private readonly string $content)
            {
            }

            public function download(Cancellation $ctx, string $url): string|false
            {
                $this->calls[] = $url;

                return $this->content;
            }
        };

        $spy = new class($downloader) extends ResourceResolver {
            public array $resolvedLayers = [];

            public function resolve(Cancellation $ctx, Canvas $canvas): void
            {
                throw new \RuntimeException('render() 不应触发预遍历物化');
            }

            public function resolveLayer(Cancellation $ctx, AbstractLayer $layer): void
            {
                $this->resolvedLayers[] = get_class($layer);
                parent::resolveLayer($ctx, $layer);
            }
        };

        $url = 'https://cdn.example.com/bg-' . uniqid() . '.png';
        // 图层盒无背景色：drawRect 跳过，背景图得以在图层透出
        $canvas = Canvas::make(20, 20, ImageLayer::make(20, 20))
            ->setBackground(new CanvasBackground(null, $url));

        $image = (new ImageRenderer($spy))->render(new NullCancellation(), $canvas);

        $this->assertSame([ImageLayer::class], $spy->resolvedLayers);
        $this->assertCount(1, $downloader->calls);
        $this->assertPixelSame([255, 0, 0], $image, 10, 10);
    }

    public function testCanvasBackgroundImageMaterializeFailureThrowsWithNoProduct(): void
    {
        // 失败语义：绘制期抛出、无产物返回（渲染面随异常丢弃）
        $downloader = new class implements DownloaderInterface {
            public function download(Cancellation $ctx, string $url): string|false
            {
                return false;
            }
        };

        $renderer = new ImageRenderer(new ResourceResolver($downloader));
        $canvas = Canvas::make(10, 10)
            ->setBackground(new CanvasBackground('#ffffff', 'https://cdn.example.com/missing-' . uniqid() . '.png'));

        try {
            $renderer->render(new NullCancellation(), $canvas);
            $this->fail('背景物化失败必须在绘制期抛出');
        } catch (MaterializeException $e) {
            $this->assertSame('resource_download_failed', $e->getErrorCode());
        }
    }

    // ------------------------------------------------------------------
    // 图片层适配模式 contain（ADR 0023 / spec §4-§5）：绘制分派层按源图内在比例
    // 把目标矩形收缩成等比矩形再调原语——空隙透明露下层与盒背景、按 align+padding
    // 放置；cover 缺省（存量断言）/未知值/显式 cover/损坏图降级路径零回归
    // ------------------------------------------------------------------

    /**
     * contain 等比收缩后的空隙透出图层盒背景色：40x10 源图（左红右蓝）进 40x20 盒，
     * 收缩矩形 40x10 居中放 y∈[5,15)，上下空隙露白底（cover 会在此处铺满图色）
     */
    public function testContainLeavesGapShowingLayerBoxBackground(): void
    {
        $wide = $this->twoColorPng(40, 10, '#f00', '#00f', 20);

        $layer = ImageLayer::make(40, 20, '#ffffff')
            ->setImage($wide)
            ->setFit(ImageLayer::FIT_CONTAIN);

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

        $this->assertPixelSame([255, 0, 0], $image, 10, 10);
        $this->assertPixelSame([0, 0, 255], $image, 30, 10);
        // 上下空隙 = 盒背景白；cover 降级时 (20,2) 是红（放大裁切铺满）
        $this->assertPixelSame([255, 255, 255], $image, 20, 2);
        $this->assertPixelSame([255, 255, 255], $image, 20, 18);
        // 收缩矩形边界精确贴 y∈[5,15)：行 5/14 是图、行 4/15 是空隙
        $this->assertPixelSame([255, 0, 0], $image, 10, 5);
        $this->assertPixelSame([255, 0, 0], $image, 10, 14);
        $this->assertPixelSame([255, 255, 255], $image, 10, 4);
        $this->assertPixelSame([255, 255, 255], $image, 10, 15);
    }

    /**
     * 空隙透明露下层：图层盒无背景色（drawRect 跳过），收缩矩形外的空隙
     * 直接透出优先级更高的垫底红层——证明空隙是透明而非填充色
     */
    public function testContainGapTransparencyRevealsLowerLayer(): void
    {
        $red = ImageLayer::make(40, 20, '#f00')->setPriority(5);
        $wide = $this->twoColorPng(40, 10, '#ff00ff', '#0000ff', 20);
        $contain = ImageLayer::make(40, 20)->setImage($wide)->setFit(ImageLayer::FIT_CONTAIN)->setPriority(1);

        $image = (new ImageRenderer())->render(
            new NullCancellation(),
            Canvas::make(40, 20, $red, $contain)
        );

        // 上下空隙透出垫底红层；收缩矩形 y∈[5,15) 内是源图（左品红右蓝）
        $this->assertPixelSame([255, 0, 0], $image, 20, 2);
        $this->assertPixelSame([255, 0, 0], $image, 20, 18);
        $this->assertPixelSame([255, 0, 255], $image, 10, 10);
        $this->assertPixelSame([0, 0, 255], $image, 30, 10);
        // 矩形边界：行 5/14 是图、行 4/15 是垫底红
        $this->assertPixelSame([255, 0, 255], $image, 10, 5);
        $this->assertPixelSame([255, 0, 255], $image, 10, 14);
        $this->assertPixelSame([255, 0, 0], $image, 10, 4);
        $this->assertPixelSame([255, 0, 0], $image, 10, 15);
    }

    /**
     * contain 收缩矩形按 align + padding 放置（非居中组合）：100x60 盒 padding
     * 上10/右30/下20/左20 + left/top，内容盒 50x30，40x10 源图收缩 50x12
     * （scale = min(50/40, 30/10) = 1.25）贴内容盒左上原点 (20,10)
     */
    public function testContainPlacesShrunkRectByAlignAndPadding(): void
    {
        $wide = $this->twoColorPng(40, 10, '#f00', '#00f', 20);

        $layer = ImageLayer::make(100, 60, '#ffffff')
            ->setImage($wide)
            ->setFit(ImageLayer::FIT_CONTAIN)
            ->setPadding(10, 30, 20, 20)
            ->setHorizontalAlign('left')
            ->setVerticalAlign('top');

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

        // 收缩矩形 [20,70)x[10,22)：贴 padding 原点，分界线 x=45（本地 25 = 源 20）
        $this->assertPixelSame([255, 0, 0], $image, 20, 15);
        $this->assertPixelSame([255, 0, 0], $image, 40, 15);
        $this->assertPixelSame([0, 0, 255], $image, 60, 15);
        $this->assertPixelSame([0, 0, 255], $image, 69, 15);
        // 放置精度：首行贴 y=10（padding top），上方露盒背景
        $this->assertPixelSame([255, 0, 0], $image, 40, 10);
        $this->assertPixelSame([255, 255, 255], $image, 40, 9);
        // 左侧 padding 空隙露盒背景
        $this->assertPixelSame([255, 255, 255], $image, 19, 15);
        // 矩形下方至内容盒底（y<40）的空隙露盒背景；cover 会铺满内容盒此处是蓝
        $this->assertPixelSame([255, 255, 255], $image, 40, 22);
        $this->assertPixelSame([255, 255, 255], $image, 40, 39);
    }

    /**
     * 渲染面降级零回归（点位与 testImageIsCoverCroppedIntoContentBox 一致）：
     * 白名单外 fit 值（wire 透传保存）与显式 cover（归一缺省态）都按 cover
     * 原路径渲染。两腿已知拒绝档 + 任意未知串——'scale-down' 是 CSS 有而本
     * 契约明示不收的档位（spec Q1）；'flip' 是从未入册的任意未知串
     * （go-canvas / js-canvas 同款示例，三端统一）
     */
    public function testFitValuesOutsideWhitelistAndExplicitCoverRenderSamePixelsAsCover(): void
    {
        $wide = $this->twoColorPng(40, 10, '#f00', '#00f', 20);

        // 已知拒绝档 'scale-down' 经 wire 透传（往返恒等），渲染按 cover 降级
        $graph = ImageLayer::make(20, 20, '#fff')->setImage($wide)->graph();
        $graph['spec']['fit'] = 'scale-down';
        $layer = ImageLayer::fromGraph($graph);
        $this->assertSame('scale-down', $layer->getFit());

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);
        $this->assertPixelSame([255, 0, 0], $image, 5, 10);
        $this->assertPixelSame([0, 0, 255], $image, 15, 10);

        // 任意未知串 'flip' 同走 cover 降级
        $unknown = ImageLayer::make(20, 20, '#fff')->setImage($wide)->graph();
        $unknown['spec']['fit'] = 'flip';
        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), ImageLayer::fromGraph($unknown));
        $this->assertPixelSame([255, 0, 0], $image, 5, 10);
        $this->assertPixelSame([0, 0, 255], $image, 15, 10);

        // 显式 cover 归一为缺省态，走同一 cover 路径
        $explicit = ImageLayer::make(20, 20, '#fff')->setImage($wide)->setFit(ImageLayer::FIT_COVER);
        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $explicit);
        $this->assertPixelSame([255, 0, 0], $image, 5, 10);
        $this->assertPixelSame([0, 0, 255], $image, 15, 10);
    }

    /**
     * 损坏图渲染结局跨全 fit 等价（ADR 0024）：内在尺寸读取失败在 fit 分派之前
     * 统一退化「整图拉伸进目标矩形」（fill 形态）——四档 fit 走同一条退化臂，
     * 位图端对不可解码文件的结局随驱动而定（GD 抛解码失败；Imagick 可能同样抛，
     * 也可能经 TXT 兜底成图），故断言各 fit 渲染 outcome 互相一致而非具体结局
     */
    public function testCorruptedImageOutcomeEquivalentAcrossAllFits(): void
    {
        $src = sys_get_temp_dir() . '/canvas-next-test-broken-' . uniqid() . '.png';
        file_put_contents($src, 'definitely-not-an-image');

        $outcome = function (?string $fit) use ($src): array {
            $layer = ImageLayer::make(100, 50, '#ffffff')->setImage($src);
            if ($fit !== null) {
                $layer->setFit($fit);
            }

            try {
                $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

                return ['image', $image->width(), $image->height()];
            } catch (\Throwable $e) {
                return ['exception', get_class($e), $e->getMessage()];
            }
        };

        $baseline = $outcome(null);
        foreach ([ImageLayer::FIT_COVER, ImageLayer::FIT_CONTAIN, ImageLayer::FIT_FILL, ImageLayer::FIT_TILE] as $fit) {
            $this->assertSame($baseline, $outcome($fit), "损坏图 fit={$fit} 渲染结局必须与缺省完全一致");
        }

        @unlink($src);
    }

    // ------------------------------------------------------------------
    // 双矩形 blit 原语（ADR 0024）：后端零 fit 语义——decode → crop 源矩形 →
    // 非等比拉伸 resize 至目标矩形 → insert。fill 的拉伸、tile 网格的逐块
    // blit、cover 等比搬运在位图端的像素真相（无黄金文件，点位断言）
    // ------------------------------------------------------------------

    /**
     * fill 整图拉伸进目标矩形：非等比源图比例失真可见、无 cover 裁切、无空隙——
     * 40x10 三竖条源图（红[0,8)/绿[8,24)/蓝[24,40)）进 20x20 盒，1:2 横向压缩后
     * 分界线落 dst x=4/12；cover 居中裁窗为源 x∈[15,25)，红条根本不可见
     */
    public function testFillStretchesWholeImageIntoBoxWithoutCropOrGap(): void
    {
        $layer = ImageLayer::make(20, 20, '#ffffff')
            ->setImage($this->threeBandPng())
            ->setFit(ImageLayer::FIT_FILL);

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

        // 三条全部可见（cover 会裁掉红条），分界位置与 1:2 压缩一致
        $this->assertPixelSame([255, 0, 0], $image, 1, 10);
        $this->assertPixelSame([0, 255, 0], $image, 7, 10);
        $this->assertPixelSame([0, 0, 255], $image, 15, 10);
        // 垂直方向拉伸铺满整盒：上下边界行同色（无空隙）
        $this->assertPixelSame([255, 0, 0], $image, 1, 0);
        $this->assertPixelSame([255, 0, 0], $image, 1, 19);
        $this->assertPixelSame([0, 0, 255], $image, 15, 0);
        $this->assertPixelSame([0, 0, 255], $image, 15, 19);
    }

    /**
     * tile 网格（非居中 align + 非对称 padding）：60x60 盒 padding 右10/下10，
     * left/top 锚定首块于内容盒原点 (0,0)；20x20 四象限源图 1:1 重复 3x3 块，
     * 末行末列被内容盒右/下边界截成半块（可见子矩形只含源图左/上半），
     * 内容盒外（x/y≥50）露盒背景——网格不越出内容盒
     */
    public function testTileGridAnchorsByAlignWithGapAndTruncation(): void
    {
        $layer = ImageLayer::make(60, 60, '#ffffff')
            ->setImage($this->quadrantPng())
            ->setFit(ImageLayer::FIT_TILE)
            ->setPadding(0, 10, 10, 0)
            ->setHorizontalAlign('left')
            ->setVerticalAlign('top');

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

        // 首块四象限 1:1 原样（左上红/右上绿/左下蓝/右下黄）
        $this->assertPixelSame([255, 0, 0], $image, 5, 5);
        $this->assertPixelSame([0, 255, 0], $image, 15, 5);
        $this->assertPixelSame([0, 0, 255], $image, 5, 15);
        $this->assertPixelSame([255, 255, 0], $image, 15, 15);
        // 第二块整块重复：同象限颜色在 +20 偏移处重现
        $this->assertPixelSame([255, 0, 0], $image, 25, 25);
        $this->assertPixelSame([255, 255, 0], $image, 35, 35);
        // 末列截断：dst x∈[40,50) 只对应源 x∈[0,10)（左半列）——右象限色不得出现
        $this->assertPixelSame([255, 0, 0], $image, 45, 5);
        $this->assertPixelSame([0, 0, 255], $image, 45, 15);
        // 末行截断：dst y∈[40,50) 只对应源 y∈[0,10)（上半行）——下象限色不得出现
        $this->assertPixelSame([255, 0, 0], $image, 5, 45);
        $this->assertPixelSame([0, 255, 0], $image, 15, 45);
        // 角块双向截断：只含源图左上象限
        $this->assertPixelSame([255, 0, 0], $image, 45, 45);
        // 内容盒外露盒背景：截断语义（网格∩内容盒）下不得有图色越界
        $this->assertPixelSame([255, 255, 255], $image, 55, 5);
        $this->assertPixelSame([255, 255, 255], $image, 5, 55);
        $this->assertPixelSame([255, 255, 255], $image, 55, 55);
    }

    /**
     * tile 小图 center：25x25 盒 10x10 源图，网格锚点 ((25-10)/2, ·) = (7,7)——
     * 锚点前的左/上空隙透出垫底绿层（证明空隙透明而非填充）；网格 2x2 块，
     * 末行末列截成 8px 半块；分界线 x/y=7/17 精确
     */
    public function testTileSmallImageCentersGridWithTransparentGapBeforeAnchor(): void
    {
        $red = ImageLayer::make(25, 25, '#0f0')->setPriority(5);
        $tile = ImageLayer::make(25, 25)
            ->setImage($this->solidPng(10, 10, '#f00'))
            ->setFit(ImageLayer::FIT_TILE)
            ->setPriority(1);

        $image = (new ImageRenderer())->render(new NullCancellation(), Canvas::make(25, 25, $red, $tile));

        // 锚点前空隙透出垫底绿层（首块锚定于 (7,7)，锚前无网格）
        $this->assertPixelSame([0, 255, 0], $image, 2, 2);
        $this->assertPixelSame([0, 255, 0], $image, 10, 2);
        $this->assertPixelSame([0, 255, 0], $image, 2, 10);
        // 网格分界精确贴 x/y=7：行 6 是空隙、行 7 起是图
        $this->assertPixelSame([0, 255, 0], $image, 10, 6);
        $this->assertPixelSame([255, 0, 0], $image, 10, 7);
        $this->assertPixelSame([0, 255, 0], $image, 6, 10);
        $this->assertPixelSame([255, 0, 0], $image, 7, 10);
        // 2x2 四块重复：末列/末行截成 8px（x/y∈[17,25)）仍全是图色
        $this->assertPixelSame([255, 0, 0], $image, 12, 12);
        $this->assertPixelSame([255, 0, 0], $image, 22, 12);
        $this->assertPixelSame([255, 0, 0], $image, 12, 22);
        $this->assertPixelSame([255, 0, 0], $image, 24, 24);
    }

    /**
     * tile 大图 center：负锚点露图片中部（语义与 cover 同门）——40x10 双色图进
     * 20x20 盒，锚点 (intval(-10), intval(5)) = (-10,5)：可见源窗为 x∈[10,30)
     * （中部 20px，红蓝各占一半）；纵向 10px 单元在 y=5/15 两行，y∈[0,5) 空隙
     * 透出垫底绿层、y∈[15,20) 为截断半行（源上半）
     */
    public function testTileLargeImageNegativeAnchorExposesImageCenter(): void
    {
        $red = ImageLayer::make(20, 20, '#0f0')->setPriority(5);
        $tile = ImageLayer::make(20, 20)
            ->setImage($this->twoColorPng(40, 10, '#f00', '#00f', 20))
            ->setFit(ImageLayer::FIT_TILE)
            ->setPriority(1);

        $image = (new ImageRenderer())->render(new NullCancellation(), Canvas::make(20, 20, $red, $tile));

        // 负锚点：可见源窗 x∈[10,30)（cover 窗为 [15,25)，两者都露中部但窗宽不同）
        $this->assertPixelSame([255, 0, 0], $image, 5, 10);
        $this->assertPixelSame([0, 0, 255], $image, 15, 10);
        // y∈[0,5) 锚点前空隙透明；y∈[15,20) 截断半行（源 y∈[0,5)，色同首行）
        $this->assertPixelSame([0, 255, 0], $image, 10, 2);
        $this->assertPixelSame([255, 0, 0], $image, 5, 17);
        $this->assertPixelSame([0, 0, 255], $image, 15, 17);
    }

    /**
     * 目标尺寸 ≤0 防护只画盒（既有纪律，blit 后端不画不抛）：
     * padding 吃掉全部盒尺寸后内容区为负，渲染结局 = 纯盒背景
     */
    public function testNonPositiveTargetSizeDrawsBoxOnly(): void
    {
        $layer = ImageLayer::make(20, 20, '#ffffff')
            ->setImage($this->solidPng(8, 8, '#f00'))
            ->setPadding(15);

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

        $this->assertPixelSame([255, 255, 255], $image, 10, 10);
        $this->assertPixelSame([255, 255, 255], $image, 19, 19);
    }

    public function testTemplateTablePipelineRendersExpandedRows(): void
    {
        // V2 全链路：graph 序列化 → 解码 → 展开 → 位图渲染
        $downloader = new class($this->pngBytes(10, 10, '#f00')) implements DownloaderInterface {
            public function __construct(private readonly string $content)
            {
            }

            public function download(Cancellation $ctx, string $url): string|false
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

        $image = (new ImageRenderer(new ResourceResolver($downloader)))->render(new NullCancellation(), $hydrated);

        // 两行实例：行区图片铺满格（绿底被红图覆盖），表壳剩余区按声明白底
        $this->assertPixelSame([255, 0, 0], $image, 50, 5);
        $this->assertPixelSame([255, 0, 0], $image, 50, 15);
        $this->assertPixelSame([255, 255, 255], $image, 50, 35);
    }

    /**
     * EXIF 旋转图整图 blit 保持归正（观察方向）：orientation=6 顺时针 90°，
     * 8x16 上红下蓝观察为 16x8 左蓝右红（原下缘转到左缘）——fill 整图路径，
     * JPEG 有损容差取样（镜像 go-canvas TestDrawImageExifRotatedWholeImageUpright）
     */
    public function testExifRotatedWholeImageStaysUpright(): void
    {
        $layer = ImageLayer::make(16, 8, '#ffffff')
            ->setImage($this->twoBandExifJpeg(6))
            ->setFit(ImageLayer::FIT_FILL);

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

        $this->assertPixelNear([0, 0, 255], $image, 4, 4);
        $this->assertPixelNear([255, 0, 0], $image, 12, 4);
    }

    /**
     * EXIF 旋转图窗口 blit 取内在（未归正）坐标系、归正后置于裁窗（ADR 0024
     * 契约显式化，07 票裁决，镜像 go-canvas TestDrawImageExifRotatedCropsWindowIn-
     * IntrinsicCoordinates）：cover 16x8 盒对 8x16 源的居中裁窗 (0,6,8,4) 在内在
     * 坐标横跨红蓝分界（行 6..9）——转正后观察方向左蓝右红；归正先于裁窗的
     * 旧序会按归正坐标系错窗、越界黑补（GD 实测图外 (0,0,0,255) 不抛错）
     */
    public function testExifRotatedCoverWindowTakenInIntrinsicCoordinates(): void
    {
        $layer = ImageLayer::make(16, 8, '#ffffff')
            ->setImage($this->twoBandExifJpeg(6));

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

        $this->assertPixelNear([0, 0, 255], $image, 4, 4);
        $this->assertPixelNear([255, 0, 0], $image, 12, 4);
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

        return $this->writePngToTemp($image);
    }

    /**
     * 生成 40x10 三竖条 PNG：红 x∈[0,8)、绿 x∈[8,24)、蓝 x∈[24,40)
     * （进 20x20 盒拉伸后分界线落 dst x=4/12，cover 居中裁窗看不到红条）
     */
    private function threeBandPng(): string
    {
        $image = ImageManagerFactory::make()->createImage(40, 10);
        $image->fill('#f00');
        $image->drawRectangle(function (RectangleFactory $rect) {
            $rect->at(8, 0)->size(16, 10)->background('#0f0');
        });
        $image->drawRectangle(function (RectangleFactory $rect) {
            $rect->at(24, 0)->size(16, 10)->background('#00f');
        });

        return $this->writePngToTemp($image);
    }

    /**
     * 生成 20x20 四象限 PNG：左上红/右上绿/左下蓝/右下黄（tile 网格方向性断言用）
     */
    private function quadrantPng(): string
    {
        $image = ImageManagerFactory::make()->createImage(20, 20);
        $image->fill('#f00');
        $image->drawRectangle(function (RectangleFactory $rect) {
            $rect->at(10, 0)->size(10, 10)->background('#0f0');
        });
        $image->drawRectangle(function (RectangleFactory $rect) {
            $rect->at(0, 10)->size(10, 10)->background('#00f');
        });
        $image->drawRectangle(function (RectangleFactory $rect) {
            $rect->at(10, 10)->size(10, 10)->background('#ff0');
        });

        return $this->writePngToTemp($image);
    }

    private function solidPng(int $width, int $height, string $color): string
    {
        return $this->writePngToTemp(
            ImageManagerFactory::make()->createImage($width, $height)->fill($color)
        );
    }

    /**
     * 生成 8x16 上红下蓝、携带指定 EXIF orientation 的 JPEG（EXIF 次序测试共用
     * 夹具，镜像 go-canvas image-renderer 同名助手）：GD 编码后在 SOI 后注入
     * 最小 APP1 Exif 段（II 字节序 TIFF，IFD0 仅 Orientation 一项 SHORT）
     */
    private function twoBandExifJpeg(int $orientation): string
    {
        $image = imagecreatetruecolor(8, 16);
        $red = imagecolorallocate($image, 255, 0, 0);
        $blue = imagecolorallocate($image, 0, 0, 255);
        imagefilledrectangle($image, 0, 0, 7, 7, $red);
        imagefilledrectangle($image, 0, 8, 7, 15, $blue);
        $path = sys_get_temp_dir() . '/canvas-next-test-' . uniqid() . '.jpg';
        imagejpeg($image, $path, 100);
        imagedestroy($image);

        $ifd = pack('v', 1)
            . pack('v', 0x0112) . pack('v', 3) . pack('V', 1)
            . pack('v', $orientation) . pack('v', 0)
            . pack('V', 0);
        $payload = "Exif\x00\x00" . 'II' . pack('v', 42) . pack('V', 8) . $ifd;
        $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        $bytes = file_get_contents($path);
        file_put_contents($path, substr($bytes, 0, 2) . $app1 . substr($bytes, 2));

        return $path;
    }

    /**
     * 生成左半 color、右半全透明的 PNG 文件（不 fill，保留 createImage 的透明底）
     */
    private function halfTransparentPng(int $width, int $height, string $color): string
    {
        $image = ImageManagerFactory::make()->createImage($width, $height);
        $image->drawRectangle(function (RectangleFactory $rect) use ($width, $height, $color) {
            $rect->at(0, 0)->size(intdiv($width, 2), $height)->background($color);
        });

        return $this->writePngToTemp($image);
    }

    /** 把渲染好的位图编码落盘到临时目录（测试源图统一出口） */
    private function writePngToTemp(ImageInterface $image): string
    {
        $path = sys_get_temp_dir() . '/canvas-next-test-' . uniqid() . '.png';
        file_put_contents($path, $image->encodeUsingFileExtension('png')->toString());

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
