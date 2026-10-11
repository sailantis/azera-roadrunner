<?php

declare(strict_types=1);

namespace Azera\RR\Tests;

use Azera\AppContext;
use Azera\Core\Engines\ClarityEngine;
use Azera\RR\WorkerBootstrap;
use PHPUnit\Framework\TestCase;

final class WorkerBootstrapTest extends TestCase
{
    public function testEnableDumpHaltTurnsOnHaltForDebugModeClarityEngine(): void
    {
        $ctx    = AppContext::instance();
        $engine = new ClarityEngine();
        $engine->setDebugMode(true);
        $ctx->setView($engine);

        WorkerBootstrap::enableDumpHalt($ctx);

        $this->assertTrue($engine->getDebugOptions()?->getHaltWithException());
    }

    public function testEnableDumpHaltIsNoOpWhenDebugModeIsOff(): void
    {
        $ctx    = AppContext::instance();
        $engine = new ClarityEngine();
        $ctx->setView($engine);

        WorkerBootstrap::enableDumpHalt($ctx);

        $this->assertNull($engine->getDebugOptions());
    }
}
