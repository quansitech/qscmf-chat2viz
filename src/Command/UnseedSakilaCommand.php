<?php

namespace Qscmf\Chat2Viz\Command;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Qscmf\Chat2Viz\Sakila\SakilaProceduralObjects;
use Qscmf\Chat2Viz\Sakila\SakilaSchema;
use Qscmf\Chat2Viz\Sakila\SakilaTables;
use Qscmf\Chat2Viz\Support\Table;

/**
 * php artisan chat2viz:unseed-sakila [--prefix=qs_]
 *
 * 删除 Sakila 全部对象（SP/视图/触发器/表），FK 随表而灭。
 * 删序与建序相反：过程化对象先于表，表按 FK 反向序（DROP_ORDER）逐个 drop。
 * 通常由 chat2viz:seed-sakila 自动调用一次保证幂等。
 */
class UnseedSakilaCommand extends Command
{
    protected $signature = 'chat2viz:unseed-sakila
        {--prefix= : 表前缀；留空则走 ENV("DB_PREFIX", "")}';

    protected $description = '从当前数据库删除 Sakila 全部对象（跨库）';

    public function handle(): int
    {
        $physical = $this->physicalResolver();
        $this->info(sprintf('准备删除 Sakila fixture（物理表名样例: %s）', $physical('film')));

        // 前缀沙箱：与 seed 对称，裸 SQL（drop trigger/view/function）与 Schema::drop
        // 都落到同一物理名 qs_xxx。见 SeedSakilaCommand 同名注释。
        $conn = DB::connection();
        $savedPrefix = $conn->getTablePrefix();
        $conn->setTablePrefix('');
        try {
            $procedural = new SakilaProceduralObjects($physical);
            $schema = new SakilaSchema($physical);

            // 过程化对象先于表（pg 不允许删被视图依赖的表）
            try { $procedural->dropRoutines(); } catch (\Throwable $e) { $this->line('  drop routines: ' . $e->getMessage()); }
            try { $procedural->dropViews(); } catch (\Throwable $e) { $this->line('  drop views: ' . $e->getMessage()); }
            try { $procedural->dropTriggers(); } catch (\Throwable $e) { $this->line('  drop triggers: ' . $e->getMessage()); }

            // 表按 FK 反向序删除（引用方先删）
            $schema->down();

            $this->line('  已尝试删除 ' . count(SakilaTables::DROP_ORDER) . ' 张表 + 过程化对象');
        } finally {
            $conn->setTablePrefix($savedPrefix);
        }
        return self::SUCCESS;
    }

    /**
     * 物理表名 resolver（裸 SQL/Schema 通用），与 SeedSakilaCommand 对称。
     *
     * @return \Closure(string):string
     */
    private function physicalResolver(): \Closure
    {
        $explicit = $this->option('prefix');
        if (is_string($explicit) && $explicit !== '') {
            return static fn (string $bare): string => $explicit . $bare;
        }
        return static fn (string $bare): string => Table::physicalName($bare);
    }
}
