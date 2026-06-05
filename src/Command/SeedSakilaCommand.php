<?php

namespace Qscmf\Chat2Viz\Command;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Qscmf\Chat2Viz\Sakila\CommentAnnotator;
use Qscmf\Chat2Viz\Sakila\SakilaTables;
use Qscmf\Chat2Viz\Sakila\SqlTransformer;

/**
 * php artisan chat2viz:seed-sakila [--prefix=qs_] [--force]
 *
 * 把 Sakila 16 张表灌到当前数据库（默认加 qs_ 前缀），含中文 COMMENT。
 * 幂等：执行前先 unseed 再 seed。可重复运行。
 */
class SeedSakilaCommand extends Command
{
    protected $signature = 'chat2viz:seed-sakila
        {--prefix= : 表前缀，默认读取 env DB_PREFIX 或 qs_}
        {--skip-comments : 跳过中文 COMMENT 注入}
        {--data-dir= : sakila-data.sql 和 sakila-schema.sql 所在目录}';

    protected $description = '将 Sakila 真实数据集载入当前数据库（含中文 COMMENT），用于 chat2viz 回归测试';

    public function handle(): int
    {
        $prefix = $this->resolvePrefix();
        $dataDir = $this->resolveDataDir();

        $this->info(sprintf('准备载入 Sakila fixture（表前缀: %s）', $prefix));
        $this->line(sprintf('数据目录: %s', $dataDir));

        if (!is_file($dataDir . '/sakila-schema.sql') || !is_file($dataDir . '/sakila-data.sql')) {
            $this->error("Sakila SQL 文件未找到，请确认 data-dir 路径正确");
            return self::FAILURE;
        }

        $unseed = $this->laravel->make(UnseedSakilaCommand::class);
        $unseed->setLaravel($this->laravel);
        $unseed->run(new \Symfony\Component\Console\Input\ArrayInput([
            '--prefix' => $prefix,
        ]), $this->output);

        $transformer = new SqlTransformer($prefix);

        $this->info('1/3 载入 schema...');
        $schemaSql = file_get_contents($dataDir . '/sakila-schema.sql');
        $schemaTransformed = $transformer->transformSchema($schemaSql);
        $this->executeSql($schemaTransformed);

        $this->info('2/3 载入 data...');
        $dataSql = file_get_contents($dataDir . '/sakila-data.sql');
        $dataTransformed = $transformer->transformData($dataSql);
        $this->executeSql($dataTransformed);

        if (!$this->option('skip-comments')) {
            $this->info('3/3 注入中文 COMMENT...');
            $annotator = new CommentAnnotator($prefix);
            $count = $annotator->applyAll();
            $this->line("  共写入 $count 条 COMMENT（表 + 字段）");
        }

        $this->newLine();
        $this->info('Sakila fixture 载入完成，验证行数:');
        $this->renderRowCountTable($prefix);

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

    private function resolveDataDir(): string
    {
        $opt = $this->option('data-dir');
        if (is_string($opt) && $opt !== '') {
            return rtrim($opt, '/');
        }
        return realpath(__DIR__ . '/../Sakila/data') ?: __DIR__ . '/../Sakila/data';
    }

    private function executeSql(string $sql): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::statement('SET UNIQUE_CHECKS=0');
        DB::statement('SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO"');
        try {
            DB::unprepared($sql);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            DB::statement('SET UNIQUE_CHECKS=1');
        }
    }

    private function renderRowCountTable(string $prefix): void
    {
        $rows = [];
        foreach (SakilaTables::DROP_ORDER as $table) {
            $fullName = $prefix . $table;
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
