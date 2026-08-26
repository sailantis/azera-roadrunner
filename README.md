# azera-roadrunner

Application-server adapter that lets [Azera](https://github.com/Sailantis/azera-framework) run on top of
[RoadRunner](https://roadrunner.dev). RoadRunner (a Go application server) runs persistent PHP workers,
so your app boots **once** and stays resident, reusing database/cache/queue connections across requests —
while Azera's Router, Dispatcher, DI container and middleware pipeline work exactly as they do under PHP-FPM.

This package serves **both**:

- **HTTP** — your existing website, reusing the full `Router → Dispatcher → Controller` pipeline and the ViewEngine.
- **gRPC** — RPC services, with an Azera-style service mapper over RoadRunner's gRPC plugin.

Both listeners run in one RoadRunner process, over one worker pool, sharing a single bootstrapped `AppContext`.

---

## Installation

```bash
composer require sailantis/azera-roadrunner
```

Download the RoadRunner binary:

```bash
./vendor/bin/rr get-binary
```

> Requires PHP >= 8.1 and the `sailantis/azera-framework` package.

---

## Quick start (HTTP)

1. Copy `.rr.yaml` to your application root and point the `http.static.dir` at your `public/` folder.
2. Start the server:

```bash
./rr serve
```

Your existing routes, controllers, middleware and templates now run under RoadRunner with no code changes.
Each worker bootstraps the `AppContext` once and resets per-request state between requests.

---

## How it works

RoadRunner launches one PHP worker process per pool member and tells each worker which plugin it serves
via the `RR_MODE` environment variable (`http`, `grpc`, …). The shared entry point `azera-worker.php`
detects that mode and starts the matching worker loop:

```text
RoadRunner (Go)
 ├─ http listener ──► RR_MODE=http  ──► Azera\RR\Http\RoadRunnerHttpWorker
 └─ grpc listener ──► RR_MODE=grpc ──► Azera\RR\Grpc\RoadRunnerGrpcWorker
```

Both loops share the same bootstrapped `AppContext` (via `Azera\RR\WorkerBootstrap`) and reset per-request
state after every request.

### HTTP worker

Each incoming PSR-7 request is translated to an Azera `Request` (`Azera\RR\Http\Adapter\PsrToAzeraRequest`),
matched through the router, dispatched through the controller/middleware pipeline, and the resulting Azera
`Response` is translated back to PSR-7 (`Azera\RR\Http\Adapter\AzeraToPsrResponse`) and sent.

### gRPC worker

gRPC services are registered per service (a gRPC service is a fixed contract, unlike free-form HTTP routes).
Register generated service interfaces against handler classes:

```php
<?php // grpc-services.php (project root)

use Azera\RR\Grpc\RpcDispatcher;

return static function (RpcDispatcher $rpc): void {
    $rpc->service(
        \GRPC\Greeter\GreeterInterface::class,
        \App\Grpc\GreeterService::class,   // class name: auto-wired via AppContext DI
    );
};
```

Handlers may extend `Azera\RR\Grpc\Service` for convenient access to `db()`, `cache()`, `logger()`, `events()`:

```php
<?php
namespace App\Grpc;

use Azera\RR\Grpc\Service;
use GRPC\Greeter\GreeterInterface;
use GRPC\Greeter\HelloReply;
use GRPC\Greeter\HelloRequest;
use Spiral\RoadRunner\GRPC\ContextInterface;

class GreeterService extends Service implements GreeterInterface
{
    public function SayHello(ContextInterface $ctx, HelloRequest $in): HelloReply
    {
        return new HelloReply(['message' => 'Hello ' . $in->getName() . '!']);
    }
}
```

Control the status code returned to clients by throwing `Azera\RR\Grpc\Exception\GrpcStatusException`
(e.g. `GrpcStatusException::notFound('User not found')`). Unknown exceptions map to `INTERNAL`.

---

## Configuration

The package ships a `config/.rr.yaml` and a `config/grpc-services.example.php`.

### Per-request state reset

Because workers are long-lived, request-scoped state must be reset between requests. The worker loops call
`Azera\AppContext::clearRequestScope()` after each request, which clears Azera's built-in request-scoped
services (`Request`, `ResolvedRoute`, `Session`, `Cookies`) and flushes any service that implements
`Azera\Lifecycle\RequestScoped`.

**Persistent connections are intentionally kept alive** across requests — database manager, cache/Redis backends,
queue, logger and event dispatcher are never torn down.

If your application registers its own per-request services, make them implement `Azera\Lifecycle\RequestScoped`.
A full worked example is shipped at `examples/src/Http/CurrentUser.php`:

```php
<?php
namespace App\Http;

use Azera\Lifecycle\RequestScoped;

final class CurrentUser implements RequestScoped
{
    private ?object $user = null;
    private array $attributes = [];

    public function setUser(?object $user): void { $this->user = $user; }
    public function user(): ?object { return $this->user; }
    public function isAuthenticated(): bool { return $this->user !== null; }

    public function set(string $key, mixed $value): void { $this->attributes[$key] = $value; }
    public function get(string $key, mixed $default = null): mixed { return $this->attributes[$key] ?? $default; }

    public function resetState(): void   // ← required by RequestScoped
    {
        $this->user = null;               // drop the current-request user
        $this->attributes = [];           // and the per-request attribute bag
    }
}
```

Register it **once** at boot in your worker entry point (`rr-worker.php` / `azera-worker.php`). It is then reused
across all requests and flushed automatically after each one:

```php
use App\Http\CurrentUser;

$ctx->set(CurrentUser::class, new CurrentUser());

// In a controller, read the current user for THIS request:
$user = $ctx->get(CurrentUser::class);
if ($user->isAuthenticated()) {
    // ...
}
```

> **Rule of thumb:** implement `RequestScoped` only for services that hold per-request data. Keep persistent
> handles — DB connections, Redis/cache, queue, logger — out of it, so they stay warm across requests.

### Development hot-reload

The shipped `.rr.yaml` includes a `server.reload` section that watches your source files and automatically
restarts workers on change — a dev-only convenience. Set `server.reload` to `{}` in production.

---

## Project layout

```text
src/
├── WorkerBootstrap.php                 # resolve + run the app's BootstrapProvider once
├── Lifecycle/
├── Http/
│   ├── RoadRunnerHttpWorker.php        # persistent HTTP worker loop
│   └── Adapter/
│       ├── PsrToAzeraRequest.php       # PSR-7 → Azera\Request
│       └── AzeraToPsrResponse.php      # Azera\Response → PSR-7
└── Grpc/
    ├── RpcDispatcher.php               # Azera-style gRPC service registry
    ├── Service.php                     # base class for gRPC handlers
    ├── RoadRunnerGrpcWorker.php        # persistent gRPC worker loop
    └── Exception/GrpcStatusException.php

azera-worker.php                        # entry point dispatched by RR_MODE
examples/
└── src/
    └── Http/CurrentUser.php            # worked RequestScoped example
```

---

## Testing

```bash
composer install
composer test
```

---

## License

MIT