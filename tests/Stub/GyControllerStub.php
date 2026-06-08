<?php

namespace Qscmf\Chat2Viz\Tests\Stub;

/**
 * Minimal stub for GyController — mirrors the framework class signature.
 * No return types on methods to match the original GyController contract.
 */
class GyControllerStub
{
    protected function _initialize()
    {
        // no-op
    }

    protected function ajaxReturn(array $data)
    {
        // no-op in tests
    }

    protected function assign(string $key, $value)
    {
        // no-op
    }

    protected function display()
    {
        // no-op
    }
}
