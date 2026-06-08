<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Controller\Chat2VizController;
use Qscmf\SseCore\SocketTransport;

/**
 * @covers \Qscmf\Chat2Viz\Controller\Chat2VizController::createSocketTransport
 */
class CreateSocketTransportTest extends TestCase
{
    private Chat2VizController $controller;

    protected function setUp(): void
    {
        $this->controller = (new \ReflectionClass(Chat2VizController::class))
            ->newInstanceWithoutConstructor();
    }

    private function invokeCreateSocketTransport(): SocketTransport
    {
        $method = new \ReflectionMethod(Chat2VizController::class, 'createSocketTransport');
        $method->setAccessible(true);
        return $method->invoke($this->controller);
    }

    public function testReturnsSocketTransportInstance(): void
    {
        $transport = $this->invokeCreateSocketTransport();
        $this->assertInstanceOf(SocketTransport::class, $transport);
    }

    public function testDefaultSocketPath(): void
    {
        // Ensure env is not set
        putenv('CHAT2VIZ_SOCKET_PATH');

        $transport = $this->invokeCreateSocketTransport();
        // SocketTransport stores config internally; verify via reflection
        $prop = new \ReflectionProperty(SocketTransport::class, 'socketPath');
        $prop->setAccessible(true);
        $this->assertSame('/run/chat2viz.sock', $prop->getValue($transport));
    }

    public function testCustomSocketPath(): void
    {
        putenv('CHAT2VIZ_SOCKET_PATH=/tmp/test.sock');
        try {
            $transport = $this->invokeCreateSocketTransport();
            $prop = new \ReflectionProperty(SocketTransport::class, 'socketPath');
            $prop->setAccessible(true);
            $this->assertSame('/tmp/test.sock', $prop->getValue($transport));
        } finally {
            putenv('CHAT2VIZ_SOCKET_PATH');
        }
    }

    public function testDefaultTimeout(): void
    {
        putenv('CHAT2VIZ_SSE_TIMEOUT');
        $transport = $this->invokeCreateSocketTransport();
        $prop = new \ReflectionProperty(SocketTransport::class, 'timeout');
        $prop->setAccessible(true);
        $this->assertSame(180, $prop->getValue($transport));
    }

    public function testCustomTimeout(): void
    {
        putenv('CHAT2VIZ_SSE_TIMEOUT=300');
        try {
            $transport = $this->invokeCreateSocketTransport();
            $prop = new \ReflectionProperty(SocketTransport::class, 'timeout');
            $prop->setAccessible(true);
            $this->assertSame(300, $prop->getValue($transport));
        } finally {
            putenv('CHAT2VIZ_SSE_TIMEOUT');
        }
    }
}
