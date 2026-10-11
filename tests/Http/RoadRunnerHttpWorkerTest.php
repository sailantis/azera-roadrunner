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

    /**
     * Writes to the session, so the worker has one to carry back to the client.
     */
    public function rememberAction(string $value): string
    {
        session_start();
        $_SESSION['remembered'] = $value;

        return 'stored';
    }

    /**
     * Reads back what `rememberAction` wrote, to prove the session survived.
     */
    public function recallAction(): string
    {
        session_start();

        return (string) ($_SESSION['remembered'] ?? 'nothing');
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

    /**
     * A request that starts a session must be given the cookie naming it, since
     * no SAPI is writing one.
     */
    public function testASessionStartedByTheApplicationIsSentAsACookie(): void
    {
        $worker = $this->sessionWorker();

        $response = $worker->dispatch(new ServerRequest('GET', 'https://example.com/remember/ada'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Set-Cookie'));

        $cookie = $response->getHeaderLine('Set-Cookie');
        $this->assertStringStartsWith(rawurlencode((string) session_name()) . '=', $cookie);
    }

    /**
     * The whole point: the next request, presenting that cookie, sees the data
     * the previous one wrote. Under FPM/PHP-CGI this is the SAPI's job; in a
     * worker it is this worker's.
     */
    public function testASessionSurvivesIntoTheNextRequest(): void
    {
        $worker = $this->sessionWorker();

        $stored = $worker->dispatch(new ServerRequest('GET', 'https://example.com/remember/ada'));
        $cookie = $stored->getHeaderLine('Set-Cookie');
        $id = explode(';', explode('=', $cookie, 2)[1])[0];

        // A later request by the same client, carrying that cookie back.
        $recalled = $worker->dispatch(
            (new ServerRequest('GET', 'https://example.com/recall'))->withHeader('Cookie', (string) session_name() . '=' . $id)
        );

        $this->assertSame('ada', (string) $recalled->getBody());
        $this->assertFalse(
            $recalled->hasHeader('Set-Cookie'),
            'a client that already holds the id is not sent another cookie'
        );
    }

    /**
     * `session_id()` outlives a request in a persistent process, so a client
     * presenting no cookie must not inherit the previous client's session.
     */
    public function testAClientWithNoCookieDoesNotInheritThePreviousClientsSession(): void
    {
        $worker = $this->sessionWorker();

        $worker->dispatch(new ServerRequest('GET', 'https://example.com/remember/first-client'));

        $second = $worker->dispatch(new ServerRequest('GET', 'https://example.com/recall'));

        $this->assertSame('nothing', (string) $second->getBody());
    }

    private function sessionWorker(): RoadRunnerHttpWorker
    {
        $ctx        = AppContext::instance();
        $router     = $ctx->router();
        $dispatcher = new Dispatcher();
        $dispatcher->setBaseNamespace(__NAMESPACE__);

        $router->add('GET', '/remember/{value}', __NAMESPACE__ . '\TestController::rememberAction');
        $router->add('GET', '/recall', __NAMESPACE__ . '\TestController::recallAction');

        return new RoadRunnerHttpWorker($ctx, $router, $dispatcher);
    }
}