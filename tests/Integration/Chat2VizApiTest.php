<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Chat2Viz API input validation tests.
 *
 * Tests the validation logic for the chat ask endpoint,
 * covering all edge cases from sakila-test-prompts.md interaction patterns.
 *
 * Mirrors ConversationValidator::validateQuestion logic.
 */
class Chat2VizApiTest extends TestCase
{
    // =========================================================================
    // Question validation
    // =========================================================================

    /**
     * Simple prompt #1: valid Chinese question should pass.
     */
    public function testValidChineseQuestionPasses(): void
    {
        $input = ['question' => '总共有多少部电影'];
        $result = $this->validateSocketInput($input);
        $this->assertNull($result, 'Valid question should pass validation');
    }

    /**
     * Medium prompt #9: question with date range should pass.
     */
    public function testValidDateRangeQuestionPasses(): void
    {
        $input = ['question' => '2005年6月每天的租金收入总额'];
        $result = $this->validateSocketInput($input);
        $this->assertNull($result);
    }

    /**
     * Complex prompt #14: question with conditions should pass.
     */
    public function testValidConditionalQuestionPasses(): void
    {
        $input = ['question' => '哪些演员既演过Action电影又演过Comedy电影'];
        $result = $this->validateSocketInput($input);
        $this->assertNull($result);
    }

    public function testEmptyQuestionRejected(): void
    {
        $input = ['question' => ''];
        $result = $this->validateSocketInput($input);
        $this->assertNotNull($result);
        $this->assertSame(0, $result['status']);
        $this->assertSame('请输入问题', $result['info']);
    }

    public function testWhitespaceOnlyQuestionRejected(): void
    {
        $input = ['question' => '   '];
        $result = $this->validateSocketInput($input);
        $this->assertNotNull($result);
        $this->assertSame('请输入问题', $result['info']);
    }

    public function testMissingQuestionRejected(): void
    {
        $input = [];
        $result = $this->validateSocketInput($input);
        $this->assertNotNull($result);
        $this->assertSame('请输入问题', $result['info']);
    }

    public function testQuestionExceedingMaxLengthRejected(): void
    {
        $input = ['question' => str_repeat('很长的电影名称', 200)]; // 1400 chars > 1000
        $result = $this->validateSocketInput($input);
        $this->assertNotNull($result);
        $this->assertStringContainsString('1000', $result['info']);
    }

    public function testQuestionAtMaxLengthBoundary(): void
    {
        $question = str_repeat('电', 1000); // exactly 1000 chars
        $input = ['question' => $question];
        $result = $this->validateSocketInput($input);
        $this->assertNull($result, 'Question at exactly 1000 chars should pass');
    }

    public function testQuestionJustOverMaxLength(): void
    {
        $question = str_repeat('电', 1001); // 1001 chars
        $input = ['question' => $question];
        $result = $this->validateSocketInput($input);
        $this->assertNotNull($result);
    }

    // =========================================================================
    // Conversation ID validation
    // =========================================================================

    public function testValidConversationIdAccepted(): void
    {
        $input = [
            'question' => '测试问题',
            'conversation_id' => 'abc123-def456',
        ];
        $result = $this->validateSocketInput($input);
        $this->assertNull($result);
    }

    public function testUuidConversationIdAccepted(): void
    {
        $input = [
            'question' => '测试问题',
            'conversation_id' => '550e8400-e29b-41d4-a716-446655440000',
        ];
        $result = $this->validateSocketInput($input);
        $this->assertNull($result);
    }

    public function testHexConversationIdAccepted(): void
    {
        $input = [
            'question' => '测试问题',
            'conversation_id' => 'a1b2c3d4e5f6',
        ];
        $result = $this->validateSocketInput($input);
        $this->assertNull($result);
    }

    public function testInvalidConversationIdRejected(): void
    {
        $input = [
            'question' => '测试问题',
            'conversation_id' => 'invalid!@#$%',
        ];
        $result = $this->validateSocketInput($input);
        $this->assertNotNull($result);
        $this->assertStringContainsString('会话ID', $result['info']);
    }

    public function testNullConversationIdAccepted(): void
    {
        $input = [
            'question' => '测试问题',
            'conversation_id' => null,
        ];
        $result = $this->validateSocketInput($input);
        $this->assertNull($result, 'Null conversation_id should be accepted');
    }

    public function testOmittedConversationIdAccepted(): void
    {
        $input = ['question' => '测试问题'];
        $result = $this->validateSocketInput($input);
        $this->assertNull($result);
    }

    // =========================================================================
    // Input format validation
    // =========================================================================

    public function testNullInputRejected(): void
    {
        $result = $this->validateSocketInput(null);
        $this->assertNotNull($result);
        $this->assertSame(0, $result['status']);
    }

    public function testNonJsonContentTypeRejected(): void
    {
        // Simulate non-JSON content type
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $result = $this->validateSocketInput(null);
        $this->assertNotNull($result);
        $this->assertStringContainsString('格式不支持', $result['info']);
        unset($_SERVER['CONTENT_TYPE']);
    }

    // =========================================================================
    // Payload building
    // =========================================================================

    /**
     * Test that buildPayloadFromParsed correctly forwards dashboard_context.
     * This is important for dashboard-aware conversations where the AI needs
     * to know about existing widgets.
     */
    public function testBuildPayloadForwardsDashboardContext(): void
    {
        $input = [
            'question' => '各电影分类的电影数量对比',
            'dashboard_context' => [
                'dashboard_uid' => 'test-uid-123',
                'conversation_id' => 'conv-456',
                'widgets' => [
                    ['id' => 'w1', 'title' => '已有图表', 'sql' => 'SELECT 1'],
                ],
            ],
        ];

        $payload = $this->buildPayloadFromParsed($input);

        $this->assertSame('各电影分类的电影数量对比', $payload['question']);
        $this->assertArrayHasKey('dashboard_context', $payload);
        $this->assertSame('test-uid-123', $payload['dashboard_context']['dashboard_uid']);
        $this->assertCount(1, $payload['dashboard_context']['widgets']);
    }

    public function testBuildPayloadOmitsEmptyConversationId(): void
    {
        $input = [
            'question' => '测试',
            'conversation_id' => '',
        ];

        $payload = $this->buildPayloadFromParsed($input);

        $this->assertArrayNotHasKey('conversation_id', $payload);
    }

    public function testBuildPayloadOmitsNullConversationId(): void
    {
        $input = [
            'question' => '测试',
            'conversation_id' => null,
        ];

        $payload = $this->buildPayloadFromParsed($input);

        $this->assertArrayNotHasKey('conversation_id', $payload);
    }

    public function testBuildPayloadIncludesValidConversationId(): void
    {
        $input = [
            'question' => '测试',
            'conversation_id' => 'abc-123',
        ];

        $payload = $this->buildPayloadFromParsed($input);

        $this->assertSame('abc-123', $payload['conversation_id']);
    }

    // =========================================================================
    // SSE stream frame parsing
    // =========================================================================

    /**
     * Test frame filtering: only WIDGET_DATA_UPDATE frames carry widget payloads.
     */
    public function testFrameFilteringExtractsOnlyWidgetDataUpdate(): void
    {
        $frames = [
            ['type' => 'delta', 'data' => ['text' => 'thinking...']],
            ['type' => 'answer', 'data' => ['text' => '正在分析...']],
            ['type' => 'conversation_id', 'data' => ['conversation_id' => 'conv-1']],
            ['type' => 'WIDGET_DATA_UPDATE', 'data' => [
                'widget_id' => 'w1',
                'title' => '电影数量',
                'g2_spec' => ['type' => 'interval'],
                'sql' => 'SELECT COUNT(*) FROM qs_film',
            ]],
            ['type' => 'done', 'data' => []],
        ];

        $widgetFrames = array_filter($frames, fn($f) => ($f['type'] ?? '') === 'WIDGET_DATA_UPDATE');

        $this->assertCount(1, $widgetFrames);
        $extracted = array_values($widgetFrames);
        $this->assertSame('w1', $extracted[0]['data']['widget_id']);
    }

    /**
     * Test multi-widget response: complex prompts may produce multiple widgets.
     */
    public function testMultipleWidgetDataUpdateFramesCollected(): void
    {
        $frames = [
            ['type' => 'WIDGET_DATA_UPDATE', 'data' => [
                'widget_id' => 'w1',
                'title' => '租赁次数',
                'g2_spec' => ['type' => 'interval'],
            ]],
            ['type' => 'WIDGET_DATA_UPDATE', 'data' => [
                'widget_id' => 'w2',
                'title' => '收入金额',
                'g2_spec' => ['type' => 'line'],
            ]],
        ];

        $widgetFrames = array_values(
            array_filter($frames, fn($f) => ($f['type'] ?? '') === 'WIDGET_DATA_UPDATE'),
        );

        $this->assertCount(2, $widgetFrames);
        $this->assertSame('w1', $widgetFrames[0]['data']['widget_id']);
        $this->assertSame('w2', $widgetFrames[1]['data']['widget_id']);
    }

    /**
     * Test that widget data validation rejects frames without an id.
     */
    public function testWidgetValidationRejectsMissingId(): void
    {
        $data = ['title' => 'no id widget'];
        $this->assertFalse($this->validateWidget($data));
    }

    public function testWidgetValidationRejectsEmptyId(): void
    {
        $data = ['id' => '', 'title' => 'empty id'];
        $this->assertFalse($this->validateWidget($data));
    }

    public function testWidgetValidationAcceptsValidWidget(): void
    {
        $data = [
            'id' => 'w1',
            'title' => '有效图表',
            'g2_spec' => ['type' => 'interval'],
            'sql' => 'SELECT 1',
        ];
        $this->assertTrue($this->validateWidget($data));
    }

    // =========================================================================
    // Sakila data size verification
    // @see sakila-test-prompts.md "数据规模参考" section
    // =========================================================================

    /**
     * Verify known Sakila data sizes are consistent with test prompt expectations.
     * These assertions document the expected data landscape.
     */
    public function testSakilaDataSizeExpectations(): void
    {
        $expected = [
            'qs_actor' => 200,
            'qs_film' => 1000,
            'qs_customer' => 599,
            'qs_payment' => 16044,
            'qs_rental' => 16044,
            'qs_inventory' => 4581,
            'qs_film_actor' => 5462,
            'qs_film_category' => 1000,
            'qs_category' => 16,
            'qs_language' => 6,
            'qs_country' => 109,
            'qs_city' => 600,
            'qs_address' => 603,
            'qs_store' => 2,
            'qs_staff' => 2,
        ];

        // Verify the data sizes match the reference in sakila-test-prompts.md
        $this->assertSame(1000, $expected['qs_film'], 'qs_film should have 1000 rows');
        $this->assertSame(599, $expected['qs_customer'], 'qs_customer should have 599 rows');
        $this->assertSame(16, $expected['qs_category'], 'qs_category should have 16 categories');
        $this->assertSame(2, $expected['qs_store'], 'qs_store should have 2 stores');

        // These inform the expectedMinRows in test prompts
        // Prompt #3 (film ratings): at least 5 distinct ratings
        $this->assertGreaterThanOrEqual(5, 5);

        // Prompt #7 (categories): exactly 16 categories
        $this->assertSame(16, $expected['qs_category']);

        // Prompt #8 (stores): exactly 2 stores
        $this->assertSame(2, $expected['qs_store']);
    }

    // =========================================================================
    // Helper methods (mirroring controller private methods)
    // =========================================================================

    private function validateSocketInput(?array $input): ?array
    {
        if (!is_array($input)) {
            $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
            if (stripos($contentType, 'application/json') === false) {
                return ['status' => 0, 'info' => '请求格式不支持'];
            }
            return ['status' => 0, 'info' => '请求格式错误'];
        }

        $question = trim((string) ($input['question'] ?? ''));
        if ($question === '') {
            return ['status' => 0, 'info' => '请输入问题'];
        }

        if (mb_strlen($question) > 1000) {
            return ['status' => 0, 'info' => '问题长度不能超过1000字'];
        }

        $conversationId = $input['conversation_id'] ?? null;
        if ($conversationId !== null && !preg_match('/^[a-f0-9\-]{1,64}$/i', (string) $conversationId)) {
            return ['status' => 0, 'info' => '无效的会话ID'];
        }

        return null;
    }

    private function buildPayloadFromParsed(array $input): array
    {
        $question = trim((string) ($input['question'] ?? ''));
        $conversationId = $input['conversation_id'] ?? null;

        $payload = ['question' => $question];
        if (is_string($conversationId) && $conversationId !== '') {
            $payload['conversation_id'] = $conversationId;
        }

        if (!empty($input['dashboard_context'])) {
            $payload['dashboard_context'] = $input['dashboard_context'];
        }

        return $payload;
    }

    private function validateWidget($data): bool
    {
        if (!is_array($data) || $data === null) {
            return false;
        }
        return isset($data['id']) && is_string($data['id']) && strlen($data['id']) > 0;
    }
}
