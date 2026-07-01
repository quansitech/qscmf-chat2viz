<?php

namespace Qscmf\Chat2Viz\Tests\Stub;

/**
 * Minimal stub for Qscmf\Core\QsController — mirrors the framework class
 * signature enough to let BaseDashboardController / DashboardController /
 * PublicDashboardController load under PHPUnit without booting ThinkPHP.
 *
 * No return types on methods to match the original QsController contract
 * (mirrors GyControllerStub). Stubs here are inert; tests that need to
 * observe calls override them via anonymous subclasses.
 *
 * Required by tests/Controller/*Test.php (fix-public-view-draft-exposure §3).
 */
class QsControllerStub
{
    /** Subclasses (test doubles) override this to capture rejections. */
    public function error($message = '', $jumpUrl = '', $time = 3)
    {
        // no-op
    }

    /** Subclasses override to capture JSON responses. */
    public function ajaxReturn($data, $type = '', $json_option = 0)
    {
        // no-op
    }

    /**
     * ThinkPHP input helper. Reads from PHP superglobals so tests can set
     * $_GET['uid'] etc. without a real framework request.
     */
    public function I($name, $default = '', $filter = null, $datas = '')
    {
        // Parse "get.uid" / "post.uid" style ThinkPHP name spec.
        if (!is_string($name) || $name === '') {
            return $default;
        }
        $parts = explode('.', $name, 2);
        $source = $parts[0] ?? '';
        $key = $parts[1] ?? '';

        $bag = match ($source) {
            'get' => $_GET,
            'post' => $_POST,
            'request' => $_REQUEST,
            default => $_REQUEST,
        };

        if ($key === '') {
            return $default;
        }
        return array_key_exists($key, $bag) ? $bag[$key] : $default;
    }

    protected function _initialize()
    {
        // no-op
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
