<?php

namespace Qscmf\Chat2Viz\Sakila;

use Illuminate\Support\Facades\DB;

/**
 * 给 Sakila 表/字段补中文业务语义 COMMENT。
 * 这是 LLM 生成正确 SQL 的关键提示。
 *
 * 所有 ALTER 必须在 seed 完数据之后执行（schema 已经是最终形态）。
 */
class CommentAnnotator
{
    public function __construct(private string $prefix)
    {
    }

    public function applyAll(): int
    {
        $count = 0;
        $count += $this->applyTableComments();
        $count += $this->applyFieldComments();
        return $count;
    }

    private function applyTableComments(): int
    {
        $count = 0;
        foreach (SakilaTables::TABLE_COMMENTS as $table => $comment) {
            $fullName = $this->prefix . $table;
            $escaped = addslashes($comment);
            DB::statement("ALTER TABLE `$fullName` COMMENT = '$escaped'");
            $count++;
        }
        return $count;
    }

    private function applyFieldComments(): int
    {
        $count = 0;
        foreach (SakilaTables::FIELD_COMMENTS as $table => $columns) {
            $fullName = $this->prefix . $table;
            foreach ($columns as [$field, $definition, $comment]) {
                $escaped = addslashes($comment);
                DB::statement("ALTER TABLE `$fullName` MODIFY `$field` $definition COMMENT '$escaped'");
                $count++;
            }
        }
        return $count;
    }
}
