<?php

namespace Qscmf\Chat2Viz\Sakila;

/**
 * Sakila 官方 SQL 的清洗与表名前缀注入。
 *
 * 关键问题：
 * 1. schema.sql 含 USE/CREATE SCHEMA/DROP SCHEMA、SET 会话变量、视图/存储过程（用 DELIMITER $$ 语法）
 *    → 用括号配对的 state machine 提取 CREATE TABLE 块
 * 2. data.sql 含 license 头、SET/COMMIT 事务包裹、staff 表 BLOB（PNG hex）大字段
 *    → 剥离法：去掉 SET/COMMIT/license，其余原样保留
 * 3. 表名需加 DB_PREFIX（默认 qs_）
 *    → 用 word-boundary regex 替换，长名优先避免 film_actor / actor 冲突
 */
class SqlTransformer
{
    private string $prefix;

    public function __construct(string $prefix = '')
    {
        $this->prefix = $prefix;
    }

    /**
     * 从 Sakila 官方 schema.sql 提取 CREATE TABLE ... ; 块，自动加表前缀。
     * 过滤掉：USE/CREATE SCHEMA/DROP SCHEMA、SET 语句、视图、存储过程、函数、注释、空行。
     */
    public function transformSchema(string $sql): string
    {
        $blocks = $this->extractCreateTableBlocks($sql);
        if ($this->prefix !== '') {
            $blocks = $this->prefixConstraintNames($blocks);
            $blocks = $this->applyPrefix($blocks);
        }
        return $blocks;
    }

    /**
     * 从 Sakila 官方 data.sql 剥离 SET/COMMIT/license 头，保留所有 INSERT，自动加表前缀。
     */
    public function transformData(string $sql): string
    {
        $stripped = $this->stripDataNoise($sql);
        if ($this->prefix !== '') {
            $stripped = $this->applyPrefix($stripped);
        }
        return $stripped;
    }

    /**
     * 用括号配对解析 CREATE TABLE 块。处理 ENUM('G','PG',...)、SET('Trailers',...) 等嵌套括号。
     */
    private function extractCreateTableBlocks(string $sql): string
    {
        $lines = explode("\n", $sql);
        $output = [];
        $buffer = '';
        $inCreate = false;
        $parenDepth = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (!$inCreate) {
                if (preg_match('/^CREATE\s+TABLE\s+/i', $trimmed)) {
                    $inCreate = true;
                    $buffer = $line;
                    $parenDepth = substr_count($line, '(') - substr_count($line, ')');
                }
            } else {
                $buffer .= "\n" . $line;
                $parenDepth += substr_count($line, '(') - substr_count($line, ')');

                if ($parenDepth <= 0 && str_ends_with($trimmed, ';')) {
                    $output[] = $buffer;
                    $buffer = '';
                    $inCreate = false;
                }
            }
        }

        return implode("\n\n", $output);
    }

    /**
     * 仅保留 INSERT 语句，剥离 license 头、SET/USE/LOCK/TRIGGER 等所有非 INSERT 内容。
     * 使用状态机追踪多行 INSERT（BLOB 字段可能导致 INSERT 跨多行）。
     */
    private function stripDataNoise(string $sql): string
    {
        $lines = explode("\n", $sql);
        $output = [];
        $inInsert = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($inInsert) {
                $output[] = $line;
                if (str_ends_with($trimmed, ';')) {
                    $inInsert = false;
                }
                continue;
            }

            if (preg_match('/^INSERT\s+INTO\s+/i', $trimmed)) {
                $output[] = $line;
                $inInsert = !str_ends_with($trimmed, ';');
            }
        }

        return implode("\n", $output);
    }

    /**
     * 给 Sakila 16 张表名加前缀。仅在 SQL 表引用上下文中替换（CREATE TABLE / REFERENCES / INSERT INTO），
     * 避免误伤与表名同名的列名（如 address、city、country 列）。
     * 长名优先（film_actor 早于 actor），避免 film_actor 被错误地拆成 film_qs_actor。
     */
    private function applyPrefix(string $sql): string
    {
        $names = SakilaTables::allTableNames();
        usort($names, fn($a, $b) => strlen($b) - strlen($a));

        foreach ($names as $name) {
            $quoted = preg_quote($name, '/');
            $replacement = $this->prefix . $name;

            // 只在 SQL 关键字之后的表引用位置替换，不影响列名、索引名、约束名
            $sql = preg_replace(
                '/\b(?:CREATE\s+TABLE|REFERENCES|INSERT\s+INTO)\s+`?\K\b' . $quoted . '\b/',
                $replacement,
                $sql
            );
        }
        return $sql;
    }

    /**
     * 给 FK 约束名加前缀，避免与数据库中已有的同名约束冲突。
     * CONSTRAINT `fk_address_city` → CONSTRAINT `qs_fk_address_city`
     * CONSTRAINT fk_customer_address → CONSTRAINT qs_fk_customer_address
     */
    private function prefixConstraintNames(string $sql): string
    {
        return preg_replace_callback(
            '/\bCONSTRAINT\s+(`?)(fk_\w+)\1\s+FOREIGN\s+KEY\b/i',
            function ($matches) {
                $backtick = $matches[1];
                $name = $matches[2];
                if (str_starts_with($name, $this->prefix)) {
                    return $matches[0];
                }
                return 'CONSTRAINT ' . $backtick . $this->prefix . $name . $backtick . ' FOREIGN KEY';
            },
            $sql
        );
    }
}
