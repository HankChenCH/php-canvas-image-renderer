# AGENTS.md

## Purpose

`hankchen/php-canvas-image-renderer`：核心包 `hankchen/php-canvas-next` 的位图渲染后端（intervention/image v4，GD/Imagick），返回 `ImageInterface`。命名空间根 `HankChen\CanvasNext\Renderer\Image\`（PSR-4 → `src/`），与拆分前保持一致，用户代码零改动。中文注释、conventional commits + 中文 subject、TDD，均与核心包约定一致。

## Layout

- `src/ImageRenderer.php` — 渲染后端：继承核心包 `AbstractRenderer` 模板，只实现 `begin/end/drawRect/drawImage/drawText` 五个绘制原语。
- `src/ImageManagerFactory.php` — 共享 `ImageManager` 单例（Imagick 优先，退回 GD）。
- `tests/Support/CanvasTestCase.php` — 像素取样（`pixel`/`assertPixelSame`）、PNG 生成（`pngBytes`）、`systemTtf()`/`usesImagickDriver()`、缓存目录清理；核心包的同名基类只有目录清理（结构侧无像素测试）。

## Commands

```sh
export PATH="$(brew --prefix)/opt/php@8.3/bin:$PATH"   # Homebrew PHP 是 keg-only
composer install
composer test
php scripts/visual-check.php out.png   # 目验样图（中文禁则/表格/二维码/优先级）
```

## Rules and gotchas

- **依赖核心包**：`"hankchen/php-canvas-next": "^1.0"`，经 composer path repository 指向同级 `../php-canvas-next`（本地 symlink）。包发布 packagist 后可移除该 repositories 段。
- **核心包保持零渲染依赖**：本包之外不得把 intervention/image 引回核心包；图层/Canvas/Resolver 的改动在核心包仓库做。
- **阿里云 composer 镜像元数据滞后**（不认识 intervention/image 4.x 稳定版）：本机安装依赖用仓库内 `composer.lock` 直接 `composer install`；必须重新解析时临时 `composer config repo.packagist.org composer https://repo.packagist.org` 再 update，随后 unset 并用脚本重算 lock 的 content-hash。CI 从 packagist 解析，无此问题。
- **CI**：workflow 会额外 checkout 核心包到工作区子目录，并把 path repository 重写指向它（path repo 的 `../` 相对路径在 CI 工作区里不存在）。
- **v4 API / Imagick 无内置字体 / PCRE \X bug** 等注意项与核心包 AGENTS.md 相同，改绘制原语前先读那边。
- 像素测试无黄金文件：用 `assertPixelSame` 断言点位，改绘制逻辑必须保绿或显式更新断言。
