# Changelog

本项目遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

## 1.2.0 - 2026-10-18

- 图片层适配模式 contain 测试跟随（ADR 0023）：绘制原语与 `src/` 零改动——收缩矩形由核心包 `paintImage` 分派算得，后端只承接 `drawImage` 调用
- 像素测试补 contain 点位断言（无黄金文件约定，GD/Imagick 双驱动）：空隙透明露盒背景与下层、收缩矩形按 align+padding 放置（left/top 非居中组合）、未知 fit 值与显式 cover 降级点位与既有 cover 断言一致
- 损坏图 + contain 断言渲染结局与 cover 逐位一致（`getimagesize` 读取失败降级 cover 路径，fit 臂零新增异常面）

## 1.1.0 - 2026-10-18

- 画布背景特性跟随（ADR 0022）：绘制原语零改动——背景铺装在核心包 `AbstractRenderer` 完成（先色后图、cover 铺满画布矩形），后端复用 `drawRect` / `drawImage` 原语
- 像素测试补画布背景点位断言（无黄金文件约定）：背景色垫于图层之下、零图层整面铺色、背景图 cover 放大铺满、透明区露背景色、画布级惰性物化（`resolve()` 禁预遍历断言保持）、物化失败即抛无产物
- 目验样图换装：人造白底层改由画布级 `background` 声明承担，渲染产物与存量逐字节一致

## 1.0.0 - 2026-10-07

首个稳定版。

- `hankchen/php-canvas-next` 的位图渲染后端：结构树 → intervention/image v4 `ImageInterface`（GD / Imagick 驱动）
- 绘制原语 `drawRect` / `drawImage` / `drawText`，覆盖文本、图片、表格、二维码等图层类型
- QR 内切正方形渲染语义：padding 留白即静区、align 参与放置（ADR 0015）
- 目验样图脚本 `php scripts/visual-check.php out.png`
