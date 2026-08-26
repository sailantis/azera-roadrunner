<?php

declare(strict_types=1);

namespace Azera\RR\Tests\Http;

use Azera\AppContext;
use Azera\Core\Dispatcher;
use Azera\Core\Router;
use Azera\RR\Http\RoadRunnerHttpWorker;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class TestController extends \Azera\Core\Controller
{
    public function helloAction(string $name): string
    {
        return 'Hello ' . $name;
    }

    public function notFoundAction(): \Azera\Http\Response
    {
        return \Azera\Http\Response::json(['ok' => false], 404);
    }
}

final class RoadRunnerHttpWorkerTest extends TestCase
{
    public function testDispatchRoutesPsrRequestThroughAzeraPipeline(): void
    {
        $ctx        = AppContext::instance();
        $router     = $ctx->router();
        $dispatcher = new Dispatcher();
        $dispatcher->setBaseNamespace(__NAMESPACE__);

        $router->add('GET', '/hello/{name}', __NAMESPACE__ . '\TestController::helloAction');

        $worker = new RoadRunnerHttpWorker($ctx, $router, $dispatcher);

        $psrRequest = new ServerRequest('GET', 'https://example.com/hello/world');

        $response = $worker->dispatch($psrRequest);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Hello world', (string) $response->getBody());
    }

    public function testDispatchReturns404ForUnknownRoute(): void
    {
        $ctx        = AppContext::instance();
        $router     = new Router();
        $dispatcher = new Dispatcher();

        $worker = new RoadRunnerHttpWorker($ctx, $router, $dispatcher);

        $psrRequest = new ServerRequest('GET', 'https://example.com/nope');

        $response = $worker->dispatch($psrRequest);

        $this->assertSame(404, $response->getStatusCode());
    }
}