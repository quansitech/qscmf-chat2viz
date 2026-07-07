<?php

namespace Qscmf\Chat2Viz\Sakila;

use Illuminate\Support\Facades\DB;

/**
 * 把 Sakila 官方 data.sql 清洗为跨库可执行的 INSERT 流。
 *
 * 清洗规则（均为标准 SQL 化，mysql/pg 通用）：
 * 1. 仅保留 INSERT … ; 语句（剥离 license 头、SET/COMMIT/LOCK/UNLOCK/USE/AUTOCOMMIT）。
 * 2. 删除 MySQL 版本条件注释块（包裹 address.location 的 GEOMETRY WKB；location 列已
 *    省略，且分隔逗号落在注释块内，整块删除后列数恰好对齐 8 列）。
 * 3. INSERT INTO `bare` → INSERT INTO 物理表名（resolver 解析，qs_xxx）。
 * 4. pg：残留的 0x<HEX>（staff.picture 的 PNG）→ '\x<HEX>'（bytea hex 字面量）。
 *
 * data.sql 经实测：0 个反斜杠转义（全用 '' 标准转义），故 pg standard_conforming_strings
 * 下无需额外处理。
 */
class SakilaDataLoader
{
    /**
     * @param \Closure(string):string $resolver 裸表名 → 物理表名
     */
    public function __construct(private \Closure $resolver, private string $dataPath)
    {
    }

    /**
     * 纯函数：原始 SQL → 可执行 SQL。无 DB 依赖，供单测。
     */
    public static function transform(string $rawSql, \Closure $resolver, bool $isPg): string
    {
        $sql = self::keepInserts($rawSql);
        $sql = preg_replace('/\/\*!\d+\b.*?\*\//s', '', $sql);
        $sql = preg_replace_callback(
            // 反引号可选：官方 sakila-data.sql 多数 INSERT 不带反引号，少数带。
            '/INSERT\s+INTO\s+`?([a-z_]+)`?(?=\s|\()/i',
            static fn ($m) => 'INSERT INTO ' . $resolver($m[1]),
            $sql
        );
        if ($isPg) {
            $sql = preg_replace('/0x([0-9A-Fa-f]{2,})/', "'\\x$1'", $sql);
        }
        return $sql;
    }

    public function load(): void
    {
        $raw = (string) file_get_contents($this->dataPath);
        $isPg = DB::getDriverName() === 'pgsql';
        DB::unprepared(self::transform($raw, $this->resolver, $isPg));
    }

    /**
     * 状态机保留 INSERT … ;（跨多行；BLOB/长行可能折行）。
     */
    private static function keepInserts(string $sql): string
    {
        $out = [];
        $inInsert = false;
        foreach (explode("\n", $sql) as $line) {
            $trimmed = trim($line);
            if ($inInsert) {
                $out[] = $line;
                if (str_ends_with($trimmed, ';')) {
                    $inInsert = false;
                }
                continue;
            }
            if (preg_match('/^INSERT\s+INTO\s+/i', $trimmed)) {
                $out[] = $line;
                $inInsert = !str_ends_with($trimmed, ';');
            }
        }
        return implode("\n", $out);
    }
}
