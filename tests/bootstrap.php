<?php

// Test bootstrap: stub framework dependencies before autoload kicks in.

$packageRoot = dirname(__DIR__);

// ── Scrub CHAT2VIZ_* runtime-service vars from the PHP superglobals ─────────
// The host's Laravel env() reads $_SERVER / $_ENV (dotenv default adapters,
// NOT getenv()), so a shell-exported CHAT2VIZ_SOCKET_PATH / CHAT2VIZ_API_KEY
// survives into the test process via $_SERVER and silently defeats the
// putenv()-based isolation in CreateSocketTransportTest /
// AskCommandIntegrationTest / ShowSqlConfigTest (putenv writes the process
// env table, not the superglobals). Unit tests drive config with putenv(), so
// the superglobals MUST be clean before any env() call lazily builds the
// dotenv repository. Only CHAT2VIZ_* is scrubbed — DB_* etc. are left intact
// (phpunit-safe / phpunit.xml own those).
foreach (array_keys($_SERVER) as $k) {
    if (str_starts_with($k, 'CHAT2VIZ_')) {
        unset($_SERVER[$k]);
    }
}
foreach (array_keys($_ENV ?? []) as $k) {
    if (str_starts_with($k, 'CHAT2VIZ_')) {
        unset($_ENV[$k]);
    }
}
// Also clear the process env table so getenv() matches until the test sets it.
foreach (['CHAT2VIZ_SOCKET_PATH', 'CHAT2VIZ_API_KEY', 'CHAT2VIZ_SSE_TIMEOUT',
          'CHAT2VIZ_SHOW_SQL', 'CHAT2VIZ_MOCK_MODE'] as $k) {
    putenv($k); // no value = delete
}

// Autoload path: prefer package-local, fall back to host project
$autoloadPath = $packageRoot . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    $autoloadPath = dirname($packageRoot, 3) . '/vendor/autoload.php';
}
if (!file_exists($autoloadPath)) {
    throw new RuntimeException('Cannot find vendor/autoload.php');
}

// Load stub files BEFORE autoload so the classes exist when autoload fires
require_once $packageRoot . '/tests/Stub/GyControllerStub.php';
require_once $packageRoot . '/tests/Stub/QsControllerStub.php';
require_once $packageRoot . '/tests/Stub/ThinkLogStub.php';

// Now register autoload
require $autoloadPath;

// Alias stubs to framework names so Chat2VizController can resolve its parent
if (!class_exists(\Gy_Library\GyController::class, false)) {
    class_alias(
        \Qscmf\Chat2Viz\Tests\Stub\GyControllerStub::class,
        \Gy_Library\GyController::class
    );
}

// fix-public-view-draft-exposure §3: alias QsController so
// BaseDashboardController / DashboardController / PublicDashboardController
// can load under PHPUnit without booting the full ThinkPHP runtime. The stub
// is a standalone class (no extends Think\Controller), which severs the
// framework dependency chain at the alias boundary.
if (!class_exists(\Qscmf\Core\QsController::class, false)) {
    class_alias(
        \Qscmf\Chat2Viz\Tests\Stub\QsControllerStub::class,
        \Qscmf\Core\QsController::class
    );
}

if (!class_exists(\Think\Log::class, false)) {
    class_alias(
        \Qscmf\Chat2Viz\Tests\Stub\ThinkLogStub::class,
        \Think\Log::class
    );
}
