# Changelog

All notable changes to `sailantis/azera-roadrunner` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.2] - 2026-11-16

First tagged release. Version numbers follow `sailantis/azera-framework`.

### Added

- HTTP and gRPC workers that reuse the framework's `AppContext` container,
  `Router`, `Dispatcher` and middleware pipeline across persistent PHP workers.
- `PsrToAzeraRequest`, which translates a PSR-7 server request into the
  superglobal-shaped arrays `Azera\Http\Request` expects.
- Dump-halt mode: a `dump()`/`dd()` in the application becomes a response and
  the worker stays alive, instead of the process dying mid-request.
- Sessions across requests. A worker is not a SAPI, so the cookie that carries
  a session id in and the `Set-Cookie` that carries it back out are the
  adapter's job; `Azera\RR\Http\WorkerSession` does both. `SessionMiddleware`
  still decides whether a session is started, exactly as under FPM.
- `Request::setRawBody()` is used to inject the PSR-7 body, replacing the earlier
  reflection hack.

### Fixed

- `PsrToAzeraRequest` mapped every header to `HTTP_*`, leaving `CONTENT_TYPE`
  unset. `contentType()` and `isJson()` therefore saw nothing, so a JSON body
  was never recognised as JSON, while `header('Content-Type')` answered from
  `HTTP_CONTENT_TYPE` — two answers to one question. `Content-Type` and
  `Content-Length` now become the unprefixed CGI variables, which is what a SAPI
  actually produces.

### Notes

`Azera\RR\Http\WorkerSession` was previously `Azera\Http\WorkerSession` in the
framework. Core removed it because a worker's request lifecycle belongs to the
adapter that defines it; the volucer adapter uses `Volucer\Session` from the
worker SDK, and RoadRunner has no such SDK, so the class now lives here. A
version of this package that depended on `Azera\Http\WorkerSession` cannot run
against any released framework.
