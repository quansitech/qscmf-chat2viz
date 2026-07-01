<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Stub;

/**
 * Test stub for the framework global env() helper.
 *
 * The real Think\QSCMF env() reads a parsed .env file; in unit tests we don't
 * boot the framework, so env() is undefined and would fatal. This stub reads
 * the live process environment via getenv() so tests can drive config flags
 * (e.g. CHAT2VIZ_SHOW_SQL) with putenv(). Mirrors the real helper's contract:
 * returns the default when the variable is absent.
 *
 * Registered in tests/bootstrap.php.
 */
function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return $value;
}
