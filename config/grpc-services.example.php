<?php

/**
 * Application gRPC service registrations.
 *
 * This optional file lives in your application root and is loaded once when
 * the RoadRunner gRPC worker starts. Return a callable that registers your
 * generated service interfaces against handler classes.
 *
 * Handlers may be given as a class name (auto-wired via the Azera AppContext
 * DI container) or as an object instance.
 */

use Azera\RR\Grpc\RpcDispatcher;

return static function (RpcDispatcher $rpc): void {
    // Example — uncomment and adapt to your generated services:
    //
    // $rpc->service(
    //     \GRPC\Greeter\GreeterInterface::class,
    //     \App\Grpc\GreeterService::class,
    // );
};