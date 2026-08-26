<?php

declare(strict_types=1);

namespace Azera\RR\Grpc;

use Azera\AppContext;
use Spiral\RoadRunner\GRPC\Server as GrpcServer;
use Spiral\RoadRunner\GRPC\ServiceInterface;
use Spiral\RoadRunner\Worker;
use Spiral\RoadRunner\WorkerInterface;

/**
 * Azera-style mapper/registry for gRPC services.
 *
 * RoadRunner's gRPC plugin dispatches by the *service name* declared in the
 * generated service interface (`const NAME`), and each RPC method is a real
 * method on that interface. Unlike HTTP routes (which are free-form), a gRPC
 * service is a fixed contract, so registration here is **per service**, not
 * per method.
 *
 * This class wraps RoadRunner's {@see GrpcServer} and gives it an Azera feel:
 * handlers are resolved through the {@see AppContext} DI container, so a
 * service handler can be registered either as an instance or as a class name
 * that gets auto-wired — exactly like Azera controllers.
 *
 * Handlers may extend {@see Service} (or just implement the generated
 * interface) and may throw {@see Exception\GrpcStatusException} to control
 * the status code returned to the client.
 */
final class RpcDispatcher
{
    private GrpcServer $server;

    public function __construct(
        private AppContext $context,
        ?GrpcServer $server = null,
    ) {
        $this->server = $server ?? new GrpcServer();
    }

    /**
     * Register a gRPC service handler against its generated interface.
     *
     * The handler may be:
     *  - a class name (resolved via AppContext DI/auto-wiring), or
     *  - an object instance.
     *
     * It must implement the generated service interface (which in turn
     * implements {@see ServiceInterface} and declares the `NAME` constant).
     *
     * @template T of ServiceInterface
     * @param class-string<T> $interface The generated service interface (e.g. \GRPC\Greeter\GreeterInterface).
     * @param T|class-string<T> $handler  Handler instance or class name.
     */
    public function service(string $interface, object|string $handler): static
    {
        $instance = $this->resolve($interface, $handler);

        $this->server->registerService($interface, $instance);

        return $this;
    }

    /**
     * Serve gRPC requests over the given RoadRunner worker until shutdown.
     *
     * @param callable|null $finalize Optional callback invoked after each
     *        request (in the server's finally block) — used to reset
     *        per-request Azera state.
     */
    public function serve(?WorkerInterface $worker = null, ?callable $finalize = null): void
    {
        $this->server->serve($worker ?? Worker::create(), $finalize);
    }

    /**
     * Access the underlying RoadRunner server (advanced use).
     */
    public function getServer(): GrpcServer
    {
        return $this->server;
    }

    /**
     * Resolve a handler to a service instance.
     *
     * @template T of ServiceInterface
     * @param class-string<T> $interface
     * @param T|class-string<T> $handler
     * @return T
     */
    private function resolve(string $interface, object|string $handler): ServiceInterface
    {
        if (is_object($handler)) {
            $instance = $handler;
        } else {
            $instance = $this->context->get($handler);
        }

        if (!$instance instanceof $interface) {
            throw new \InvalidArgumentException(
                sprintf(
                    'gRPC handler %s must implement interface %s.',
                    is_object($instance) ? get_class($instance) : (string) $handler,
                    $interface
                )
            );
        }

        return $instance;
    }
}