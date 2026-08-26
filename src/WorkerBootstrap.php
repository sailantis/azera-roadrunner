<?php

declare(strict_types=1);

namespace Azera\RR;

use Azera\AppContext;
use Azera\Boot\BootstrapDiscovery;
use Azera\Boot\BootstrapResolver;

/**
 * Bootstraps the Azera AppContext for a RoadRunner worker.
 *
 * A RoadRunner worker is a long-lived PHP process, so the app is booted
 * exactly once (not per request). This helper mirrors the framework's own
 * entry points: it resolves the application's BootstrapProvider (via the
 * same resolution chain the framework CLI uses), invokes boot(), and hands
 * back the shared {@see AppContext}.
 *
 * The provider (or its AppContext subclass) must be the first to call
 * {@see AppContext::instance()}; we never construct a base AppContext here,
 * otherwise a subclass would lose the singleton slot.
 */
final class WorkerBootstrap
{
    /**
     * Resolve and run the application bootstrap, then return the shared
     * AppContext.
     *
     * @param string $projectRoot Absolute path to the application root
     *                            (contains composer.json / azera-boot.php).
     */
    public static function boot(string $projectRoot): AppContext
    {
        $bridge = BootstrapResolver::resolve($projectRoot);

        if ($bridge === null && !is_file($projectRoot . '/vendor/azera-bootstrap.php')) {
            BootstrapDiscovery::scanAndCache($projectRoot);
            $bridge = BootstrapResolver::resolve($projectRoot);
        }

        if ($bridge !== null) {
            $bridge->boot();
        }

        return AppContext::instance();
    }
}