<?php

/**
 * 目验脚本：渲染一张真实场景样图（中文断行、表格、二维码、图片、边框、优先级）
 *
 * 优先级语义与旧库一致：priority 越大越先渲染（越垫底）
 *
 * 用法: php scripts/visual-check.php [输出路径]
 */

require __DIR__ . '/../vendor/autoload.php';

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Renderer\Image\ImageRenderer;
use HankChen\CanvasNext\Runtime\NullCancellation;

// 字体候选：优先带 CJK 字形的字体，避免中文变豆腐块
$ttf = null;
foreach ([
    '/System/Library/Fonts/STHeiti Medium.ttc',
    '/System/Library/Fonts/Supplemental/Songti.ttc',
    '/System/Library/Fonts/Hiragino Sans GB.ttc',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/System/Library/Fonts/Supplemental/Arial.ttf',
] as $candidate) {
    if (is_file($candidate)) {
        $ttf = $candidate;
        break;
    }
}
$font = $ttf ?? '';

// 白色底（最垫底），避免透明区域
$base = ImageLayer::make(400, 400, '#ffffff')->setPriority(11);

// 头图色块
$header = ImageLayer::make(400, 90, '#2d6cdf')->setPosition(0, 0)->setPriority(10);

// 标题（画在色块上）
$title = TextLayer::make(400, 90)
    ->setText('php-canvas-next 目验样图')
    ->setFont($font, 24, '#ffffff')
    ->setVerticalAlign('center')
    ->setHorizontalAlign('center')
    ->setPosition(0, 0)
    ->setPriority(5);

// 长中文段落：autowrap + 禁则效果（。不能出现在行首）
$paragraph = TextLayer::make(360, 'auto', '#f5f7fa')
    ->setText("图层树与渲染器分离之后，同一份结构树可以交给不同后端渲染；中文断行内置禁则处理，行首不会出现句号、逗号等收尾标点。英文单词 hello world 优先在词边界断行。")
    ->setFont($font, 14, '#333333')
    ->setPadding(10)
    ->setAutowrap(true)
    ->setPosition(20, 110)
    ->setPriority(4);

// 表格：三行两列（宽 250，右侧留给二维码）
$table = TableLayer::make(250, 0, '#ffffff')
    ->setPosition(20, 250)
    ->setPriority(4)
    ->setBorder(1, '#dddddd');
$specs = [
    ['字段', '说明'],
    ['Canvas', '纯结构容器'],
    ['Renderer', '可插拔渲染后端'],
];
foreach ($specs as $i => [$field, $desc]) {
    $row = TableRowLayer::make(250, 'auto');
    foreach ([
        [$field, 80, $i === 0 ? '#eef3fd' : '#ffffff'],
        [$desc, 170, $i === 0 ? '#eef3fd' : '#ffffff'],
    ] as [$text, $width, $bg]) {
        $cell = TableCellLayer::make($width, 'auto', $bg)
            ->setBorder(1, '#dddddd')
            ->addContentLayer(
                TextLayer::make($width, 'auto')
                    ->setText($text)
                    ->setFont($font, 12, '#222222')
                    ->setPadding(6)
            );
        $row->addCell($cell);
    }
    $table->addRow($row);
}
$tableHeight = array_sum(array_map(fn (TableRowLayer $r) => $r->getHeight(), $table->getRows()));
$table->setHeight($tableHeight);

// 二维码（表格右侧）
$qr = QrCodeLayer::make(90, 90)
    ->setText('https://github.com/hankchen/php-canvas-next')
    ->setPosition(280, 250)
    ->setPriority(4);

// 图片图层：本地生成一张双色 PNG
$tmpPng = sys_get_temp_dir() . '/canvas-next-visual.png';
$image = \HankChen\CanvasNext\Renderer\Image\ImageManagerFactory::make()->createImage(360, 40);
$image->fill('#e8f0e8');
$image->drawRectangle(function (\Intervention\Image\Geometry\Factories\RectangleFactory $rect) {
    $rect->at(10, 10)->size(120, 20)->background('#6dc287');
});
file_put_contents($tmpPng, $image->encodeUsingFileExtension('png')->toString());

$strip = ImageLayer::make(360, 40, '#ffffff')
    ->setImage($tmpPng)
    ->setPosition(20, 340)
    ->setPriority(4);

$footer = TextLayer::make(400, 30)
    ->setText('HankChen/php-canvas-next')
    ->setFont($font, 11, '#888888')
    ->setHorizontalAlign('center')
    ->setVerticalAlign('center')
    ->setPosition(0, 370)
    ->setPriority(4);

$canvas = Canvas::make(400, 400, $base, $header, $title, $paragraph, $table, $qr, $strip, $footer);

$output = $argv[1] ?? (__DIR__ . '/../visual-check.png');
$image = (new ImageRenderer())->render(new NullCancellation(), $canvas);
$image->save($output);

echo "已输出: {$output} (" . $image->width() . 'x' . $image->height() . ")\n";
