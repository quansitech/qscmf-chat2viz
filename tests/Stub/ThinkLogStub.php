<?php

namespace Qscmf\Chat2Viz\Tests\Stub;

class ThinkLogStub
{
    public const ERR = 'ERR';

    public static function write(string $message, string $level): void
    {
        // no-op in tests
    }
}
