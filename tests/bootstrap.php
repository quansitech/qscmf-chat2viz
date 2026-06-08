<?php

// Test bootstrap: stub framework dependencies before autoload kicks in.

$packageRoot = dirname(__DIR__);

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

if (!class_exists(\Think\Log::class, false)) {
    class_alias(
        \Qscmf\Chat2Viz\Tests\Stub\ThinkLogStub::class,
        \Think\Log::class
    );
}
