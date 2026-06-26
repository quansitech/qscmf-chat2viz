<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Controller\Chat2VizController;

/**
 * Tests for Chat2VizController::api_ask() — the synchronous (non-streaming)
 * proxy to the Python NL2SQL service.
 *
 * Covers the contract:
 *   - Successful 2xx with `answer` key → adapted success envelope (status=1).
 *   - 5xx from upstream → "分析服务请求失败".
 *   - Network/Connect failure → "分析服务不可用".
 *   - Empty / missing question → "请输入问题" (validator gate, no upstream call).
 *   - X-API-Key header is forwarded verbatim from CHAT2VIZ_API_KEY.
 *   - Guzzle client is built with a 60-second timeout.
 *
 * The upstream service is replaced with a Guzzle MockHandler so the test
 * never touches the network. The controller is constructed via reflection
 * to skip GyController's constructor; serviceUrl, apiKey and httpClient
 * are injected directly through reflection on the protected properties.
 *
 * The stubbed GyController::ajaxReturn is captured through an anonymous
 * subclass so each test can assert the actual JSON envelope without booting
 * the framework response writer.
 *
 * @covers \Qscmf\Chat2Viz\Controller\Chat2VizController::api_ask
 */
class Chat2VizApiAskTest extends TestCase
{
    private const SERVICE_URL = 'http://python.local';
    private const API_KEY     = 'test-api-key-xyz';

    /** @var array<int, array<string, mixed>> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->captured = [];
        // Default Content-Type: pretend the client posted JSON so the validator
        // accepts the body. Tests that need a different type override this.
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        // The body for parseJsonInput is read from php://input — pre-seed it.
        file_put_contents('php://input', '');
    }

    protected function tearDown(): void
    {
        unset($_SERVER['CONTENT_TYPE'], $_SERVER['REQUEST_METHOD']);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1. Empty question
    // ------------------------------------------------------------------

    public function testEmptyQuestionReturnsErrorEnvelope(): void
    {
        $controller = $this->newControllerWithHttp(new MockHandler([]));

        $this->invokeApiAsk($controller, ['question' => '']);

        $this->assertCount(1, $this->captured);
        $this->assertSame(0, $this->captured[0]['status']);
        $this->assertSame('请输入问题', $this->captured[0]['info']);
    }

    public function testWhitespaceOnlyQuestionReturnsErrorEnvelope(): void
    {
        $controller = $this->newControllerWithHttp(new MockHandler([]));

        $this->invokeApiAsk($controller, ['question' => "   \t\n  "]);

        $this->assertCount(1, $this->captured);
        $this->assertSame(0, $this->captured[0]['status']);
        $this->assertSame('请输入问题', $this->captured[0]['info']);
    }

    // ------------------------------------------------------------------
    // 2. Successful proxy
    // ------------------------------------------------------------------

    public function testSuccessfulProxyAdaptsAnswerEnvelope(): void
    {
        $serviceResponse = [
            'answer'  => '电影总数 1000 部',
            'sql'     => 'SELECT COUNT(*) FROM qs_film',
            'g2_spec' => ['type' => 'text'],
        ];

        $controller = $this->newControllerWithHttp(new MockHandler([
            new Psr7Response(200, [], json_encode($serviceResponse, JSON_UNESCAPED_UNICODE)),
        ]));

        $this->invokeApiAsk($controller, ['question' => '总共有多少部电影']);

        $this->assertCount(1, $this->captured);
        $this->assertSame(1, $this->captured[0]['status']);
        $this->assertSame('电影总数 1000 部', $this->captured[0]['data']['answer']);
        $this->assertSame('SELECT COUNT(*) FROM qs_film', $this->captured[0]['data']['sql']);
        $this->assertSame(['type' => 'text'], $this->captured[0]['data']['g2_spec']);
    }

    // ------------------------------------------------------------------
    // 3. 5xx upstream error
    // ------------------------------------------------------------------

    public function test5xxUpstreamReturnsRequestFailedEnvelope(): void
    {
        $controller = $this->newControllerWithHttp(new MockHandler([
            new RequestException(
                'Server Error',
                new Psr7Request('POST', self::SERVICE_URL . '/api/v1/ask'),
                new Psr7Response(500, [], 'internal error'),
            ),
        ]));

        $this->invokeApiAsk($controller, ['question' => '查询销量']);

        $this->assertCount(1, $this->captured);
        $this->assertSame(0, $this->captured[0]['status']);
        $this->assertSame('分析服务请求失败', $this->captured[0]['info']);
    }

    // ------------------------------------------------------------------
    // 4. Connection failure
    // ------------------------------------------------------------------

    public function testConnectionFailureReturnsServiceUnavailable(): void
    {
        $controller = $this->newControllerWithHttp(new MockHandler([
            new ConnectException(
                'Connection refused',
                new Psr7Request('POST', self::SERVICE_URL . '/api/v1/ask'),
            ),
        ]));

        $this->invokeApiAsk($controller, ['question' => '查询销量']);

        $this->assertCount(1, $this->captured);
        $this->assertSame(0, $this->captured[0]['status']);
        $this->assertSame('分析服务不可用', $this->captured[0]['info']);
    }

    // ------------------------------------------------------------------
    // 5. X-API-Key header forwarding
    // ------------------------------------------------------------------

    public function testApiKeyHeaderForwardedToUpstream(): void
    {
        $mock = new MockHandler([
            new Psr7Response(200, [], json_encode(['answer' => 'ok'])),
        ]);

        // Use a HistoryMiddleware to capture the outgoing request.
        $history = [];
        $stack = HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));

        $controller = $this->newControllerWithHttp($mock, $stack);

        $this->invokeApiAsk($controller, ['question' => 'check header']);

        $this->assertCount(1, $this->captured, 'Expected exactly one upstream call');
        $this->assertCount(1, $history, 'History middleware should record one request');

        $sent = $history[0]['request'];
        $this->assertSame('POST', $sent->getMethod());
        $this->assertSame(self::SERVICE_URL . '/api/v1/ask', (string) $sent->getUri());
        $this->assertSame(self::API_KEY, $sent->getHeaderLine('X-API-Key'));
        $this->assertSame('application/json', $sent->getHeaderLine('Content-Type'));
    }

    // ------------------------------------------------------------------
    // 6. 60s timeout
    // ------------------------------------------------------------------

    public function testHttpClientBuiltWith60SecondTimeout(): void
    {
        // Construct via _initialize() so the timeout is set inside the
        // controller rather than via reflection injection. We can still
        // short-circuit the upstream call: _initialize() does not touch
        // the network.
        $controller = (new \ReflectionClass(Chat2VizController::class))
            ->newInstanceWithoutConstructor();

        // Manually invoke the protected _initialize.
        $init = new \ReflectionMethod($controller, '_initialize');
        $init->setAccessible(true);
        $init->invoke($controller);

        $prop = new \ReflectionProperty($controller, 'httpClient');
        $prop->setAccessible(true);
        /** @var GuzzleClient $client */
        $client = $prop->getValue($controller);

        $config = $client->getConfig();
        $this->assertSame(60, $config['timeout']);
    }

    // ------------------------------------------------------------------
    // Test helpers
    // ------------------------------------------------------------------

    /**
     * Build a controller with the given mock handler wired through a fresh
     * Guzzle client, and CHAT2VIZ_SERVICE_URL / CHAT2VIZ_API_KEY injected
     * into the protected $serviceUrl / $apiKey properties.
     */
    private function newControllerWithHttp(MockHandler $mock, ?HandlerStack $stack = null): Chat2VizController
    {
        $stack = $stack ?? HandlerStack::create($mock);
        $client = new GuzzleClient(['handler' => $stack, 'timeout' => 60]);

        $controller = (new \ReflectionClass(Chat2VizController::class))
            ->newInstanceWithoutConstructor();

        $this->setProtectedProperty($controller, 'serviceUrl', self::SERVICE_URL);
        $this->setProtectedProperty($controller, 'apiKey', self::API_KEY);
        $this->setProtectedProperty($controller, 'httpClient', $client);

        return $controller;
    }

    /**
     * Invoke api_ask() with a JSON body matching the given input array.
     *
     * We bypass php://input (unreliable in PHPUnit CLI) and override
     * parseJsonInput() in the bridge to return the pre-built body
     * directly. ajaxReturn is captured into $this->captured.
     */
    private function invokeApiAsk(Chat2VizController $controller, array $body): void
    {
        $bridge = new class($body, $this->captured) extends Chat2VizController {
            /** @var array<string, mixed> */
            private array $body;
            /** @var array<int, array<string, mixed>> */
            private array $sink;

            public function __construct(array $body, array &$sink)
            {
                // Skip parent::_initialize — properties are already populated
                // via reflection on the delegate. We do not call any parent
                // constructor because GyControllerStub has none.
                $this->body = $body;
                $this->sink = &$sink;
            }

            // Bypass php://input — return the body captured at construction.
            protected function parseJsonInput(): ?array
            {
                return $this->body;
            }

            public function ajaxReturn($data, $type = '', $json_option = 0)
            {
                $this->sink[] = is_array($data) ? $data : ['value' => $data];
            }

            public function logError(string $tag, string $detail): void
            {
                // Suppress log writes during tests.
            }

            public function runApiAsk(): void
            {
                $this->api_ask();
            }
        };

        // Mirror the delegate's protected state into the bridge so api_ask
        // reads the same serviceUrl/apiKey/httpClient when called on the
        // bridge instance. This is necessary because api_ask() reads
        // $this->serviceUrl, $this->apiKey, $this->httpClient.
        $this->mirrorProtected($controller, $bridge, 'serviceUrl');
        $this->mirrorProtected($controller, $bridge, 'apiKey');
        $this->mirrorProtected($controller, $bridge, 'httpClient');

        $bridge->runApiAsk();
    }

    private function mirrorProtected(object $from, object $to, string $prop): void
    {
        $ref = new \ReflectionProperty($from, $prop);
        $ref->setAccessible(true);
        $value = $ref->getValue($from);
        $ref->setValue($to, $value);
    }

    private function setProtectedProperty(object $obj, string $name, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $name);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }
}
