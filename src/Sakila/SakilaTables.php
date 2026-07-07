<?php

namespace Qscmf\Chat2Viz\Sakila;

/**
 * Sakila 16 张表的删除顺序（FK 反向：引用方先删）。
 *
 * 表结构、列类型、中文 COMMENT 的权威定义在 {@see SakilaSchema}（migration 语法）。
 * 视图/触发器/存储过程定义在 {@see SakilaProceduralObjects}。
 */
class SakilaTables
{
    public const DROP_ORDER = [
        'payment',
        'rental',
        'inventory',
        'customer',
        'film_category',
        'film_actor',
        'film_text',
        'address',
        'city',
        'store',
        'staff',
        'film',
        'country',
        'category',
        'language',
        'actor',
    ];

    /**
     * 全部 16 张裸表名（顺序无关，集合语义）。
     */
    public static function allTableNames(): array
    {
        return self::DROP_ORDER;
    }
}
