<?php

declare(strict_types=1);

namespace Azera\RR\Http;

use Azera\AppContext;
use Azera\Core\Dispatcher;
use Azera\Core\Router;
use Azera\Http\Request as AzeraRequest;
use Azera\Http\Response as AzeraResponse;
use Azera\RR\Http\Adapter\PsrToAzeraRequest;
use Closure;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;
use Throwable;

/**
 * Persistent HTTP worker loop for RoadRunner.
 *
 * Reuses the Azera {@see Router} + Dispatcher pipeline unchanged: each PSR-7
 * request is translated to an Azera request, matched against the router, and
 * dispatched to the controller. After the response is sent, per-request state
 * is reset so nothing leaks into the next request.
 */
final class RoadRunnerHttpWorker
{
    /**
     * @param callable(string $path, string $method): AzeraResponse|null $notFoundHandler
     *        Optional callback invoked when no route matches. Return an Azera
     *        response to send it, or null to fall back to a plain 404.
     */
    public function __construct(
        private AppContext $context,
        private Router $router,
        private Dispatcher $dispatcher,
        private ?PSR7Worker $psr7 = null,
        private ?Closure $notFoundHandler = null,
    ) {
        $this->psr7 = $psr7;
    }

    /**
     * Lazily create the PSR-7 worker from the RoadRunner environment.
     * Only needed for the run() loop; dispatch() works without it.
     */
    private function psr7(): PSR7Worker
    {
        return $this->psr7 ??= new PSR7Worker(
            Worker::create(),
            new Psr17Factory(),
            new Psr17Factory(),
            new Psr17Factory(),
        );
    }

    /**
     * Run the worker loop until RoadRunner signals shutdown (null request).
     */
    public function run(): void
    {
        $psr7 = $this->psr7();

        while (true) {
            try {
                $request = $psr7->waitRequest();

                if ($request === null) {
                    break;
                }
            } catch (Throwable $e) {
                // A malformed request must not kill the worker.
                $psr7->respond(new Response(400));
                continue;
            }

            try {
                $response = $this->dispatch($request);
                $psr7->respond($response);
            } catch (Throwable $e) {
                // Send an error response and keep the worker alive.
                $psr7->respond(new Response(500, [], 'Internal Server Error'));
            } finally {
                // Reset per-request state BEFORE reading the next request.
                $this->context->clearRequestScope();
            }
        }
    }

    /**
     * Run the Router + Dispatcher pipeline against a single PSR-7 request.
     *
     * @return PsrResponseInterface The PSR-7 response to send.
     */
    public function dispatch(ServerRequestInterface $request): PsrResponseInterface
    {
        $azeraRequest = PsrToAzeraRequest::convert($request);

        // Make the translated request available on the context.
        $this->context->set(AzeraRequest::class, $azeraRequest);

        $path   = $azeraRequest->path();
        $method = $azeraRequest->method();

        $route = $this->router->match($path, $method);

        if ($route === null) {
            if ($this->notFoundHandler) {
                $response = ($this->notFoundHandler)($path, $method);
                if ($response === null) {
                    $response = AzeraResponse::status(404);
                } elseif (!$response instanceof AzeraResponse) {
                    throw new \RuntimeException('NotFound handler must return an Azera\Http\Response or null.');
                }
            } else {
                $response = AzeraResponse::status(404);
            }
        } else {
            $response = $this->dispatcher->dispatch($route);
        }

        return new Response(
            $response->getStatus(),
            $response->getHeaders(),
            $response->getBody()
        );
    }
}