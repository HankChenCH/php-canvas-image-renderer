# Changelog

本项目遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

## 1.0.0 - 2026-10-07

首个稳定版。

- `hankchen/php-canvas-next` 的位图渲染后端：结构树 → intervention/image v4 `ImageInterface`（GD / Imagick 驱动）
- 绘制原语 `drawRect` / `drawImage` / `drawText`，覆盖文本、图片、表格、二维码等图层类型
- QR 内切正方形渲染语义：padding 留白即静区、align 参与放置（ADR 0015）
- 目验样图脚本 `php scripts/visual-check.php out.png`
