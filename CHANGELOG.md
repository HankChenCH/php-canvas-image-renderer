# Changelog

本项目遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

## 2.0.2 - 2026-10-19

- 测试勘误（retro 08 票，src/ 零改动）：降级回归测试更名——原「UnknownFitValue」名不副实，'scale-down' 是已知拒绝档（spec Q1 明示不收）非任意未知串，且任意未知串的渲染降级覆盖缺失；补真未知串 `'flip'` 腿并与 go-canvas / js-canvas 示例统一，测试名更 `testFitValuesOutsideWhitelistAndExplicitCoverRenderSamePixelsAsCover`

## 2.0.1 - 2026-10-19

- EXIF 旋转图坐标系契约显式化（07 票，跟随核心包 v2.0.1 的分派内在尺寸 EXIF 感知）：`drawImage` 移除解码后冗余 `orient()`（intervention v4 `autoOrientation` 双驱动**解码即归正**，显式调用恒空转）——srcRect 与分派层 intrinsicSize 同坐标系（EXIF 方向图为观察方向），整图哨兵取归正位图整幅；历史版本「归正先于裁窗」的表述即因冗余 orient 掩盖了真实次序。像素回归：EXIF orientation 6 两腿（fill 整图观察方向左蓝右红、cover 居中裁窗在内在坐标系取窗后转正），容差取样助手 `assertPixelNear`（镜像 go-canvas 同名）与 `twoBandExifJpeg` 夹具助手落 CanvasTestCase/测试类，GD 驱动本机验证、Imagick 腿由 CI 覆盖
- 依赖：`hankchen/php-canvas-next` 消费 v2.0.1（约束 `^2.0` 不变）

## 2.0.0 - 2026-10-19

**Breaking（ADR 0024）**：`ImageRenderer::drawImage` 跟随核心包 v2.0.0 的双矩形 blit 原语签名——`drawImage(string $src, int $sx, int $sy, int $sw, int $sh, int $dx, int $dy, int $dw, int $dh)`，自定义后端作者参照本包实现迁移。

- `drawImage` 按 blit 契约重写：decode → crop 源矩形 → 非等比拉伸 resize 至目标矩形 → insert 左上对位（替换恒 `->cover()`），原语零 fit 语义；sw/sh ≤ 0 为整图哨兵（按解码含 EXIF 归正后实际尺寸取整图），dw/dh ≤ 0 防护不画（既有纪律，目标盒由分派层 drawRect 承担）——fill 拉伸 / tile 逐块 blit / cover 等比搬运 / contain 收缩全部由核心包分派层算定几何，GD/Imagick 双驱同源语义
- 像素点位测试（无黄金文件，assertPixelSame 先例）：fill 拉伸形变（三竖条源图 1:2 压缩分界点位，无 cover 裁切无空隙）、tile 网格（left/top 非居中锚定 + 四象限源图整块重复 + 末行末列半块截断 + 内容盒外露盒背景、小图 center 锚点前空隙透出垫底层、大图 center 负锚点露图片中部）、dw/dh ≤ 0 只画盒
- cover / contain 存量点位零 diff 回归；未知 fit 值降级改用 `'scale-down'` 充当未知值（fill 转正后是已知值不再降级）；损坏图断言升级为全 fit 渲染结局互相等价（内在尺寸失败统一退化 fill 形态，ADR 0024）
- 依赖：`hankchen/php-canvas-next` 约束升 `^2.0`

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
