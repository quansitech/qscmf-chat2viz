<?php

namespace Qscmf\Chat2Viz\Sakila;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sakila 16 张表的跨库 schema（migration 语法）。
 *
 * - 表名走 $resolver（默认 Table::physicalName()，= ENV('DB_PREFIX', '') . $bare）。
 * - 列类型用 Blueprint 可移植方法；ENUM→enum()（pg 退化为 varchar+check），
 *   SET→string()（无原生等价，存逗号串），YEAR→year()（pg 退化为 integer）。
 * - 中文 COMMENT 直接挂在列/表上（mysql ALTER COMMENT / pg COMMENT ON COLUMN），
 *   取代旧的 CommentAnnotator。
 * - 外键单独一轮添加，避免建表顺序依赖（store↔staff 循环引用）。
 * - 索引不显式命名，交由 Laravel 按 {table}_{col}_index 自动生成 → pg 全局唯一，无 42P07。
 *
 * 视图/触发器/存储过程见 SakilaProceduralObjects（mysql/pg 方言分支）。
 *
 * 唯一可移植性例外：address.location（GEOMETRY）省略——pg 需 PostGIS 扩展，
 * 且原 schema 中本就是 MySQL 版本条件注释包裹的可选列，全部 20 条 NL2SQL demo 均不使用空间查询。
 */
class SakilaSchema
{
    /**
     * @param \Closure(string):string $resolver 裸表名 → 物理表名
     */
    public function __construct(private \Closure $resolver)
    {
    }

    private function t(string $bare): string
    {
        return ($this->resolver)($bare);
    }

    public function down(): void
    {
        // store↔staff 互相外键引用，无任何线性删除序能避开 FK 冲突，故按驱动绕开：
        if (DB::getDriverName() === 'pgsql') {
            // CASCADE 连带删除引用本表的外键约束，顺序无关。
            foreach (SakilaTables::DROP_ORDER as $bare) {
                DB::statement('DROP TABLE IF EXISTS ' . $this->t($bare) . ' CASCADE');
            }
            return;
        }
        // mysql：会话内关 FK 检查，顺序无关；IF EXISTS 容错空库。
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (SakilaTables::DROP_ORDER as $bare) {
            Schema::dropIfExists($this->t($bare));
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function createTables(): void
    {
        // ── 基础实体（被引用，先建） ──────────────────────────────────────
        $this->createLanguage();
        $this->createCategory();
        $this->createActor();
        $this->createCountry();
        $this->createFilm();
        $this->createFilmText();
        $this->createCity();
        $this->createAddress();
        $this->createStaff();
        $this->createStore();
        $this->createCustomer();
        $this->createFilmActor();
        $this->createFilmCategory();
        $this->createInventory();
        $this->createRental();
        $this->createPayment();
    }

    // ─── 各表定义 ────────────────────────────────────────────────────────

    private function createLanguage(): void
    {
        Schema::create($this->t('language'), function (Blueprint $t) {
            $t->tinyIncrements('language_id');
            $t->char('name', 20)->comment('语言名，如English/Japanese/Mandarin等');
            $t->timestamp('last_update')->useCurrent();
        });
    }

    private function createCategory(): void
    {
        Schema::create($this->t('category'), function (Blueprint $t) {
            $t->tinyIncrements('category_id');
            $t->string('name', 25)->comment('分类名，如Action/Comedy/Drama等');
            $t->timestamp('last_update')->useCurrent();
        });
    }

    private function createActor(): void
    {
        Schema::create($this->t('actor'), function (Blueprint $t) {
            $t->smallIncrements('actor_id');
            $t->string('first_name', 45)->comment('名');
            $t->string('last_name', 45)->comment('姓');
            $t->timestamp('last_update')->useCurrent();
            $t->index('last_name');
        });
    }

    private function createCountry(): void
    {
        Schema::create($this->t('country'), function (Blueprint $t) {
            $t->smallIncrements('country_id');
            $t->string('country', 50)->comment('国家名');
            $t->timestamp('last_update')->useCurrent();
        });
    }

    private function createCity(): void
    {
        Schema::create($this->t('city'), function (Blueprint $t) {
            $t->smallIncrements('city_id');
            $t->string('city', 50)->comment('城市名');
            $t->unsignedSmallInteger('country_id')->comment('所属国家ID，对应country表');
            $t->timestamp('last_update')->useCurrent();
            $t->index('country_id');
        });
    }

    private function createAddress(): void
    {
        Schema::create($this->t('address'), function (Blueprint $t) {
            $t->smallIncrements('address_id');
            $t->string('address', 50)->comment('街道地址第一行');
            $t->string('address2', 50)->nullable()->comment('街道地址第二行(可选)');
            $t->string('district', 20)->comment('区/县');
            $t->unsignedSmallInteger('city_id')->comment('城市ID，对应city表');
            $t->string('postal_code', 10)->nullable()->comment('邮政编码');
            $t->string('phone', 20)->comment('联系电话');
            // location GEOMETRY 省略：pg 需 PostGIS，且原 schema 即 /*!50705 条件列*/，demo 不用。
            $t->timestamp('last_update')->useCurrent();
            $t->index('city_id');
        });
    }

    private function createFilm(): void
    {
        Schema::create($this->t('film'), function (Blueprint $t) {
            $t->smallIncrements('film_id');
            $t->string('title', 128)->comment('片名');
            $t->text('description')->nullable();
            $t->year('release_year')->nullable()->comment('上映年份');
            $t->unsignedTinyInteger('language_id')->comment('所属语言ID，对应language表');
            $t->unsignedTinyInteger('original_language_id')->nullable();
            $t->unsignedTinyInteger('rental_duration')->default(3)->comment('租赁周期(天)');
            $t->decimal('rental_rate', 4, 2)->default(4.99)->comment('基础租赁价格(美元)');
            $t->unsignedSmallInteger('length')->nullable()->comment('片长(分钟)');
            $t->decimal('replacement_cost', 5, 2)->default(19.99)->comment('损坏赔偿价格(美元)');
            $t->enum('rating', ['G', 'PG', 'PG-13', 'R', 'NC-17'])->default('G')
                ->comment('电影分级: G=大众, PG=儿童需家长指导, PG-13=13岁以下需家长指导, R=限制级, NC-17=17岁以下禁看');
            $t->string('special_features')->nullable(); // SET 无原生等价，存逗号串
            $t->timestamp('last_update')->useCurrent();
            $t->index('title');
            $t->index('language_id');
            $t->index('original_language_id');
        });
    }

    private function createFilmText(): void
    {
        Schema::create($this->t('film_text'), function (Blueprint $t) {
            $t->unsignedSmallInteger('film_id')->primary();
            $t->string('title', 255);
            $t->text('description')->nullable();
        });
    }

    private function createStaff(): void
    {
        Schema::create($this->t('staff'), function (Blueprint $t) {
            $t->tinyIncrements('staff_id');
            $t->string('first_name', 45)->comment('名');
            $t->string('last_name', 45)->comment('姓');
            $t->unsignedSmallInteger('address_id')->comment('地址ID，对应address表');
            $t->binary('picture')->nullable();
            $t->string('email', 50)->nullable()->comment('电子邮箱');
            $t->unsignedTinyInteger('store_id')->comment('所属门店ID，对应store表');
            $t->unsignedTinyInteger('active')->default(1)->comment('在职状态: 1=在职, 0=离职');
            $t->string('username', 16)->comment('登录用户名');
            $t->string('password', 40)->nullable();
            $t->timestamp('last_update')->useCurrent();
            $t->index('store_id');
            $t->index('address_id');
        });
    }

    private function createStore(): void
    {
        Schema::create($this->t('store'), function (Blueprint $t) {
            $t->tinyIncrements('store_id');
            $t->unsignedTinyInteger('manager_staff_id')->comment('店长员工ID，对应staff表');
            $t->unsignedSmallInteger('address_id')->comment('门店地址ID，对应address表');
            $t->timestamp('last_update')->useCurrent();
            $t->unique('manager_staff_id');
            $t->index('address_id');
        });
    }

    private function createCustomer(): void
    {
        Schema::create($this->t('customer'), function (Blueprint $t) {
            $t->smallIncrements('customer_id');
            $t->unsignedTinyInteger('store_id')->comment('所属门店ID，对应store表');
            $t->string('first_name', 45)->comment('名');
            $t->string('last_name', 45)->comment('姓');
            $t->string('email', 50)->nullable()->comment('电子邮箱');
            $t->unsignedSmallInteger('address_id')->comment('地址ID，对应address表');
            $t->unsignedTinyInteger('active')->default(1)->comment('是否活跃: 1=活跃, 0=已注销');
            $t->dateTime('create_date')->comment('注册时间');
            $t->timestamp('last_update')->useCurrent();
            $t->index('store_id');
            $t->index('address_id');
            $t->index('last_name');
        });
    }

    private function createFilmActor(): void
    {
        Schema::create($this->t('film_actor'), function (Blueprint $t) {
            $t->unsignedSmallInteger('actor_id');
            $t->unsignedSmallInteger('film_id');
            $t->timestamp('last_update')->useCurrent();
            $t->primary(['actor_id', 'film_id']);
            $t->index('film_id');
        });
    }

    private function createFilmCategory(): void
    {
        Schema::create($this->t('film_category'), function (Blueprint $t) {
            $t->unsignedSmallInteger('film_id');
            $t->unsignedTinyInteger('category_id');
            $t->timestamp('last_update')->useCurrent();
            $t->primary(['film_id', 'category_id']);
        });
    }

    private function createInventory(): void
    {
        Schema::create($this->t('inventory'), function (Blueprint $t) {
            $t->mediumIncrements('inventory_id');
            $t->unsignedSmallInteger('film_id')->comment('电影ID，对应film表');
            $t->unsignedTinyInteger('store_id')->comment('门店ID，对应store表');
            $t->timestamp('last_update')->useCurrent();
            $t->index('film_id');
            $t->index(['store_id', 'film_id']);
        });
    }

    private function createRental(): void
    {
        Schema::create($this->t('rental'), function (Blueprint $t) {
            $t->increments('rental_id');
            $t->dateTime('rental_date')->comment('租赁开始时间');
            $t->unsignedMediumInteger('inventory_id')->comment('库存ID，对应inventory表');
            $t->unsignedSmallInteger('customer_id')->comment('客户ID，对应customer表');
            $t->dateTime('return_date')->nullable()->comment('实际归还时间，NULL=尚未归还');
            $t->unsignedTinyInteger('staff_id')->comment('经手员工ID，对应staff表');
            $t->timestamp('last_update')->useCurrent();
            $t->unique(['rental_date', 'inventory_id', 'customer_id']);
            $t->index('inventory_id');
            $t->index('customer_id');
            $t->index('staff_id');
        });
    }

    private function createPayment(): void
    {
        Schema::create($this->t('payment'), function (Blueprint $t) {
            $t->smallIncrements('payment_id');
            $t->unsignedSmallInteger('customer_id')->comment('客户ID，对应customer表');
            $t->unsignedTinyInteger('staff_id')->comment('经手员工ID，对应staff表');
            $t->unsignedInteger('rental_id')->nullable()->comment('关联租赁记录ID，对应rental表');
            $t->decimal('amount', 5, 2)->comment('支付金额(美元)');
            $t->dateTime('payment_date')->comment('支付时间');
            $t->timestamp('last_update')->useCurrent();
            $t->index('staff_id');
            $t->index('customer_id');
        });
    }

    // ─── 外键（建表后单独添加，规避 store↔staff 循环顺序） ────────────────

    public function addForeignKeys(): void
    {
        $fks = [
            ['address',  'city_id',     'city',     'city_id',     'fk_address_city'],
            ['city',     'country_id',  'country',  'country_id',  'fk_city_country'],
            ['customer', 'store_id',    'store',    'store_id',    'fk_customer_store'],
            ['customer', 'address_id',  'address',  'address_id',  'fk_customer_address'],
            ['film',     'language_id', 'language', 'language_id', 'fk_film_language'],
            ['film',     'original_language_id', 'language', 'language_id', 'fk_film_language_original'],
            ['film_actor',    'actor_id', 'actor', 'actor_id', 'fk_film_actor_actor'],
            ['film_actor',    'film_id',  'film',  'film_id',  'fk_film_actor_film'],
            ['film_category', 'film_id',     'film',     'film_id',     'fk_film_category_film'],
            ['film_category', 'category_id', 'category', 'category_id', 'fk_film_category_category'],
            ['inventory', 'store_id', 'store', 'store_id', 'fk_inventory_store'],
            ['inventory', 'film_id',  'film',  'film_id',  'fk_inventory_film'],
            ['payment',   'rental_id',   'rental',   'rental_id',   'fk_payment_rental', 'set null'],
            ['payment',   'customer_id', 'customer', 'customer_id', 'fk_payment_customer'],
            ['payment',   'staff_id',    'staff',    'staff_id',    'fk_payment_staff'],
            ['rental',    'staff_id',    'staff',    'staff_id',    'fk_rental_staff'],
            ['rental',    'inventory_id','inventory','inventory_id','fk_rental_inventory'],
            ['rental',    'customer_id', 'customer', 'customer_id', 'fk_rental_customer'],
            ['staff',     'store_id',    'store',    'store_id',    'fk_staff_store'],
            ['staff',     'address_id',  'address',  'address_id',  'fk_staff_address'],
            ['store',     'manager_staff_id', 'staff', 'staff_id',   'fk_store_staff'],
            ['store',     'address_id',  'address',  'address_id',  'fk_store_address'],
        ];

        foreach ($fks as $fk) {
            [$table, $col, $refTable, $refCol, $name] = $fk;
            $onDelete = $fk[5] ?? 'restrict';
            Schema::table($this->t($table), function (Blueprint $t) use ($col, $refTable, $refCol, $name, $onDelete) {
                $t->foreign($col, $name)
                    ->references($refCol)->on($this->t($refTable))
                    ->onDelete($onDelete)->onUpdate('cascade');
            });
        }
    }
}
