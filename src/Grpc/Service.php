<?php

declare(strict_types=1);

namespace Azera\RR\Grpc;

use Azera\AppContext;

/**
 * Base class for gRPC service handlers in Azera.
 *
 * Mirror of Azera's {@see \Azera\Core\Controller}, but for gRPC service
 * handlers. Handlers may extend this class to gain easy access to the
 * application context and its services, while still implementing the
 * generated service interface.
 */
abstract class Service
{
    /**
     * Get the current AppContext instance.
     */
    public function context(): AppContext
    {
        return AppContext::instance();
    }

    /**
     * Get a service from the context by class name.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function resolve(string $id): object
    {
        return $this->context()->get($id);
    }

    /**
     * Get the database manager for persistence access.
     */
    public function db(): \Azera\Db\DatabaseManager
    {
        return $this->context()->dbManager();
    }

    /**
     * Get the cache service.
     */
    public function cache(): \Psr\SimpleCache\CacheInterface
    {
        return $this->context()->cache();
    }

    /**
     * Get the logger.
     */
    public function logger(): \Psr\Log\LoggerInterface
    {
        return $this->context()->logger();
    }

    /**
     * Get the event dispatcher.
     */
    public function events(): \Psr\EventDispatcher\EventDispatcherInterface
    {
        return $this->context()->events();
    }
}