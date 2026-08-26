<?php

declare(strict_types=1);

namespace Example\Http;

use Azera\Lifecycle\RequestScoped;

/**
 * A per-request "current user" service.
 *
 * Under PHP-FPM this would be a fresh object every request. Under RoadRunner
 * the worker (and thus the AppContext singleton) stays alive across requests,
 * so this service MUST implement {@see RequestScoped} and clear its state in
 * resetState(). It is flushed automatically by the worker loop (via
 * AppContext::clearRequestScope()) after every request, before the next one
 * is read.
 *
 * Register it on the AppContext once at boot (see worker snippet at the bottom
 * of this file). Persistent infrastructure — DB, cache, queue — is NOT touched.
 */
final class CurrentUser implements RequestScoped
{
    /** @var object|null The authenticated user for the current request. */
    private ?object $user = null;

    /** @var array<string, mixed> Per-request attribute bag. */
    private array $attributes = [];

    /**
     * Set the authenticated user for the current request.
     */
    public function setUser(?object $user): void
    {
        $this->user = $user;
    }

    /**
     * The authenticated user, or null when unauthenticated.
     */
    public function user(): ?object
    {
        return $this->user;
    }

    /**
     * True when a user is authenticated for the current request.
     */
    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Called by the worker (via AppContext::clearRequestScope()) after every
     * request. Drop the user and the attribute bag so nothing leaks into the
     * next request.
     */
    public function resetState(): void
    {
        $this->user       = null;
        $this->attributes = [];
    }
}