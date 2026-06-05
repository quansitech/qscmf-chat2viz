<?php

namespace Qscmf\Chat2Viz\Command;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Qscmf\Chat2Viz\Sakila\SakilaTables;

/**
 * php artisan chat2viz:unseed-sakila [--prefix=qs_]
 *
 * 一次性 DROP Sakila 16 张表（FK 反向顺序），不连同其他 qs_* 表。
 * 通常由 chat2viz:seed-sakila 自动调用一次保证幂等。
 */
class UnseedSakilaCommand extends Command
{
    protected $signature = 'chat2viz:unseed-sakila
        {--prefix= : 表前缀，默认读取 env DB_PREFIX 或 qs_}';

    protected $description = '从当前数据库删除 Sakila 16 张表（FK 反向顺序）';

    public function handle(): int
    {
        $prefix = $this->resolvePrefix();
        $this->info(sprintf('准备删除 Sakila fixture（表前缀: %s）', $prefix));

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $dropped = [];
        $missing = [];
        $stale = [];

        foreach (SakilaTables::DROP_ORDER as $table) {
            $fullName = $prefix . $table;
            $exists = DB::selectOne(
                "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$fullName]
            );
            if (!$exists || (int) $exists->c === 0) {
                $missing[] = $fullName;
            } else {
                DB::statement("DROP TABLE IF EXISTS `$fullName`");
                $dropped[] = $fullName;
            }

            // 清理残留的无前缀 Sakila 旧表
            if ($prefix !== '') {
                $staleExists = DB::selectOne(
                    "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                    [$table]
                );
                if ($staleExists && (int) $staleExists->c > 0) {
                    DB::statement("DROP TABLE IF EXISTS `$table`");
                    $stale[] = $table;
                }
            }
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        if (!empty($dropped)) {
            $this->line('  已删除 ' . count($dropped) . ' 张表: ' . implode(', ', $dropped));
        }
        if (!empty($stale)) {
            $this->line('  清理残留旧表 ' . count($stale) . ' 张: ' . implode(', ', $stale));
        }
        if (!empty($missing)) {
            $this->line('  本就不存在: ' . count($missing) . ' 张');
        }

        return self::SUCCESS;
    }

    private function resolvePrefix(): string
    {
        $cli = $this->option('prefix');
        if (is_string($cli) && $cli !== '') {
            return $cli;
        }
        $env = env('DB_PREFIX', 'qs_');
        return is_string($env) ? $env : 'qs_';
    }
}
