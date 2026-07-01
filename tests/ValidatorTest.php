<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Exception\DashboardException;
use Qscmf\Chat2Viz\Validator\DashboardValidator;
use Qscmf\Chat2Viz\Validator\ConversationValidator;
use Qscmf\Chat2Viz\Traits\UuidTrait;

/**
 * @covers \Qscmf\Chat2Viz\Validator\DashboardValidator
 * @covers \Qscmf\Chat2Viz\Validator\ConversationValidator
 * @covers \Qscmf\Chat2Viz\Traits\UuidTrait::validateUuid
 */
class ValidatorTest extends TestCase
{
    // ─── UuidTrait::validateUuid ────────────────────────────────────────────

    private const UUID_VALID = '550e8400-e29b-41d4-a716-446655440000';
    private const UUID_VALID_UPPER = '550E8400-E29B-41D4-A716-446655440000';

    public function testValidateUuidAcceptsValidUuid(): void
    {
        // Use a concrete class that uses the trait
        $this->assertTrue(UuidTraitTester::validateUuid(self::UUID_VALID));
    }

    public function testValidateUuidAcceptsUppercaseUuid(): void
    {
        $this->assertTrue(UuidTraitTester::validateUuid(self::UUID_VALID_UPPER));
    }

    public function testValidateUuidRejectsInvalidString(): void
    {
        $this->assertFalse(UuidTraitTester::validateUuid('not-a-uuid'));
    }

    public function testValidateUuidRejectsEmptyString(): void
    {
        $this->assertFalse(UuidTraitTester::validateUuid(''));
    }

    public function testValidateUuidRejectsWrongVersion(): void
    {
        // Version 3 instead of 4 (third segment starts with 3, not 4)
        $this->assertFalse(UuidTraitTester::validateUuid('550e8400-e29b-31d4-a716-446655440000'));
    }

    // ─── DashboardValidator::validateCreate ────────────────────────────────

    public function testValidateCreateAcceptsMinimalInput(): void
    {
        DashboardValidator::validateCreate([]);
        $this->assertTrue(true); // No exception means pass
    }

    public function testValidateCreateAcceptsValidInput(): void
    {
        DashboardValidator::validateCreate([
            'title' => 'My Dashboard',
            'current_schema' => ['widgets' => []],
        ]);
        $this->assertTrue(true);
    }

    public function testValidateCreateRejectsLongTitle(): void
    {
        $this->expectException(DashboardException::class);
        DashboardValidator::validateCreate(['title' => str_repeat('x', 256)]);
    }

    public function testValidateCreateRejectsNonArraySchema(): void
    {
        $this->expectException(DashboardException::class);
        DashboardValidator::validateCreate(['current_schema' => 'not-array']);
    }

    public function testValidateCreateAllowsMaxTitleLength(): void
    {
        DashboardValidator::validateCreate(['title' => str_repeat('x', 255)]);
        $this->assertTrue(true);
    }

    // ─── DashboardValidator::validateUpdate ────────────────────────────────

    public function testValidateUpdateAcceptsEmptyInput(): void
    {
        DashboardValidator::validateUpdate([]);
        $this->assertTrue(true);
    }

    public function testValidateUpdateRejectsLongTitle(): void
    {
        $this->expectException(DashboardException::class);
        DashboardValidator::validateUpdate(['title' => str_repeat('x', 256)]);
    }

    public function testValidateUpdateRejectsOversizedSchema(): void
    {
        $this->expectException(DashboardException::class);
        DashboardValidator::validateUpdate([
            'current_schema' => ['data' => str_repeat('x', 70000)],
        ]);
    }

    public function testValidateUpdateRejectsInvalidDashboardStatus(): void
    {
        $this->expectException(DashboardException::class);
        DashboardValidator::validateUpdate(['dashboard_status' => 'invalid']);
    }

    public function testValidateUpdateAcceptsValidDashboardStatus(): void
    {
        foreach (['draft', 'published', 'archived'] as $status) {
            DashboardValidator::validateUpdate(['dashboard_status' => $status]);
        }
        $this->assertTrue(true);
    }

    public function testValidateUpdateRejectsNonArraySchema(): void
    {
        $this->expectException(DashboardException::class);
        DashboardValidator::validateUpdate(['current_schema' => 'string']);
    }

    // ─── DashboardValidator::validateSchemaSize ────────────────────────────

    public function testValidateSchemaSizeAcceptsSmallSchema(): void
    {
        DashboardValidator::validateSchemaSize(['key' => 'value']);
        $this->assertTrue(true);
    }

    public function testValidateSchemaSizeRejectsOversizedSchema(): void
    {
        $this->expectException(DashboardException::class);
        DashboardValidator::validateSchemaSize(['data' => str_repeat('x', 70000)]);
    }

    // ─── ConversationValidator::validateQuestion ───────────────────────────

    public function testValidateQuestionReturnsNullForValidInput(): void
    {
        $result = ConversationValidator::validateQuestion(
            ['question' => 'Show me sales data']
        );
        $this->assertNull($result);
    }

    public function testValidateQuestionRejectsNullInput(): void
    {
        $result = ConversationValidator::validateQuestion(null);
        $this->assertNotNull($result);
        $this->assertSame(0, $result['status']);
    }

    public function testValidateQuestionRejectsEmptyQuestion(): void
    {
        $result = ConversationValidator::validateQuestion(['question' => '']);
        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('问题', $result['info']);
    }

    public function testValidateQuestionRejectsLongQuestion(): void
    {
        $result = ConversationValidator::validateQuestion(
            ['question' => str_repeat('x', 1001)]
        );
        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('1000', $result['info']);
    }

    public function testValidateQuestionAcceptsMax1000Chars(): void
    {
        $result = ConversationValidator::validateQuestion(
            ['question' => str_repeat('x', 1000)]
        );
        $this->assertNull($result);
    }

    public function testValidateQuestionRejectsInvalidConversationId(): void
    {
        $result = ConversationValidator::validateQuestion([
            'question' => 'test',
            'conversation_id' => 'invalid!@#',
        ]);
        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('会话ID', $result['info']);
    }

    public function testValidateQuestionAcceptsValidConversationId(): void
    {
        $result = ConversationValidator::validateQuestion([
            'question' => 'test',
            'conversation_id' => 'abc123-def456',
        ]);
        $this->assertNull($result);
    }

    // ─── ConversationValidator::validateConversationCreate ────────────────

    public function testValidateConversationCreateReturnsNullForValidInput(): void
    {
        $result = ConversationValidator::validateConversationCreate([
            'dashboard_uid' => self::UUID_VALID,
        ]);
        $this->assertNull($result);
    }

    public function testValidateConversationCreateRejectsNullInput(): void
    {
        $result = ConversationValidator::validateConversationCreate(null);
        $this->assertSame(0, $result['status']);
    }

    public function testValidateConversationCreateRejectsMissingUid(): void
    {
        $result = ConversationValidator::validateConversationCreate([]);
        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('仪表盘ID', $result['info']);
    }

    public function testValidateConversationCreateRejectsInvalidUid(): void
    {
        $result = ConversationValidator::validateConversationCreate([
            'dashboard_uid' => 'not-a-uuid',
        ]);
        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('格式无效', $result['info']);
    }
}

/** Concrete class to test UuidTrait static method via trait composition. */
class UuidTraitTester
{
    use UuidTrait;
}
