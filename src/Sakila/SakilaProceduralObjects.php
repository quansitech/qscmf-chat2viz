<?php

namespace Qscmf\Chat2Viz\Sakila;

use Illuminate\Support\Facades\DB;

/**
 * Sakila 过程化对象（视图 / 触发器 / 存储过程与函数）的跨库建/删。
 *
 * mysql 与 pg 的过程化 SQL 方言不兼容（GROUP_CONCAT/IF/DELIMITER/PROCEDURE
 *  vs STRING_AGG/CASE/FUNCTION $$），任何框架都无法用单一可移植语句表达，
 *  故按驱动分支。SQL 用 {bare_table} 占位，sql() 解析为物理表名（qs_xxx）——
 *  带花括号避免与 city.city 这类「列名=表名」冲突。
 *
 * 触发器在 film→film_text 同步；故 data 载入 film 后 film_text 自动填充。
 *
 * 注意：pg 的 PL/pgSQL 翻译为忠实改写，需在目标 pg 库执行验证（env-verify）。
 */
class SakilaProceduralObjects
{
    /** @var \Closure(string):string */
    public function __construct(private \Closure $resolver)
    {
    }

    private function t(string $bare): string
    {
        return ($this->resolver)($bare);
    }

    private function sql(string $template): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $this->t($m[1]), $template);
    }

    private function isPg(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    private function stmt(string $template): void
    {
        // 复合语句（CREATE TRIGGER/FUNCTION/PROCEDURE 的 BEGIN…END 体、pg 的 $$ 体）
        // 内含分号：DB::statement 走 prepare 路径，会在体内分号处截断报语法错；
        // 统一走 unprepared(exec) 把整段原样交给服务端按复合语句语法整体解析。
        // 简单 CREATE VIEW 经 exec 同样正确，无副作用。
        DB::unprepared($this->sql($template));
    }

    // ─── 对外入口 ────────────────────────────────────────────────────────

    public function up(): void
    {
        $this->createViews();
        $this->createTriggers();
        $this->createRoutines();
    }

    public function down(): void
    {
        $this->dropRoutines();
        $this->dropTriggers();
        $this->dropViews();
    }

    // ─── 视图（7） ────────────────────────────────────────────────────────

    public function createViews(): void
    {
        if ($this->isPg()) {
            $this->createViewsPg();
            return;
        }
        $this->createViewsMysql();
    }

    private function createViewsMysql(): void
    {
        $this->stmt(<<<SQL
CREATE VIEW {customer_list} AS
SELECT cu.customer_id AS ID, CONCAT(cu.first_name, _utf8mb4' ', cu.last_name) AS name,
       a.address AS address, a.postal_code AS `zip code`, a.phone AS phone,
       {city}.city AS city, {country}.country AS country,
       IF(cu.active, _utf8mb4'active', _utf8mb4'') AS notes, cu.store_id AS SID
FROM {customer} cu JOIN {address} a ON cu.address_id = a.address_id
     JOIN {city} ON a.city_id = {city}.city_id
     JOIN {country} ON {city}.country_id = {country}.country_id
SQL);

        $this->stmt(<<<SQL
CREATE VIEW {film_list} AS
SELECT f.film_id AS FID, f.title AS title, f.description AS description, c.name AS category,
       f.rental_rate AS price, f.length AS length, f.rating AS rating,
       GROUP_CONCAT(CONCAT(a.first_name, _utf8mb4' ', a.last_name) SEPARATOR ', ') AS actors
FROM {film} f
LEFT JOIN {film_category} fc ON f.film_id = fc.film_id
LEFT JOIN {category} c ON c.category_id = fc.category_id
LEFT JOIN {film_actor} fa ON f.film_id = fa.film_id
LEFT JOIN {actor} a ON fa.actor_id = a.actor_id
GROUP BY f.film_id, c.name
SQL);

        $this->stmt(<<<SQL
CREATE VIEW {staff_list} AS
SELECT s.staff_id AS ID, CONCAT(s.first_name, _utf8mb4' ', s.last_name) AS name,
       a.address AS address, a.postal_code AS `zip code`, a.phone AS phone,
       {city}.city AS city, {country}.country AS country, s.store_id AS SID
FROM {staff} s JOIN {address} a ON s.address_id = a.address_id
     JOIN {city} ON a.city_id = {city}.city_id
     JOIN {country} ON {city}.country_id = {country}.country_id
SQL);

        $this->stmt(<<<SQL
CREATE VIEW {sales_by_store} AS
SELECT CONCAT({city}.city, _utf8mb4',', {country}.country) AS store,
       CONCAT(m.first_name, _utf8mb4' ', m.last_name) AS manager,
       SUM(p.amount) AS total_sales
FROM {payment} p
JOIN {rental} r ON p.rental_id = r.rental_id
JOIN {inventory} i ON r.inventory_id = i.inventory_id
JOIN {store} s ON i.store_id = s.store_id
JOIN {address} a ON s.address_id = a.address_id
JOIN {city} ON a.city_id = {city}.city_id
JOIN {country} ON {city}.country_id = {country}.country_id
JOIN {staff} m ON s.manager_staff_id = m.staff_id
GROUP BY s.store_id
ORDER BY {country}.country, {city}.city
SQL);

        $this->stmt(<<<SQL
CREATE VIEW {sales_by_film_category} AS
SELECT c.name AS category, SUM(p.amount) AS total_sales
FROM {payment} p
JOIN {rental} r ON p.rental_id = r.rental_id
JOIN {inventory} i ON r.inventory_id = i.inventory_id
JOIN {film} f ON i.film_id = f.film_id
JOIN {film_category} fc ON f.film_id = fc.film_id
JOIN {category} c ON fc.category_id = c.category_id
GROUP BY c.name
ORDER BY total_sales DESC
SQL);

        $this->stmt(<<<SQL
CREATE VIEW {nicer_but_slower_film_list} AS
SELECT f.film_id AS FID, f.title AS title, f.description AS description, c.name AS category,
       f.rental_rate AS price, f.length AS length, f.rating AS rating,
       GROUP_CONCAT(CONCAT(CONCAT(UCASE(SUBSTR(a.first_name,1,1)),
              LCASE(SUBSTR(a.first_name,2,CHAR_LENGTH(a.first_name)))), _utf8mb4' ',
              CONCAT(UCASE(SUBSTR(a.last_name,1,1)),
              LCASE(SUBSTR(a.last_name,2,CHAR_LENGTH(a.last_name))))) SEPARATOR ', ') AS actors
FROM {film} f
LEFT JOIN {film_category} fc ON f.film_id = fc.film_id
LEFT JOIN {category} c ON c.category_id = fc.category_id
LEFT JOIN {film_actor} fa ON f.film_id = fa.film_id
LEFT JOIN {actor} a ON fa.actor_id = a.actor_id
GROUP BY f.film_id, c.name
SQL);

        $this->stmt(<<<SQL
CREATE VIEW {actor_info} AS
SELECT a.actor_id, a.first_name, a.last_name,
       GROUP_CONCAT(DISTINCT CONCAT(c.name, _utf8mb4': ',
           (SELECT GROUP_CONCAT(f.title ORDER BY f.title SEPARATOR ', ')
            FROM {film} f
            JOIN {film_category} fc ON f.film_id = fc.film_id
            JOIN {film_actor} fa ON f.film_id = fa.film_id
            WHERE fc.category_id = c.category_id AND fa.actor_id = a.actor_id)
       ) ORDER BY c.name SEPARATOR '; ') AS film_info
FROM {actor} a
LEFT JOIN {film_actor} fa ON a.actor_id = fa.actor_id
LEFT JOIN {film_category} fc ON fa.film_id = fc.film_id
LEFT JOIN {category} c ON fc.category_id = c.category_id
GROUP BY a.actor_id, a.first_name, a.last_name
SQL);
    }

    private function createViewsPg(): void
    {
        $this->stmt(<<<SQL
CREATE VIEW {customer_list} AS
SELECT cu.customer_id AS id, (cu.first_name || ' ' || cu.last_name) AS name,
       a.address AS address, a.postal_code AS "zip code", a.phone AS phone,
       ci.city AS city, co.country AS country,
       -- active 列为 smallint(0/1)，pg 不隐式 int→bool，须显式比较。
       CASE WHEN cu.active <> 0 THEN 'active' ELSE '' END AS notes, cu.store_id AS sid
FROM {customer} cu JOIN {address} a ON cu.address_id = a.address_id
     JOIN {city} ci ON a.city_id = ci.city_id
     JOIN {country} co ON ci.country_id = co.country_id
SQL);

        $this->stmt(<<<SQL
CREATE VIEW {film_list} AS
SELECT f.film_id AS fid, f.title AS title, f.description AS description, c.name AS category,
       f.rental_rate AS price, f.length AS length, f.rating AS rating,
       STRING_AGG((a.first_name || ' ' || a.last_name), ', ') AS actors
FROM {film} f
LEFT JOIN {film_category} fc ON f.film_id = fc.film_id
LEFT JOIN {category} c ON c.category_id = fc.category_id
LEFT JOIN {film_actor} fa ON f.film_id = fa.film_id
LEFT JOIN {actor} a ON fa.actor_id = a.actor_id
GROUP BY f.film_id, c.name
SQL);

        $this->stmt(<<<SQL
CREATE VIEW {staff_list} AS
SELECT s.staff_id AS id, (s.first_name || ' ' || s.last_name) AS name,
       a.address AS address, a.postal_code AS "zip code", a.phone AS phone,
       ci.city AS city, co.country AS country, s.store_id AS sid
FROM {staff} s JOIN {address} a ON s.address_id = a.address_id
     JOIN {city} ci ON a.city_id = ci.city_id
     JOIN {country} co ON ci.country_id = co.country_id
SQL);

        $this->stmt(<<<SQL
CREATE VIEW {sales_by_store} AS
SELECT (ci.city || ',' || co.country) AS store,
       (m.first_name || ' ' || m.last_name) AS manager,
       SUM(p.amount) AS total_sales
FROM {payment} p
JOIN {rental} r ON p.rental_id = r.rental_id
JOIN {inventory} i ON r.inventory_id = i.inventory_id
JOIN {store} s ON i.store_id = s.store_id
JOIN {address} a ON s.address_id = a.address_id
JOIN {city} ci ON a.city_id = ci.city_id
JOIN {country} co ON ci.country_id = co.country_id
JOIN {staff} m ON s.manager_staff_id = m.staff_id
GROUP BY s.store_id, ci.city, co.country, m.first_name, m.last_name
ORDER BY co.country, ci.city
SQL);

        $this->stmt(<<<SQL
CREATE VIEW {sales_by_film_category} AS
SELECT c.name AS category, SUM(p.amount) AS total_sales
FROM {payment} p
JOIN {rental} r ON p.rental_id = r.rental_id
JOIN {inventory} i ON r.inventory_id = i.inventory_id
JOIN {film} f ON i.film_id = f.film_id
JOIN {film_category} fc ON f.film_id = fc.film_id
JOIN {category} c ON fc.category_id = c.category_id
GROUP BY c.name
ORDER BY total_sales DESC
SQL);

        $this->stmt(<<<SQL
CREATE VIEW {nicer_but_slower_film_list} AS
SELECT f.film_id AS fid, f.title, f.description, c.name AS category, f.rental_rate AS price,
       f.length, f.rating,
       STRING_AGG(upper(substr(a.first_name,1,1)) || lower(substr(a.first_name,2,length(a.first_name))) || ' ' ||
                  upper(substr(a.last_name,1,1)) || lower(substr(a.last_name,2,length(a.last_name))), ', ') AS actors
FROM {film} f
LEFT JOIN {film_category} fc ON f.film_id = fc.film_id
LEFT JOIN {category} c ON c.category_id = fc.category_id
LEFT JOIN {film_actor} fa ON f.film_id = fa.film_id
LEFT JOIN {actor} a ON fa.actor_id = a.actor_id
GROUP BY f.film_id, c.name
SQL);

        // actor_info：pg 的 STRING_AGG(DISTINCT … ORDER BY c.name) 会触发
        // 「ORDER BY 须出现在 DISTINCT 参数中」限制，故先在子查询去重再聚合。
        $this->stmt(<<<SQL
CREATE VIEW {actor_info} AS
SELECT a.actor_id, a.first_name, a.last_name,
       (SELECT STRING_AGG(ci.entry, '; ' ORDER BY ci.entry)
        FROM (
            SELECT DISTINCT (c2.name || ': ' || COALESCE((
                SELECT STRING_AGG(f.title, ', ' ORDER BY f.title)
                FROM {film} f
                JOIN {film_category} fc ON f.film_id = fc.film_id
                JOIN {film_actor} fa ON f.film_id = fa.film_id
                WHERE fc.category_id = c2.category_id AND fa.actor_id = a.actor_id
            ), '')) AS entry
            FROM {category} c2
            JOIN {film_category} fc2 ON fc2.category_id = c2.category_id
            JOIN {film_actor} fa2 ON fa2.film_id = fc2.film_id AND fa2.actor_id = a.actor_id
        ) ci
       ) AS film_info
FROM {actor} a
SQL);
    }

    public function dropViews(): void
    {
        foreach (['actor_info', 'nicer_but_slower_film_list', 'sales_by_film_category', 'sales_by_store', 'staff_list', 'film_list', 'customer_list'] as $v) {
            DB::statement('DROP VIEW IF EXISTS ' . $this->t($v));
        }
    }

    // ─── 触发器（3：film → film_text 同步） ────────────────────────────────

    public function createTriggers(): void
    {
        if ($this->isPg()) {
            $this->createTriggersPg();
            return;
        }
        // mysql：无需 DELIMITER（那是客户端概念），每条 CREATE TRIGGER 单独执行。
        $this->stmt(<<<SQL
CREATE TRIGGER ins_film AFTER INSERT ON {film} FOR EACH ROW
BEGIN
    INSERT INTO {film_text} (film_id, title, description) VALUES (NEW.film_id, NEW.title, NEW.description);
END
SQL);
        $this->stmt(<<<SQL
CREATE TRIGGER upd_film AFTER UPDATE ON {film} FOR EACH ROW
BEGIN
    IF (OLD.title <> NEW.title) OR (OLD.description <> NEW.description) OR (OLD.film_id <> NEW.film_id) THEN
        UPDATE {film_text} SET title = NEW.title, description = NEW.description, film_id = NEW.film_id
        WHERE film_id = OLD.film_id;
    END IF;
END
SQL);
        $this->stmt(<<<SQL
CREATE TRIGGER del_film AFTER DELETE ON {film} FOR EACH ROW
BEGIN
    DELETE FROM {film_text} WHERE film_id = OLD.film_id;
END
SQL);
    }

    private function createTriggersPg(): void
    {
        $this->stmt(<<<SQL
CREATE FUNCTION {sakila_ins_film}() RETURNS TRIGGER AS $$
BEGIN
    INSERT INTO {film_text} (film_id, title, description) VALUES (NEW.film_id, NEW.title, NEW.description);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        $this->stmt('CREATE TRIGGER ins_film AFTER INSERT ON {film} FOR EACH ROW EXECUTE FUNCTION {sakila_ins_film}()');

        $this->stmt(<<<SQL
CREATE FUNCTION {sakila_upd_film}() RETURNS TRIGGER AS $$
BEGIN
    IF (OLD.title IS DISTINCT FROM NEW.title) OR (OLD.description IS DISTINCT FROM NEW.description) OR (OLD.film_id <> NEW.film_id) THEN
        UPDATE {film_text} SET title = NEW.title, description = NEW.description, film_id = NEW.film_id
        WHERE film_id = OLD.film_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        $this->stmt('CREATE TRIGGER upd_film AFTER UPDATE ON {film} FOR EACH ROW EXECUTE FUNCTION {sakila_upd_film}()');

        $this->stmt(<<<SQL
CREATE FUNCTION {sakila_del_film}() RETURNS TRIGGER AS $$
BEGIN
    DELETE FROM {film_text} WHERE film_id = OLD.film_id;
    RETURN OLD;
END;
$$ LANGUAGE plpgsql
SQL);
        $this->stmt('CREATE TRIGGER del_film AFTER DELETE ON {film} FOR EACH ROW EXECUTE FUNCTION {sakila_del_film}()');
    }

    public function dropTriggers(): void
    {
        if ($this->isPg()) {
            foreach (['ins_film', 'upd_film', 'del_film'] as $tr) {
                DB::statement('DROP TRIGGER IF EXISTS ' . $tr . ' ON ' . $this->t('film'));
            }
            foreach (['sakila_del_film', 'sakila_upd_film', 'sakila_ins_film'] as $fn) {
                DB::statement('DROP FUNCTION IF EXISTS ' . $this->t($fn) . '()');
            }
            return;
        }
        foreach (['ins_film', 'upd_film', 'del_film'] as $tr) {
            DB::statement('DROP TRIGGER IF EXISTS ' . $tr);
        }
    }

    // ─── 存储过程 / 函数（6） ─────────────────────────────────────────────
    // 忠实改写：rewards_report / get_customer_balance / film_in_stock /
    // film_not_in_stock / inventory_held_by_customer / inventory_in_stock。
    // 这些不被任何 NL2SQL demo 调用，仅为 Sakila 完整性保留。

    public function createRoutines(): void
    {
        if ($this->isPg()) {
            $this->createRoutinesPg();
            return;
        }
        $this->createRoutinesMysql();
    }

    private function createRoutinesMysql(): void
    {
        // inventory_in_stock（被 film_in_stock / film_not_in_stock 依赖，先建）
        $this->stmt(<<<SQL
CREATE FUNCTION inventory_in_stock(p_inventory_id INT) RETURNS BOOLEAN
READS SQL DATA
BEGIN
    DECLARE v_rentals INT;
    DECLARE v_out INT;
    SELECT COUNT(*) INTO v_rentals FROM {rental} WHERE inventory_id = p_inventory_id;
    IF v_rentals = 0 THEN RETURN TRUE; END IF;
    SELECT COUNT(r.rental_id) INTO v_out
    FROM {inventory} i LEFT JOIN {rental} r USING (inventory_id)
    WHERE i.inventory_id = p_inventory_id AND r.return_date IS NULL;
    IF v_out > 0 THEN RETURN FALSE; ELSE RETURN TRUE; END IF;
END
SQL);

        $this->stmt(<<<SQL
CREATE FUNCTION inventory_held_by_customer(p_inventory_id INT) RETURNS INT
READS SQL DATA
BEGIN
    DECLARE v_customer_id INT;
    DECLARE EXIT HANDLER FOR NOT FOUND RETURN NULL;
    SELECT customer_id INTO v_customer_id FROM {rental}
    WHERE return_date IS NULL AND inventory_id = p_inventory_id;
    RETURN v_customer_id;
END
SQL);

        $this->stmt(<<<SQL
CREATE FUNCTION get_customer_balance(p_customer_id INT, p_effective_date DATETIME) RETURNS DECIMAL(5,2)
DETERMINISTIC READS SQL DATA
BEGIN
    DECLARE v_rentfees DECIMAL(5,2);
    DECLARE v_overfees INT;
    DECLARE v_payments DECIMAL(5,2);
    SELECT IFNULL(SUM(f.rental_rate), 0) INTO v_rentfees
    FROM {film} f, {inventory} i, {rental} r
    WHERE f.film_id = i.film_id AND i.inventory_id = r.inventory_id
      AND r.rental_date <= p_effective_date AND r.customer_id = p_customer_id;
    SELECT IFNULL(SUM(IF((TO_DAYS(r.return_date) - TO_DAYS(r.rental_date)) > f.rental_duration,
        ((TO_DAYS(r.return_date) - TO_DAYS(r.rental_date)) - f.rental_duration), 0)), 0) INTO v_overfees
    FROM {rental} r, {inventory} i, {film} f
    WHERE f.film_id = i.film_id AND i.inventory_id = r.inventory_id
      AND r.rental_date <= p_effective_date AND r.customer_id = p_customer_id;
    SELECT IFNULL(SUM(p.amount), 0) INTO v_payments
    FROM {payment} p
    WHERE p.payment_date <= p_effective_date AND p.customer_id = p_customer_id;
    RETURN v_rentfees + v_overfees - v_payments;
END
SQL);

        $this->stmt(<<<SQL
CREATE PROCEDURE film_in_stock(IN p_film_id INT, IN p_store_id INT, OUT p_film_count INT)
READS SQL DATA
BEGIN
    SELECT inventory_id FROM {inventory}
    WHERE film_id = p_film_id AND store_id = p_store_id AND inventory_in_stock(inventory_id);
    SELECT COUNT(*) INTO p_film_count FROM {inventory}
    WHERE film_id = p_film_id AND store_id = p_store_id AND inventory_in_stock(inventory_id);
END
SQL);

        $this->stmt(<<<SQL
CREATE PROCEDURE film_not_in_stock(IN p_film_id INT, IN p_store_id INT, OUT p_film_count INT)
READS SQL DATA
BEGIN
    SELECT inventory_id FROM {inventory}
    WHERE film_id = p_film_id AND store_id = p_store_id AND NOT inventory_in_stock(inventory_id);
    SELECT COUNT(*) INTO p_film_count FROM {inventory}
    WHERE film_id = p_film_id AND store_id = p_store_id AND NOT inventory_in_stock(inventory_id);
END
SQL);

        $this->stmt(<<<SQL
CREATE PROCEDURE rewards_report(
    IN min_monthly_purchases TINYINT UNSIGNED,
    IN min_dollar_amount_purchased DECIMAL(10,2),
    OUT count_rewardees INT)
READS SQL DATA
BEGIN
    DECLARE last_month_start DATE;
    DECLARE last_month_end DATE;
    IF min_monthly_purchases = 0 THEN SELECT 'Minimum monthly purchases parameter must be > 0'; END IF;
    IF min_dollar_amount_purchased = 0.00 THEN SELECT 'Minimum monthly dollar amount purchased parameter must be > $0.00'; END IF;
    SET last_month_start = DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH);
    SET last_month_start = STR_TO_DATE(CONCAT(YEAR(last_month_start),'-',MONTH(last_month_start),'-01'),'%Y-%m-%d');
    SET last_month_end = LAST_DAY(last_month_start);
    SET count_rewardees = 0;
    SELECT COUNT(*) INTO count_rewardees
    FROM (SELECT p.customer_id FROM {payment} p
          WHERE DATE(p.payment_date) BETWEEN last_month_start AND last_month_end
          GROUP BY p.customer_id
          HAVING SUM(p.amount) > min_dollar_amount_purchased
             AND COUNT(p.customer_id) > min_monthly_purchases) AS rew;
END
SQL);
    }

    private function createRoutinesPg(): void
    {
        // pg：procedure OUT 参数 + RETURNS。返回集用 RETURNS TABLE。
        $this->stmt(<<<SQL
CREATE FUNCTION inventory_in_stock(p_inventory_id INTEGER) RETURNS BOOLEAN
LANGUAGE plpgsql AS $$
DECLARE v_rentals INTEGER; v_out INTEGER;
BEGIN
    SELECT COUNT(*) INTO v_rentals FROM {rental} WHERE inventory_id = p_inventory_id;
    IF v_rentals = 0 THEN RETURN TRUE; END IF;
    SELECT COUNT(r.rental_id) INTO v_out
    FROM {inventory} i LEFT JOIN {rental} r ON r.inventory_id = i.inventory_id
    WHERE i.inventory_id = p_inventory_id AND r.return_date IS NULL;
    RETURN v_out = 0;
END;
$$
SQL);

        $this->stmt(<<<SQL
CREATE FUNCTION inventory_held_by_customer(p_inventory_id INTEGER) RETURNS INTEGER
LANGUAGE plpgsql AS $$
DECLARE v_customer_id INTEGER;
BEGIN
    SELECT customer_id INTO v_customer_id FROM {rental}
    WHERE return_date IS NULL AND inventory_id = p_inventory_id;
    IF NOT FOUND THEN RETURN NULL; END IF;
    RETURN v_customer_id;
END;
$$
SQL);

        $this->stmt(<<<SQL
CREATE FUNCTION get_customer_balance(p_customer_id INTEGER, p_effective_date TIMESTAMP) RETURNS NUMERIC(5,2)
LANGUAGE plpgsql AS $$
DECLARE v_rentfees NUMERIC(5,2); v_overfees NUMERIC := 0; v_payments NUMERIC(5,2);
BEGIN
    SELECT COALESCE(SUM(f.rental_rate), 0) INTO v_rentfees
    FROM {film} f JOIN {inventory} i ON f.film_id = i.film_id
         JOIN {rental} r ON i.inventory_id = r.inventory_id
    WHERE r.rental_date <= p_effective_date AND r.customer_id = p_customer_id;
    SELECT COALESCE(SUM(CASE WHEN (r.return_date - r.rental_date) > f.rental_duration
        THEN ((r.return_date - r.rental_date) - f.rental_duration) ELSE 0 END), 0) INTO v_overfees
    FROM {rental} r JOIN {inventory} i ON i.inventory_id = r.inventory_id
         JOIN {film} f ON f.film_id = i.film_id
    WHERE r.rental_date <= p_effective_date AND r.customer_id = p_customer_id;
    SELECT COALESCE(SUM(p.amount), 0) INTO v_payments
    FROM {payment} p
    WHERE p.payment_date <= p_effective_date AND p.customer_id = p_customer_id;
    RETURN v_rentfees + v_overfees - v_payments;
END;
$$
SQL);

        // pg 用 RETURNS TABLE 表达返回集 + OUT 计数（合并为单结果集列）
        $this->stmt(<<<SQL
CREATE FUNCTION film_in_stock(p_film_id INTEGER, p_store_id INTEGER, OUT p_film_count INTEGER)
RETURNS SETOF INTEGER
LANGUAGE plpgsql AS $$
BEGIN
    p_film_count := 0;
    RETURN QUERY SELECT i.inventory_id FROM {inventory} i
        WHERE i.film_id = p_film_id AND i.store_id = p_store_id AND inventory_in_stock(i.inventory_id);
    SELECT COUNT(*) INTO p_film_count FROM {inventory}
    WHERE film_id = p_film_id AND store_id = p_store_id AND inventory_in_stock(inventory_id);
    RETURN;
END;
$$
SQL);

        $this->stmt(<<<SQL
CREATE FUNCTION film_not_in_stock(p_film_id INTEGER, p_store_id INTEGER, OUT p_film_count INTEGER)
RETURNS SETOF INTEGER
LANGUAGE plpgsql AS $$
BEGIN
    p_film_count := 0;
    RETURN QUERY SELECT i.inventory_id FROM {inventory} i
        WHERE i.film_id = p_film_id AND i.store_id = p_store_id AND NOT inventory_in_stock(i.inventory_id);
    SELECT COUNT(*) INTO p_film_count FROM {inventory}
    WHERE film_id = p_film_id AND store_id = p_store_id AND NOT inventory_in_stock(inventory_id);
    RETURN;
END;
$$
SQL);

        $this->stmt(<<<SQL
CREATE FUNCTION rewards_report(
    min_monthly_purchases SMALLINT,
    min_dollar_amount_purchased NUMERIC(10,2),
    OUT count_rewardees INTEGER)
RETURNS SETOF INTEGER
LANGUAGE plpgsql AS $$
DECLARE last_month_start DATE; last_month_end DATE;
BEGIN
    count_rewardees := 0;
    last_month_start := date_trunc('month', CURRENT_DATE - INTERVAL '1 month')::date;
    last_month_end := (date_trunc('month', CURRENT_DATE) - INTERVAL '1 day')::date;
    RETURN QUERY SELECT p.customer_id FROM {payment} p
        WHERE p.payment_date::date BETWEEN last_month_start AND last_month_end
        GROUP BY p.customer_id
        HAVING SUM(p.amount) > min_dollar_amount_purchased
           AND COUNT(*) > min_monthly_purchases;
    GET DIAGNOSTICS count_rewardees = ROW_COUNT;
    RETURN;
END;
$$
SQL);
    }

    public function dropRoutines(): void
    {
        // 依赖序：先 film_* / rewards_report（依赖 inventory_in_stock），后 inventory_in_stock。
        // 注意 DROP 不校验函数体引用，顺序其实无关，保留仅为可读。
        $order = [
            'rewards_report',
            'film_not_in_stock',
            'film_in_stock',
            'get_customer_balance',
            'inventory_held_by_customer',
            'inventory_in_stock',
        ];
        // mysql 中 film_in_stock / film_not_in_stock / rewards_report 是 PROCEDURE，
        // 其余是 FUNCTION；且 DROP 不带参数列表（mysql 语法）。
        $procedures = ['film_in_stock', 'film_not_in_stock', 'rewards_report'];

        if ($this->isPg()) {
            // pg 全为 FUNCTION；名称唯一即可不带签名。
            foreach ($order as $n) {
                DB::statement('DROP FUNCTION IF EXISTS ' . $n);
            }
            return;
        }
        foreach ($order as $n) {
            $kind = in_array($n, $procedures, true) ? 'PROCEDURE' : 'FUNCTION';
            DB::statement('DROP ' . $kind . ' IF EXISTS ' . $n);
        }
    }
}
