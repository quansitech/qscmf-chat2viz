# chat2viz v13 ↔ v15 跨版本兼容设计

> 日期：2026-07-02 ｜ 分支：`feat/dashboard-dsl`
> 状态：设计已定，待 review → 实施

## 1. 背景

`quansitech/qscmf-chat2viz` 原按 v13 单版本设计（CLAUDE.md 旧描述已过时）。现已具备**运行时适配器架构**（`src/Adapter/AdapterFactory.php`），目标改为**一套代码同时支持 v13 / v14 / v15，不再分版本分支**。本设计消除 v13 ↔ v15 的 5 类不兼容，全部在包内闭环，不修改宿主仓库。

宿主配置相反（已亲验）：

| | v13 (`/var/www/qs_cmf_13`) | v15 (`/var/www/qs_cmf_15`) |
|---|---|---|
| Laravel `database.php` prefix | `''` (`:39/53/70/84`) | `qs_` (`:16`) |
| TP `DB_PREFIX` | `qs_` (`config.php:51`) | `qs_` (`config.php:46`) |
| driver | mysql | pgsql |

## 2. 根因总览

| ID | 症状 | 根因 | 归属 |
|----|------|------|------|
| **A1** | 迁移建到 `qs_qs_x`，代码查 `qs_x`，读写全错位 | 迁移硬编码 `qs_` 是为 v13 的空 Laravel prefix 设计；v15 Laravel prefix=`qs_` 再叠一层 | 前缀策略 |
| **A2** | pg 下 `idx_conv_created` 跨表重名报 `42P07` | MySQL 索引名表级作用域，pg schema 级全局唯一；两表同名 | pg 可移植 |
| **A3** | `chat2viz:seed-sakila` 在 pg 跑不通 | `sakila-schema.sql` 纯 MySQL DDL（`ENGINE`/`AUTO_INCREMENT`/`CREATE SCHEMA`） | pg 可移植 |
| **B1** | v15 无 `app/Extends` 模块，单图聊天 `display()` 找不到模板 | chat2viz 假设宿主有 Extends（v13 有，v15 已删）+ 单图走 Smarty | 宿主结构 + 产品决策 |
| **B2** | v15 Inertia React 资源未发布 | ServiceProvider `realpath` 探测宿主 `Pages/Chat2viz` 子目录，恒 false（逻辑写反） | 包内 bug |

## 3. 战略决策

### A1 — `Table` helper 动态前缀（包内，不依赖宿主）

**洞察**：ThinkModel 侧天然正确，不动。`M('chat2viz_x')` 加的是 TP `DB_PREFIX`（两版都是 `qs_`）→ 物理 `qs_chat2viz_x` ✓。问题只在 **Laravel-prefix 域**（迁移 + Eloquent），它们加的是 Laravel `database.php` prefix（v13 `''` / v15 `qs_`）。

**方案**：新增 `src/Support/Table.php`，集中处理 Laravel 域的双前缀——"宿主 Laravel prefix 已是 `qs_` 就不重复加，否则补上"：

```php
final class Table {
    private const BUSINESS = 'qs_'; // chat2viz 物理表固定业务前缀
    public static function name(string $t): string {
        $lp = self::laravelPrefix();
        return ($lp === self::BUSINESS ? '' : self::BUSINESS) . $t;
    }
    private static function laravelPrefix(): string {
        $c = (string) config('database.default', '');
        return (string) config("database.connections.{$c}.prefix", '');
    }
}
```

**物理表名验证**（两版统一对齐到 `qs_chat2viz_x`）：

| 调用 | v13（LP=`''`） | v15（LP=`qs_`） |
|------|----------------|-----------------|
| 迁移 `Schema::create(Table::name('chat2viz_x'))` | `qs_chat2viz_x` ✓ | `qs_chat2viz_x` ✓ |
| Eloquent `getTable() = Table::name(...)` | `qs_chat2viz_x` ✓ | `qs_chat2viz_x` ✓ |
| ThinkModel `M('chat2viz_x')`（常量不动） | `qs_chat2viz_x` ✓ | `qs_chat2viz_x` ✓ |

**影响面**：
- 迁移：所有 `Schema::create` / `hasTable` / `dropIfExists` 的 chat2viz 表名（dashboard 迁移 4 表、feedback 迁移、`2026_06_24` 唯一索引迁移——实施时逐一核对）。
- Eloquent Model 5 个（`Dashboard` / `DashboardVersion` / `Conversation` / `ConversationMessage` / `FeedbackRecord`）：重写 `getTable(): string { return Table::name('chat2viz_x'); }`。
- ThinkModel repo 4 个常量（`TABLE_DASHBOARDS` 等，`ThinkModelDashboardRepository:16-19`）：**不动**（已不带前缀，靠 TP `DB_PREFIX` 补）。

**假设**：v13/v15 的 TP `DB_PREFIX=qs_`（已验）。**风险**：已部署 v15 环境建了 `qs_qs_x`，需 drop 重建（chat2viz 表无生产数据）。**待验证**：迁移运行时 `config()` 可用性（Laravel 迁移上下文应可用）。

---

### A2 — 索引名加表段

feedback 迁移 `:53` 的 `idx_conv_created` 与 conversation_messages 迁移 `:107` 的 `idx_conv_created` 跨表重名。**改名为表段前缀**：`idx_msg_conv_created`（conversation_messages）、`idx_feedback_conv_created`（feedback）。包内机械改名，无战略分叉。已部署环境需 rollback + migrate。

---

### A3 — Sakila 改 Laravel 迁移重建

废弃 `src/Sakila/data/sakila-schema.sql`（纯 MySQL DDL）。16 表 + 索引 + 种子数据改写为 Laravel 迁移（`Schema::create` 跨库），`SeedSakilaCommand` 改为跑迁移 + seed。顺带复用 A1 的 `Table::name()` 前缀逻辑，与 chat2viz 主迁移一套机制。工作量最大（16 表），但 demo 夹具从此跨库一致。

---

### B1 — 单图聊天 v15 弃用（条件跳过注册）

**产品决策**：单图聊天（`Chat2VizController`，`/extends/Chat2Viz`）在 v15 不需要；v15 只保留 Dashboard。

**保守方案**（不删代码，可逆）：ServiceProvider 中 `Chat2VizController` 的 extends 注册包条件——按"宿主能力"分流，不硬编码版本号：

```php
// 仅当宿主有 Extends 模块（v13/v14）才注册单图聊天
if (is_dir(APP_PATH . 'Extends')) {
    RegisterContainer::registerController('extends', 'Chat2Viz', Chat2VizController::class);
    $this->safeRegisterSymLink(APP_PATH . 'Extends/View/default/Chat2Viz', __DIR__ . '/../view/default/Chat2Viz');
}
```

v15 无 Extends → 跳过 → 不再 `display()` 报错。`Chat2VizController` 类保留供 v13/v14 使用。

**⚠️ 待确认/需实施时验证**：
1. 单图范围：本设计采用"v15 弃用、v13/v14 保留"。若产品要**全版本废弃**单图，改为直接移除 `Chat2VizController` + 视图。
2. `PublicDashboardController`（公开看板视图，ServiceProvider`:47-51` 也注册在 extends）：v15 下它走 `createRenderer`→Inertia，不 `display` Smarty，**理论上不依赖 Extends 物理目录**。需实测 v15 下 `/extends/Chat2VizDashboard` 路由可达性 + Inertia 页面渲染（依赖 B2 修好）。若 v15 路由不接受 `extends` 段，需改挂到存在的模块。

---

### B2 — Inertia 探测逻辑修复（包内 bugfix）

`Chat2VizServiceProvider:73-92` 当前用 `realpath(宿主/.../Pages/Chat2viz)` 判断——但 `Chat2viz` 子目录是包要发布过去的，宿主不会有，realpath 恒 false。**改为检测父目录 `Pages` 存在 + Inertia 类存在**即注册 symlink：

```php
$pagesDir = app_path('../../resources/js/backend/Pages');
if (class_exists(\Qscmf\Lib\Inertia\Inertia::class) && realpath($pagesDir) !== false) {
    $this->safeRegisterSymLink($pagesDir . '/Chat2viz', __DIR__ . '/../asset/inertia/Chat2viz');
}
```

`registerSymLink` 负责创建 `Chat2viz` 子链接。包内机械修复。

## 4. 附带修订

- **`composer.json`**：v15 在射程，`think-core: >=13.0.0` 保持（**不加** `<14` 上界）。
- **CLAUDE.md**（包级 `src/` 上方 + 宿主提及处）：更新为"适配器架构，一套代码 v13/v14/v15"，删除"v13 单版本 / 分版本分支"过时描述；记录单图聊天 v15 弃用决策。

## 5. 实施顺序

按依赖与风险递增：

1. **B2** — Inertia 探测修复（包内 bugfix，最独立）
2. **A2** — 索引名加表段（机械改名）
3. **A1** — `Table` helper + 迁移/Eloquent 改造（地基，最高风险）
4. **A3** — Sakila 迁移化（依赖 A1 的 `Table::name()`）
5. **B1** — 单图聊天条件跳过（依赖 B1 待确认项澄清）

每步配：phpunit（`~/.claude/bin/phpunit-safe`）+ 前端 `npm run typecheck`。A1/A3 涉及迁移，需在 v13（mysql）与 v15（pgsql）双环境各跑一次 `php artisan migrate` 验证。

## 6. 待确认 / 待验证清单

- [ ] B1 单图范围：v15 弃用（保守）vs 全版本废弃
- [ ] PublicDashboardController 在 v15 的 extends 路由可达性 + 页面渲染
- [ ] `Table::name()` 在迁移运行时 `config()` 可用性
- [ ] 已部署 v15 环境 chat2viz 表确认无生产数据 → drop 重建
- [ ] `2026_06_24_conversation_one_to_one_unique_dashboard_uid.php` 是否含表名硬编码（A1 一并核对）
