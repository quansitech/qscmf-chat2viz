<?php
declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Sse\MockStreamEmitter;
use Qscmf\SseCore\SseEvent;
use Qscmf\SseCore\SseWriter;
use ReflectionMethod;

/**
 * 缺陷2: 锁定 MockStreamEmitter 流式发射 reasoning(思考过程)事件 —— 让前端的
 * "💭 AI 思考过程"折叠面板在 mock 模式下可验证(真实场景由 Python qs-chat2viz
 * sinks.py 的 sse_frame("reasoning", {"text":...}) 发出, PHP 透传, 前端 useSseStream
 * 的 case 'reasoning' → appendThought 接收).
 *
 * 用一个 RecordingSseWriter(继承 SseWriter, autoStart=false 关闭 header/buffer 清理)
 * 捕获 sendEvent 调用; 通过反射调用 emitMockQuery/emitMockAddChart/emitMockReasoning
 * (private). 不连真服务、不写 HTTP、不触发 clearOutputBuffers().
 *
 * @covers \Qscmf\Chat2Viz\Sse\MockStreamEmitter::emitMockQuery
 * @covers \Qscmf\Chat2Viz\Sse\MockStreamEmitter::emitMockAddChart
 * @covers \Qscmf\Chat2Viz\Sse\MockStreamEmitter::emitMockReasoning
 */
class MockStreamEmitterReasoningTest extends TestCase
{
    private MockStreamEmitter $emitter;

    /** 记录每个 sendEvent 收到的 SseEvent, 不 echo、不 flush. */
    private function makeRecordingWriter(): SseWriter
    {
        return new class(false) extends SseWriter {
            /** @var SseEvent[] */
            public array $events = [];
            public function __construct(bool $autoStart)
            {
                // 跳过父类构造(autoStart=false → 不清 buffer、不发 header).
            }
            public function sendEvent(SseEvent $event): void
            {
                $this->events[] = $event;
            }
        };
    }

    /** 反射调用 MockStreamEmitter 的 private 方法, 传 writer + 其余参数. */
    private function invokePrivate(string $method, array $args): array
    {
        $m = new ReflectionMethod($this->emitter, $method);
        $m->setAccessible(true);
        $m->invoke($this->emitter, ...$args);
        return $args[0]->events; // 第一个参数始终是 writer
    }

    protected function setUp(): void
    {
        $this->emitter = new MockStreamEmitter();
    }

    public function testQueryBranchEmitsReasoningEvents(): void
    {
        $writer = $this->makeRecordingWriter();
        $events = $this->invokePrivate('emitMockQuery', [$writer, '上月各门店销售额']);

        $reasoning = array_filter($events, static fn(SseEvent $e) => $e->type === 'reasoning');
        // 必须发射 reasoning, 且是多帧增量(appendThought 追加语义)而非单帧.
        $this->assertGreaterThanOrEqual(2, count($reasoning), 'reasoning 应分多帧增量发射');
    }

    public function testReasoningPrecedesAnswer(): void
    {
        $writer = $this->makeRecordingWriter();
        $events = $this->invokePrivate('emitMockQuery', [$writer, '按产品类别对比收入']);

        $reasoningIdx = null;
        $answerIdx = null;
        foreach ($events as $i => $e) {
            if ($e->type === 'reasoning' && $reasoningIdx === null) {
                $reasoningIdx = $i;
            }
            if ($e->type === 'answer' && $answerIdx === null) {
                $answerIdx = $i;
            }
        }
        $this->assertNotNull($reasoningIdx, '缺少 reasoning 事件');
        $this->assertNotNull($answerIdx, '缺少 answer 事件');
        // 思考先于回答 —— 模拟真实场景的时序(思考流先到, 回答/图表后到).
        $this->assertLessThan($answerIdx, $reasoningIdx, 'reasoning 必须在 answer 之前发射');
    }

    public function testAddChartBranchAlsoEmitsReasoning(): void
    {
        // "再加一个/新增" 走 emitMockAddChart 分支(另一个 generate 路径),
        // 同样应发射 reasoning.
        $writer = $this->makeRecordingWriter();
        $events = $this->invokePrivate('emitMockAddChart', [$writer, '再加一个折线图']);
        $hasReasoning = false;
        foreach ($events as $e) {
            if ($e->type === 'reasoning') {
                $hasReasoning = true;
                break;
            }
        }
        $this->assertTrue($hasReasoning, 'add 分支也应发射 reasoning');
    }

    public function testReasoningPayloadShapeMatchesPythonContract(): void
    {
        // 每帧 payload 必须是 {text: 非空字符串}, 与 Python sse_frame("reasoning",
        // {"text": text}) 严格对齐 —— 前端 EventRouter case 'reasoning' 只读 data['text'].
        $writer = $this->makeRecordingWriter();
        $events = $this->invokePrivate('emitMockQuery', [$writer, '订单金额排名前10']);
        $reasoning = array_filter($events, static fn(SseEvent $e) => $e->type === 'reasoning');

        $this->assertNotEmpty($reasoning);
        foreach ($reasoning as $evt) {
            $this->assertArrayHasKey('text', $evt->data, 'reasoning payload 必须有 text 字段');
            $this->assertIsString($evt->data['text'], 'reasoning text 必须是字符串');
            $this->assertNotSame('', $evt->data['text'], 'reasoning text 不能为空');
        }
    }

    public function testReasoningFragmentsConcatenateIntoCoherentThought(): void
    {
        // 多帧 text 拼起来应是连贯的思考过程文本(appendThought 在前端是追加语义,
        // 所以这里验证拼合后的总文本非空且含中文, 与真实 ReAct 推理一致).
        $writer = $this->makeRecordingWriter();
        $events = $this->invokePrivate('emitMockQuery', [$writer, '本月销售额']);
        $joined = implode('', array_map(
            static fn(SseEvent $e) => $e->type === 'reasoning' ? (string) $e->data['text'] : '',
            $events,
        ));
        $this->assertNotSame('', $joined, '拼合的 reasoning 文本不应为空');
        // 至少 5 个中文字符 —— 排除占位/无意义片段.
        $this->assertGreaterThanOrEqual(5, mb_strlen($joined, 'UTF-8'));
    }
}
