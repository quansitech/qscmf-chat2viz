<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Controller\Chat2VizController;

/**
 * @covers \Qscmf\Chat2Viz\Controller\Chat2VizController::buildPayloadFromParsed
 */
class BuildPayloadTest extends TestCase
{
    private Chat2VizController $controller;

    protected function setUp(): void
    {
        $this->controller = (new \ReflectionClass(Chat2VizController::class))
            ->newInstanceWithoutConstructor();
    }

    private function invokeBuildPayload(array $input): array
    {
        $method = new \ReflectionMethod(Chat2VizController::class, 'buildPayloadFromParsed');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $input);
    }

    public function testBasicQuestionPayload(): void
    {
        $result = $this->invokeBuildPayload(['question' => 'show sales']);
        $this->assertSame(['question' => 'show sales'], $result);
    }

    public function testQuestionTrimmed(): void
    {
        $result = $this->invokeBuildPayload(['question' => '  hello  ']);
        $this->assertSame('hello', $result['question']);
    }

    public function testConversationIdIncludedWhenPresent(): void
    {
        $result = $this->invokeBuildPayload([
            'question' => 'test',
            'conversation_id' => 'abc-123',
        ]);
        $this->assertArrayHasKey('conversation_id', $result);
        $this->assertSame('abc-123', $result['conversation_id']);
    }

    public function testConversationIdOmittedWhenEmpty(): void
    {
        $result = $this->invokeBuildPayload([
            'question' => 'test',
            'conversation_id' => '',
        ]);
        $this->assertArrayNotHasKey('conversation_id', $result);
    }

    public function testConversationIdOmittedWhenNull(): void
    {
        $result = $this->invokeBuildPayload([
            'question' => 'test',
            'conversation_id' => null,
        ]);
        $this->assertArrayNotHasKey('conversation_id', $result);
    }

    public function testDashboardContextForwarded(): void
    {
        $result = $this->invokeBuildPayload([
            'question' => 'test',
            'dashboard_context' => ['dashboard_id' => 42, 'version_id' => 7],
        ]);
        $this->assertArrayHasKey('dashboard_context', $result);
        $this->assertSame(42, $result['dashboard_context']['dashboard_id']);
        $this->assertSame(7, $result['dashboard_context']['version_id']);
    }

    public function testEmptyDashboardContextOmitted(): void
    {
        $result = $this->invokeBuildPayload([
            'question' => 'test',
            'dashboard_context' => [],
        ]);
        $this->assertArrayNotHasKey('dashboard_context', $result);
    }

    public function testMissingDashboardContextOmitted(): void
    {
        $result = $this->invokeBuildPayload(['question' => 'test']);
        $this->assertArrayNotHasKey('dashboard_context', $result);
    }
}
