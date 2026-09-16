# php-canvas-image-renderer

[`hankchen/php-canvas-next`](https://github.com/hankchen/php-canvas-next) 的位图渲染后端：
把结构树渲染为 intervention/image v4 的 `ImageInterface`（GD / Imagick 驱动）。

核心包（结构 + 渲染契约 + 资源物化）见 [php-canvas-next](https://github.com/hankchen/php-canvas-next) 的 README 与设计文档。

## 安装

```sh
composer require hankchen/php-canvas-next hankchen/php-canvas-image-renderer
```

## 使用

命名空间与拆分前一致，代码无需改动：

```php
use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Renderer\Image\ImageRenderer;

$canvas = Canvas::make(400, 300,
    ImageLayer::make(400, 300, '#ffffff')->setPriority(10)
);

$image = (new ImageRenderer())->render($canvas);   // Intervention\Image\Interfaces\ImageInterface
$image->save('/tmp/out.png');
```

- 单图层渲染：`(new ImageRenderer())->renderLayer($layer)`
- 自定义资源下载器：`new ImageRenderer(new \HankChen\CanvasNext\ResourceManagers\ResourceResolver($downloader))`

## 环境要求

- PHP `^8.3`、`intervention/image` `^4.0`（GD 或 Imagick 驱动）
- 文字渲染建议提供真实 TTF/TTC 字体；Imagick 驱动无内置字体，必须提供

## 开发

```sh
composer install
composer test
php scripts/visual-check.php out.png   # 渲染目验样图
```

本地开发通过 composer path repository 引用同级目录的核心包 `../php-canvas-next`。
