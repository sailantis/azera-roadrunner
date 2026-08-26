<?php

declare(strict_types=1);

namespace Azera\RR\Grpc;

use Azera\AppContext;
use Spiral\RoadRunner\GRPC\ContextInterface;

/**
 * Persistent gRPC worker loop for RoadRunner.
 *
 * Wraps an {@see RpcDispatcher} and, after every served RPC, resets the
 * Azera per-request state so nothing leaks into the next call. The handler
 * instances themselves are resolved once at bootstrap and stay resident.
 */
final class RoadRunnerGrpcWorker
{
    public function __construct(
        private AppContext $context,
        private RpcDispatcher $dispatcher,
    ) {}

    /**
     * Run the gRPC server loop until RoadRunner signals shutdown. Per-request
     * Azera state is reset after every RPC.
     */
    public function run(): void
    {
        $this->dispatcher->serve(
            finalize: function (): void {
                $this->context->clearRequestScope();
            }
        );
    }
}