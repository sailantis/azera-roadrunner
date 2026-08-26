<?php

/**
 * Single entry point for Azera RoadRunner workers.
 *
 * RoadRunner starts a separate PHP worker process for each plugin (http,
 * grpc, …) from the same `server.command`/pool command, and sets the
 * `RR_MODE` environment variable to tell the worker which plugin it is
 * running for. This script detects that mode and starts the matching
 * persistent Azera worker loop.
 *
 * In the worker, the Azera AppContext is bootstrapped exactly once and then
 * stays resident. Per-request state is reset by the worker loop after each
 * request via AppContext::clearRequestScope().
 */

declare(strict_types=1);

use Azera\RR\Grpc\RpcDispatcher;
use Azera\RR\Grpc\RoadRunnerGrpcWorker;
use Azera\RR\Http\RoadRunnerHttpWorker;
use Azera\RR\WorkerBootstrap;

require __DIR__ . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// 0. Project root detection
//
// When running from an application, the worker script is invoked via the
// application's own command (e.g. php azera-worker.php inside the app root),
// so we treat the current working directory as the project root. This is
// overridable with AZERA_PROJECT_ROOT.
// ---------------------------------------------------------------------------
$projectRoot = getenv('AZERA_PROJECT_ROOT') ?: getcwd();

$ctx = WorkerBootstrap::boot($projectRoot);

// ---------------------------------------------------------------------------
// 1. Dispatch to the worker loop for the active RoadRunner plugin
// ---------------------------------------------------------------------------
switch (getenv('RR_MODE')) {
    case 'http':
        $worker = new RoadRunnerHttpWorker(
            $ctx,
            $ctx->router(),
            $ctx->dispatcher(),
        );
        $worker->run();
        break;

    case 'grpc':
        // Build the dispatcher and let the application register its generated
        // gRPC services. An optional `grpc-services.php` in the project root
        // may return a callable that receives the RpcDispatcher:
        //
        //   <?php
        //   return static function (RpcDispatcher $rpc): void {
        //       $rpc->service(\GRPC\Greeter\GreeterInterface::class, \App\Grpc\GreeterService::class);
        //   };
        //
        $rpc = new RpcDispatcher($ctx);

        $register = $projectRoot . '/grpc-services.php';
        if (is_file($register)) {
            $callable = require $register;
            if (!is_callable($callable)) {
                throw new RuntimeException('grpc-services.php must return a callable.');
            }
            $callable($rpc);
        }

        $grpc = new RoadRunnerGrpcWorker($ctx, $rpc);
        $grpc->run();
        break;

    default:
        // Unknown/unset mode — fail loudly so misconfiguration is obvious.
        throw new RuntimeException(
            'Unknown RR_MODE "' . (string) getenv('RR_MODE')
            . '". This entry point is meant to run inside a RoadRunner worker.'
        );
}