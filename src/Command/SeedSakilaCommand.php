<?php

namespace Qscmf\Chat2Viz\Command;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Qscmf\Chat2Viz\Sakila\SakilaDataLoader;
use Qscmf\Chat2Viz\Sakila\SakilaProceduralObjects;
use Qscmf\Chat2Viz\Sakila\SakilaSchema;
use Qscmf\Chat2Viz\Sakila\SakilaTables;
use Qscmf\Chat2Viz\Support\Table;

/**
 * php artisan chat2viz:seed-sakila [--prefix=qs_] [--data-dir=...]
 *
 * 用 migration 语法（Schema 构建器 + DB::statement）把 Sakila 16 表 + 7 视图
 * + 3 触发器 + 6 存储过程/函数跨库（mysql/pg）建到当前库，并灌入官方数据。
 *
 * 表名默认走 Table::physicalName()（= ENV('DB_PREFIX', '') . $bare），与
 * Eloquent grammar 加的 prefix 同源；--prefix= 显式覆盖（沙箱内生效）。
 * 幂等：执行前先 unseed。
 *
 * 建序：表 → 触发器 → 数据 → 外键 → 视图/SP。
 *  触发器先于数据：film_text 由 ins_film 在灌 film 数据时填充；
 *  外键后于数据：mysqldump 的 INSERT 顺序非拓扑（address 早于 city）。
 */
class SeedSakilaCommand extends Command
{
    protected $signature = 'chat2viz:seed-sakila
        {--prefix= : 表前缀；留空则读取环境变量 DB_PREFIX}
        {--data-dir= : sakila-data.sql 所在目录}';

    protected $description = '将 Sakila 真实数据集跨库（mysql/pg）载入当前数据库，用于 chat2viz 回归测试';

    public function handle(): int
    {
        $physical = $this->physicalResolver();
        $dataDir = $this->resolveDataDir();
        $driver = DB::getDriverName();

        $this->info(sprintf('准备载入 Sakila fixture（驱动: %s，物理表名样例: %s）', $driver, $physical('film')));

        $dataFile = $dataDir . '/sakila-data.sql';
        if (!is_file($dataFile)) {
            $this->error("sakila-data.sql 未找到，请确认 data-dir 路径正确: $dataFile");
            return self::FAILURE;
        }

        // 前缀沙箱：裸 SQL（DB::statement/unprepared）不会自动补 Laravel 连接
        // 前缀，而 Schema 构建器会补——同一裸名在两条路径上结果不同。全程压制
        // 框架前缀并改用物理名 resolver（qs_xxx），让 Schema 与裸 SQL 都落到 qs_xxx。
        $conn = DB::connection();
        $savedPrefix = $conn->getTablePrefix();
        $conn->setTablePrefix('');
        try {
            // 幂等：先 unseed（在同一沙箱内，避免二次套娃）
            $unseed = $this->laravel->make(UnseedSakilaCommand::class);
            $unseed->setLaravel($this->laravel);
            $unseed->run(new \Symfony\Component\Console\Input\ArrayInput([
                '--prefix' => (string) $this->option('prefix'),
            ]), $this->output);

            $schema = new SakilaSchema($physical);
            $procedural = new SakilaProceduralObjects($physical);
            $loader = new SakilaDataLoader($physical, $dataFile);

            $this->info('1/6 建表（Schema 构建器）...');
            $schema->createTables();

            $this->info('2/6 建触发器（先于数据，以便 film_text 自动填充）...');
            $procedural->createTriggers();

            $this->info('3/6 灌数据...');
            $loader->load();

            $this->info('4/6 加外键（后于数据，规避 INSERT 顺序）...');
            $schema->addForeignKeys();

            $this->info('5/6 建视图...');
            $procedural->createViews();

            $this->info('6/6 建存储过程/函数...');
            try {
                $procedural->createRoutines();
            } catch (\Throwable $e) {
                // 6 个 routine 无任何 NL2SQL demo 调用（见 SakilaProceduralObjects 注释），
                // 仅为 Sakila 完整性保留；pg 的 PL/pgSQL 忠实改写可能因方言细节失败。
                // 降级为警告不抛错——demo 关键产物（表/数据/外键/视图/触发器，步骤 1-5）
                // 此时已全部建成。失败信息原样透出，便于按真实报错精修。
                $this->warn('6/6 存储过程/函数建表未完全成功（不影响 demo）：' . $e->getMessage());
            }

            $this->newLine();
            $this->info('Sakila fixture 载入完成，验证行数:');
            $this->renderRowCountTable($physical);
        } finally {
            $conn->setTablePrefix($savedPrefix);
        }

        return self::SUCCESS;
    }

    /**
     * 物理表名 resolver（裸 SQL/Schema 通用）。
     *
     * 显式 --prefix → prefix.bare（已是物理名，如 t_film）；
     * 否则 Table::physicalName()（= ENV('DB_PREFIX', '') . $bare）。
     * 配合前缀沙箱，Schema 构建器与裸 SQL 落到同一物理名。
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

    private function resolveDataDir(): string
    {
        $opt = $this->option('data-dir');
        if (is_string($opt) && $opt !== '') {
            return rtrim($opt, '/');
        }
        return realpath(__DIR__ . '/../Sakila/data') ?: __DIR__ . '/../Sakila/data';
    }

    private function renderRowCountTable(\Closure $resolver): void
    {
        $rows = [];
        foreach (SakilaTables::DROP_ORDER as $table) {
            $fullName = $resolver($table);
            try {
                $count = DB::table($fullName)->count();
                $rows[] = [$fullName, number_format($count)];
            } catch (\Throwable $e) {
                $rows[] = [$fullName, '<error>缺失</error>'];
            }
        }
        $this->table(['表名', '行数'], $rows);
    }
}
