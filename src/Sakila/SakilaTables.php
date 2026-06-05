<?php

namespace Qscmf\Chat2Viz\Sakila;

/**
 * Sakila 16 张表的元信息。
 * - dropOrder: 删除顺序（FK 反向）
 * - tables: 用于前缀注入、COMMENT 注入的清单
 */
class SakilaTables
{
    /**
     * 删除顺序（FK 反向）：先删被引用多的表。
     */
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
     * 表名 → 表中文 COMMENT。
     */
    public const TABLE_COMMENTS = [
        'actor'         => '演员表',
        'film'          => '电影表',
        'customer'      => '客户表',
        'rental'        => '租赁记录表',
        'payment'       => '支付记录表',
        'category'      => '电影分类表',
        'inventory'     => '库存表',
        'staff'         => '员工表',
        'store'         => '门店表',
        'address'       => '地址表',
        'city'          => '城市表',
        'country'       => '国家表',
        'language'      => '语言表',
        'film_actor'    => '电影-演员关联表',
        'film_category' => '电影-分类关联表',
        'film_text'     => '电影简介全文索引表（由 film 表的 trigger 填充）',
    ];

    /**
     * 字段 COMMENT 配置: table => [[field, definition, comment], ...]
     * definition 必须与原 schema 完全一致，否则 MODIFY 会改变列类型。
     */
    public const FIELD_COMMENTS = [
        'film' => [
            ['title',            'VARCHAR(128) NOT NULL', '片名'],
            ['release_year',     'YEAR DEFAULT NULL', '上映年份'],
            ['language_id',      'TINYINT UNSIGNED NOT NULL', '所属语言ID，对应language表'],
            ['rental_duration',  'TINYINT UNSIGNED NOT NULL DEFAULT 3', '租赁周期(天)'],
            ['rental_rate',      'DECIMAL(4,2) NOT NULL DEFAULT 4.99', '基础租赁价格(美元)'],
            ['length',           'SMALLINT UNSIGNED DEFAULT NULL', '片长(分钟)'],
            ['replacement_cost', 'DECIMAL(5,2) NOT NULL DEFAULT 19.99', '损坏赔偿价格(美元)'],
            ['rating',           "ENUM('G','PG','PG-13','R','NC-17') DEFAULT 'G'", '电影分级: G=大众, PG=儿童需家长指导, PG-13=13岁以下需家长指导, R=限制级, NC-17=17岁以下禁看'],
        ],
        'customer' => [
            ['store_id',    'TINYINT UNSIGNED NOT NULL', '所属门店ID，对应store表'],
            ['first_name',  'VARCHAR(45) NOT NULL', '名'],
            ['last_name',   'VARCHAR(45) NOT NULL', '姓'],
            ['email',       'VARCHAR(50) DEFAULT NULL', '电子邮箱'],
            ['address_id',  'SMALLINT UNSIGNED NOT NULL', '地址ID，对应address表'],
            ['active',      'BOOLEAN NOT NULL DEFAULT TRUE', '是否活跃: 1=活跃, 0=已注销'],
            ['create_date', 'DATETIME NOT NULL', '注册时间'],
        ],
        'rental' => [
            ['rental_date',  'DATETIME NOT NULL', '租赁开始时间'],
            ['inventory_id', 'MEDIUMINT UNSIGNED NOT NULL', '库存ID，对应inventory表'],
            ['customer_id',  'SMALLINT UNSIGNED NOT NULL', '客户ID，对应customer表'],
            ['return_date',  'DATETIME DEFAULT NULL', '实际归还时间，NULL=尚未归还'],
            ['staff_id',     'TINYINT UNSIGNED NOT NULL', '经手员工ID，对应staff表'],
        ],
        'payment' => [
            ['customer_id',  'SMALLINT UNSIGNED NOT NULL', '客户ID，对应customer表'],
            ['staff_id',     'TINYINT UNSIGNED NOT NULL', '经手员工ID，对应staff表'],
            ['rental_id',    'INT DEFAULT NULL', '关联租赁记录ID，对应rental表'],
            ['amount',       'DECIMAL(5,2) NOT NULL', '支付金额(美元)'],
            ['payment_date', 'DATETIME NOT NULL', '支付时间'],
        ],
        'category' => [
            ['name', 'VARCHAR(25) NOT NULL', '分类名，如Action/Comedy/Drama等'],
        ],
        'inventory' => [
            ['film_id',  'SMALLINT UNSIGNED NOT NULL', '电影ID，对应film表'],
            ['store_id', 'TINYINT UNSIGNED NOT NULL', '门店ID，对应store表'],
        ],
        'staff' => [
            ['first_name', 'VARCHAR(45) NOT NULL', '名'],
            ['last_name',  'VARCHAR(45) NOT NULL', '姓'],
            ['address_id', 'SMALLINT UNSIGNED NOT NULL', '地址ID，对应address表'],
            ['email',      'VARCHAR(50) DEFAULT NULL', '电子邮箱'],
            ['store_id',   'TINYINT UNSIGNED NOT NULL', '所属门店ID，对应store表'],
            ['active',     'BOOLEAN NOT NULL DEFAULT TRUE', '在职状态: 1=在职, 0=离职'],
            ['username',   'VARCHAR(16) NOT NULL', '登录用户名'],
        ],
        'store' => [
            ['manager_staff_id', 'TINYINT UNSIGNED NOT NULL', '店长员工ID，对应staff表'],
            ['address_id',       'SMALLINT UNSIGNED NOT NULL', '门店地址ID，对应address表'],
        ],
        'address' => [
            ['address',     'VARCHAR(50) NOT NULL', '街道地址第一行'],
            ['address2',    'VARCHAR(50) DEFAULT NULL', '街道地址第二行(可选)'],
            ['district',    'VARCHAR(20) NOT NULL', '区/县'],
            ['city_id',     'SMALLINT UNSIGNED NOT NULL', '城市ID，对应city表'],
            ['postal_code', 'VARCHAR(10) DEFAULT NULL', '邮政编码'],
            ['phone',       'VARCHAR(20) NOT NULL', '联系电话'],
        ],
        'city' => [
            ['city',       'VARCHAR(50) NOT NULL', '城市名'],
            ['country_id', 'SMALLINT UNSIGNED NOT NULL', '所属国家ID，对应country表'],
        ],
        'country' => [
            ['country', 'VARCHAR(50) NOT NULL', '国家名'],
        ],
        'language' => [
            ['name', 'CHAR(20) NOT NULL', '语言名，如English/Japanese/Mandarin等'],
        ],
        'actor' => [
            ['first_name', 'VARCHAR(45) NOT NULL', '名'],
            ['last_name',  'VARCHAR(45) NOT NULL', '姓'],
        ],
    ];

    public static function allTableNames(): array
    {
        return array_keys(self::TABLE_COMMENTS);
    }
}
