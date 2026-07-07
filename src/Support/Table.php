<?php

namespace Qscmf\Chat2Viz\Support;

/**
 * chat2viz 表名解析。
 *
 * 物理表名 = ENV('DB_PREFIX', '') . $bareName —— 来自项目 .env 的 DB_PREFIX，
 * 不在包内硬编码。v15 Eloquent grammar 走 database.connections.*.prefix；
 * v13 ThinkModel 走 ThinkPHP DB_PREFIX；项目级配置源唯一。
 *
 * 端点：
 *  - Table::name($bare)         → 物理表名（保留历史接口名）
 *  - Table::physicalName($bare) → 同上（raw SQL / DB::unprepared 语义清晰）
 *
 * 调用方：dashboard 迁移的 Schema::create()（4 个）、4 个 Eloquent Model 的
 * getTable() override、SeedSakilaCommand 的 physical resolver。
 *
 * 注：本类**不**依赖 Eloquent/Schema/Sakila 路径——它是物理名计算的纯函数。
 * Sakila seed 的 --prefix= 显式覆盖走独立 resolver closure，不经过本类。
 */
final class Table
{
    /**
     * 物理表名（= ENV('DB_PREFIX', '') . $bareName）。
     *
     * @param string $bareName 业务名（不含前缀），如 'chat2viz_dashboards'
     * @return string 物理表名
     */
    public static function name(string $bareName): string
    {
        return self::prefix() . $bareName;
    }

    /** 别名（给 raw SQL / DB::statement / DB::unprepared 用，意图清晰） */
    public static function physicalName(string $bareName): string
    {
        return self::name($bareName);
    }

    /**
     * 项目级 DB_PREFIX。
     *
     * 用项目惯例 ENV()（不是 getenv()）——Laravel dotenv 不会写进程环境表，
     * getenv('DB_PREFIX') 在 v15 上经常拿不到值；ENV() 走 $_SERVER/$_ENV/
     * getenv() 三级查找，是 host framework 实际读 .env 的统一入口。
     */
    private static function prefix(): string
    {
        $v = ENV('DB_PREFIX', '');
        return is_string($v) ? $v : '';
    }
}
