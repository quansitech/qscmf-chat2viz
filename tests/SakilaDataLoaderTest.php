<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Sakila\SakilaDataLoader;

/**
 * @covers \Qscmf\Chat2Viz\Sakila\SakilaDataLoader::transform
 *
 * 纯函数测试（无 DB）：覆盖噪声剥离、GEOMETRY 条件注释删除、表名前缀注入、
 * 以及 mysql/pg 的 BLOB hex 差异。这是 Sakila 跨库可移植性的可单测核心。
 */
class SakilaDataLoaderTest extends TestCase
{
    private function sampleSql(): string
    {
        return <<<'SQL'
-- Sakila Sample Database Data
-- Copyright (c) Oracle
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL';
USE sakila;
LOCK TABLES `actor` WRITE;
INSERT INTO `actor` VALUES (1,'PENELOPE','GUINESS','2006-02-15 04:34:33'),(2,'NICK','WAHLBERG','2006-02-15 04:34:33');
UNLOCK TABLES;
INSERT INTO `address` VALUES (1,'47 MySakila Drive',NULL,'Alberta',300,'','',/*!50705 0x0000000001010000003E0A325D63345CC0761FDB8D99D94840,*/'2014-09-25 22:30:27');
INSERT INTO `staff` VALUES (1,'Mike','Hillyer',3,0x89504E470D0A,'Mike.Hillyer@sakilastaff.com',1,1,'Mike','8cb2237d0679ca88db6464','2006-02-15 03:57:16');
COMMIT;
SET SQL_MODE=@OLD_SQL_MODE;
SQL;
    }

    private function qsResolver(): \Closure
    {
        return static fn (string $bare): string => 'qs_' . $bare;
    }

    public function testStripsLicenseAndSessionNoiseKeepsOnlyInserts(): void
    {
        $out = SakilaDataLoader::transform($this->sampleSql(), $this->qsResolver(), false);

        $this->assertStringNotContainsString('Copyright', $out);
        $this->assertStringNotContainsString('SET @OLD_SQL_MODE', $out);
        $this->assertStringNotContainsString('USE sakila', $out);
        $this->assertStringNotContainsString('LOCK TABLES', $out);
        $this->assertStringNotContainsString('UNLOCK TABLES', $out);
        $this->assertStringNotContainsString('COMMIT', $out);
        $this->assertStringContainsString('INSERT INTO', $out);
    }

    public function testStripsGeometryConditionalLeavingEightColumns(): void
    {
        $out = SakilaDataLoader::transform($this->sampleSql(), $this->qsResolver(), false);

        // GEOMETRY WKB hex 整块删除（含其尾随逗号）
        $this->assertStringNotContainsString('0x0000000001010000', $out);
        $this->assertStringNotContainsString('/*!50705', $out);
        // 8 列对齐：city_id 300 → postal '' → phone '' → last_update
        $this->assertStringContainsString(
            "300,'','','2014-09-25 22:30:27'",
            $out,
            'address 行应剩 8 列（location 列与其分隔逗号一并随条件注释删除）'
        );
    }

    public function testPrefixesTableNamesViaResolver(): void
    {
        $out = SakilaDataLoader::transform($this->sampleSql(), $this->qsResolver(), false);
        $this->assertStringContainsString('INSERT INTO qs_actor', $out);
        $this->assertStringContainsString('INSERT INTO qs_address', $out);
        $this->assertStringContainsString('INSERT INTO qs_staff', $out);
    }

    public function testPrefixesNonBacktickedInserts(): void
    {
        // 官方 sakila-data.sql 多数 INSERT 不带反引号（如 actor）。
        $raw = "INSERT INTO actor VALUES (1,'PENELOPE','GUINESS','2006-02-15 04:34:33');\n";
        $out = SakilaDataLoader::transform($raw, $this->qsResolver(), false);
        $this->assertStringContainsString('INSERT INTO qs_actor VALUES', $out);
        $this->assertStringNotContainsString('INSERT INTO actor ', $out);
    }

    public function testResolverOverrideHonored(): void
    {
        $custom = static fn (string $bare): string => 't_' . $bare;
        $out = SakilaDataLoader::transform($this->sampleSql(), $custom, false);
        $this->assertStringContainsString('INSERT INTO t_actor', $out);
        $this->assertStringNotContainsString('qs_actor', $out);
    }

    public function testMysqlKeepsBlobHexLiteral(): void
    {
        $out = SakilaDataLoader::transform($this->sampleSql(), $this->qsResolver(), false);
        $this->assertStringContainsString('0x89504E470D0A', $out, 'mysql 应原样保留 staff.picture 的 BLOB hex');
    }

    public function testPgRewritesBlobHexToByteaLiteral(): void
    {
        $out = SakilaDataLoader::transform($this->sampleSql(), $this->qsResolver(), true);
        $this->assertStringNotContainsString('0x89504E470D0A', $out);
        $this->assertStringContainsString("'\\x89504E470D0A'", $out, 'pg 应改写为 bytea hex 字面量 \xHEX');
    }
}
