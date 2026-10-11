<?php

declare(strict_types=1);

namespace Azera\RR\Tests\Http;

use Azera\AppContext;
use Azera\Core\Dispatcher;
use Azera\Core\Router;
use Azera\RR\Http\RoadRunnerHttpWorker;
use Clarity\Debug\DebugRuntime;
use Clarity\Debug\DumpOptions;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Spiral\RoadRunner\Http\PSR7Worker;

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

    public function haltAction(): string
    {
        (new DebugRuntime(DumpOptions::create()->haltWithException()))->dumpAndDie('html', ['marker-value']);
    }
}

final class RoadRunnerHttpWorkerTest extends TestCase
{
    public function testRunSendsDumpHaltAsResponseAndKeepsWorkerAlive(): void
    {
        $ctx        = AppContext::instance();
        $router     = new Router();
        $dispatcher = new Dispatcher();
        $dispatcher->setBaseNamespace(__NAMESPACE__);
        $router->add('GET', '/halt', __NAMESPACE__ . '\TestController::haltAction');

        $psr7 = $this->createMock(PSR7Worker::class);
        $psr7->method('waitRequest')->willReturnOnConsecutiveCalls(
            new ServerRequest('GET', 'https://example.com/halt'),
            null,
        );
        $psr7->expects($this->once())->method('respond')->with($this->callback(
            static function (ResponseInterface $response): bool {
                return $response->getStatusCode() === 500
                    && str_contains($response->getHeaderLine('Content-Type'), 'text/html')
                    && str_contains((string) $response->getBody(), 'clarity-dump')
                    && str_contains((string) $response->getBody(), 'marker-value');
            }
        ));

        (new RoadRunnerHttpWorker($ctx, $router, $dispatcher, $psr7))->run();
    }

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