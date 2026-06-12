<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Validator\ConversationValidator;

/**
 * @covers \Qscmf\Chat2Viz\Validator\ConversationValidator::validateQuestion
 */
class ValidateSocketInputTest extends TestCase
{
    private function invokeValidateSocketInput(?array $input): ?array
    {
        return ConversationValidator::validateQuestion($input);
    }

    // --- null input (non-JSON request) ---

    public function testNullInputReturnsFormatError(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'text/plain';
        $result = $this->invokeValidateSocketInput(null);
        $this->assertNotNull($result);
        $this->assertSame(0, $result['status']);
        $this->assertSame('请求格式不支持', $result['info']);
    }

    public function testNullInputWithJsonContentTypeReturnsParseError(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $result = $this->invokeValidateSocketInput(null);
        $this->assertNotNull($result);
        $this->assertSame(0, $result['status']);
        $this->assertSame('请求格式错误', $result['info']);
    }

    // --- empty question ---

    public function testEmptyQuestionReturnsError(): void
    {
        $result = $this->invokeValidateSocketInput(['question' => '']);
        $this->assertNotNull($result);
        $this->assertSame('请输入问题', $result['info']);
    }

    public function testWhitespaceOnlyQuestionReturnsError(): void
    {
        $result = $this->invokeValidateSocketInput(['question' => '   ']);
        $this->assertNotNull($result);
        $this->assertSame('请输入问题', $result['info']);
    }

    // --- question length limit ---

    public function testQuestionOverLimitReturnsError(): void
    {
        $result = $this->invokeValidateSocketInput(['question' => str_repeat('x', 1001)]);
        $this->assertNotNull($result);
        $this->assertSame('问题长度不能超过1000字', $result['info']);
    }

    public function testQuestionAtLimitPasses(): void
    {
        $result = $this->invokeValidateSocketInput(['question' => str_repeat('x', 1000)]);
        $this->assertNull($result);
    }

    // --- conversation_id validation ---

    public function testInvalidConversationIdReturnsError(): void
    {
        $result = $this->invokeValidateSocketInput([
            'question' => 'test',
            'conversation_id' => 'INVALID!@#',
        ]);
        $this->assertNotNull($result);
        $this->assertSame('无效的会话ID', $result['info']);
    }

    public function testValidConversationIdPasses(): void
    {
        $result = $this->invokeValidateSocketInput([
            'question' => 'test',
            'conversation_id' => 'abc123-def456',
        ]);
        $this->assertNull($result);
    }

    public function testNullConversationIdPasses(): void
    {
        $result = $this->invokeValidateSocketInput([
            'question' => 'test',
            'conversation_id' => null,
        ]);
        $this->assertNull($result);
    }

    // --- no serviceUrl check (M12: Socket mode doesn't need HTTP URL) ---

    public function testNoServiceUrlValidation(): void
    {
        // validateQuestion with requireServiceUrl=false should pass without serviceUrl.
        $result = $this->invokeValidateSocketInput(['question' => 'valid question']);
        $this->assertNull($result);
    }
}
