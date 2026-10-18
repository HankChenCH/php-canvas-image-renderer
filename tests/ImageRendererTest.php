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
     * 未知 fit 值（白名单在渲染臂，wire 透传保存）与显式 cover（归一缺省态）
     * 都按 cover 原路径渲染
     */
    public function testUnknownFitValueAndExplicitCoverRenderSamePixelsAsCover(): void
    {
        $wide = $this->twoColorPng(40, 10, '#f00', '#00f', 20);

        // 未知值经 wire 透传（往返恒等），渲染按 cover 降级
        $graph = ImageLayer::make(20, 20, '#fff')->setImage($wide)->graph();
        $graph['spec']['fit'] = 'fill';
        $layer = ImageLayer::fromGraph($graph);
        $this->assertSame('fill', $layer->getFit());

        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);
        $this->assertPixelSame([255, 0, 0], $image, 5, 10);
        $this->assertPixelSame([0, 0, 255], $image, 15, 10);

        // 显式 cover 归一为缺省态，走同一 cover 路径
        $explicit = ImageLayer::make(20, 20, '#fff')->setImage($wide)->setFit(ImageLayer::FIT_COVER);
        $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $explicit);
        $this->assertPixelSame([255, 0, 0], $image, 5, 10);
        $this->assertPixelSame([0, 0, 255], $image, 15, 10);
    }

    /**
     * 损坏图 + contain 降级走 cover 路径（ADR 0023）：渲染结果与无 fit 的 cover
     * 逐位一致——fit 臂 @getimagesize 读取失败不告警、不新增异常面。位图端 cover
     * 路径对不可解码文件的结局随驱动而定（GD 抛解码失败；Imagick 可能同样抛，
     * 也可能经 TXT 兜底成图），故断言两次渲染 outcome 等价而非具体结局
     */
    public function testCorruptedImageWithContainFollowsCoverPathExactly(): void
    {
        $src = sys_get_temp_dir() . '/canvas-next-test-broken-' . uniqid() . '.png';
        file_put_contents($src, 'definitely-not-an-image');

        $outcome = function (bool $contain) use ($src): array {
            $layer = ImageLayer::make(100, 50, '#ffffff')->setImage($src);
            if ($contain) {
                $layer->setFit(ImageLayer::FIT_CONTAIN);
            }

            try {
                $image = (new ImageRenderer())->renderLayer(new NullCancellation(), $layer);

                return ['image', $image->width(), $image->height()];
            } catch (\Throwable $e) {
                return ['exception', get_class($e), $e->getMessage()];
            }
        };

        $this->assertSame($outcome(false), $outcome(true), '损坏图 contain 渲染结局必须与 cover 完全一致');

        @unlink($src);
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

    /**
     * 生成左半 color、右半全透明的 PNG 文件（不 fill，保留 createImage 的透明底）
     */
    private function halfTransparentPng(int $width, int $height, string $color): string
    {
        $image = ImageManagerFactory::make()->createImage($width, $height);
        $image->drawRectangle(function (RectangleFactory $rect) use ($width, $height, $color) {
            $rect->at(0, 0)->size(intdiv($width, 2), $height)->background($color);
        });

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
