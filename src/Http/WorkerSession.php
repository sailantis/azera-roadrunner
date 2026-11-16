<?php

namespace Azera\RR\Http;

/**
 * Native PHP sessions for an application-server worker.
 *
 * This is the adapter's own copy. It lived in the framework as
 * `Azera\Http\WorkerSession` until that was removed from core on the grounds
 * that a worker's request lifecycle belongs to the adapter that defines it,
 * and the volucer adapter carries the equivalent in `Volucer\Session` from the
 * worker SDK. RoadRunner has no such SDK, so the behaviour lives here.
 *
 * A worker is not a SAPI, so the session half of what PHP normally does for an
 * application is missing. Under `php-cgi`/FPM the session is started from the
 * request's `Cookie` header and the cookie naming it is written into the
 * response headers; over a worker's pipe neither happens, so a long-lived
 * process with flash messages, logins or CSRF tokens silently loses its session
 * state between requests.
 *
 * Both halves are done here. The id is taken from the request's cookies before
 * the application runs, and the `Set-Cookie` for a session started during the
 * request is produced for the adapter to put on the response. The adapter owns
 * the transport — a PSR-7 header bag, a protocol frame — so this class never
 * touches the response itself.
 *
 * This is a *worker* class. Under a normal SAPI, `session_start()` already does
 * all of this and the class is unnecessary.
 */
final class WorkerSession
{
    /**
     * PHP's session id alphabet: the characters `session.sid_bits_per_character`
     * can produce. Anything else from a client is refused rather than passed to
     * the session handler, because the id becomes part of a file name.
     */
    private const ID_PATTERN = '/^[A-Za-z0-9,\-]+$/';

    /** The id the client presented, so it is not sent a cookie it already has. */
    private ?string $presented = null;

    /**
     * Adopt the session the request presented, if any.
     *
     * The session is deliberately not started here. `SessionMiddleware` starts
     * it, exactly as it does under FPM, so a request that never reaches the
     * middleware does not create one — a 404 and a static file behave as they
     * always have.
     *
     * Any session still active at this point belongs to an earlier request, so
     * it is closed and its `$_SESSION` cleared before the presented id is
     * adopted. Doing that here rather than only after a request is what makes
     * `dispatch()` correct when it is called directly — which is how both
     * adapters are tested, and how a caller is invited to use it.
     *
     * @param array<string, string> $cookies as the client sent them, i.e. `$_COOKIE`
     */
    public function begin(array $cookies): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        self::disablePhpCookies();

        $name = session_name();
        $candidate = ($name !== false && isset($cookies[$name]) && is_string($cookies[$name]))
            ? $cookies[$name]
            : null;

        $this->presented = ($candidate !== null && preg_match(self::ID_PATTERN, $candidate) === 1)
            ? $candidate
            : null;

        // Unconditional, and the reason this class exists rather than leaving
        // the cookie to PHP: `session_id()` outlives the request in a persistent
        // process, so a client arriving with no cookie would otherwise be handed
        // the id — and the session — left behind by the previous client.
        session_id($this->presented ?? '');
    }

    /**
     * Stop PHP from trying to deliver the session cookie itself.
     *
     * A worker's SAPI cannot deliver headers: `header()`, `setcookie()` and
     * `headers_list()` are all no-ops, so a `Set-Cookie` PHP writes would never
     * reach the client, and `session_start()` would waste work building it. The
     * adapter carries the cookie out instead, and this stops PHP from trying.
     *
     * Two settings, for two reasons:
     *
     * - `session.use_cookies=0` is what makes PHP skip both the cookie it cannot
     *   send and the session id it would otherwise only accept from that cookie.
     *   An id the client *did* send is still adopted — `session_id()` is set
     *   explicitly — so a returning client keeps its session.
     * - `session.cache_limiter=''` silences the `nocache` limiter, which exists
     *   only to emit `Cache-Control` headers through the same dead channel. PHP
     *   also raises a warning when it cannot send them, and a warning on the way
     *   out is noise in a worker's log.
     *
     * Both are best-effort. `ini_set()` cannot change a session setting while a
     * session is active, so a caller that started one earlier keeps its own
     * configuration — as it does under FPM, where PHP's cookie handling is
     * correct and this class is not needed at all.
     *
     * The reason this is safe to do here and not a second source of truth: the
     * adapter always emits the cookie itself, exactly when an id was created
     * that the client did not present. PHP, with cookies on, would emit one in
     * the same circumstances — so the two agree rather than overlap.
     */
    private static function disablePhpCookies(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
    }

    /**
     * The `Set-Cookie` this request earned, or null if it earned none.
     *
     * Null in the two ordinary cases: the application never started a session,
     * or the client already holds the id. Sending a cookie the client already
     * has would mean a `Set-Cookie` on every response.
     */
    public function cookie(): ?string
    {
        $id = session_id();

        if ($id === '' || $id === $this->presented) {
            return null;
        }

        return self::header($id, session_name(), session_get_cookie_params());
    }

    /**
     * End the request's session and forget its id.
     *
     * Call once per request, after the response has been built and before the
     * next request is read. PHP leaves both `session_id()` and `$_SESSION`
     * behind otherwise, and the next client must inherit neither.
     */
    public function end(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SESSION = [];
        session_id('');
        $this->presented = null;
    }

    /**
     * The `Set-Cookie` value PHP would have sent, built from PHP's own settings
     * so `session.name` and `session.cookie_*` keep working as configuration.
     *
     * Public because it is a pure function of its arguments, and pinning it in a
     * test is the only way to assert the cookie without depending on the
     * machine's `php.ini`.
     *
     * @param string|false $name   from `session_name()`
     * @param array<string, mixed> $params from `session_get_cookie_params()`
     */
    public static function header(string $id, string|false $name, array $params): string
    {
        $parts = [rawurlencode($name === false || $name === '' ? 'PHPSESSID' : $name)
            . '=' . rawurlencode($id)];

        $lifetime = (int) ($params['lifetime'] ?? 0);
        if ($lifetime > 0) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', time() + $lifetime) . ' GMT';
            $parts[] = 'Max-Age=' . $lifetime;
        }

        $parts[] = 'Path=' . self::nonEmpty($params['path'] ?? null, '/');

        $domain = self::nonEmpty($params['domain'] ?? null, '');
        if ($domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }

        if (!empty($params['secure'])) {
            $parts[] = 'Secure';
        }

        if (!empty($params['httponly'])) {
            $parts[] = 'HttpOnly';
        }

        $samesite = self::nonEmpty($params['samesite'] ?? null, '');
        if ($samesite !== '') {
            $parts[] = 'SameSite=' . $samesite;
        }

        return implode('; ', $parts);
    }

    /**
     * Whether PHP would still try to send the session cookie itself.
     *
     * True only if the settings could not be relaxed — which happens when a
     * session was already active when {@see begin()} ran. Not an error: the
     * adapter emits the cookie either way, and a duplicate is not produced
     * because PHP's own attempt cannot leave a worker's SAPI.
     */
    public static function phpCookiesEnabled(): bool
    {
        return (bool) ini_get('session.use_cookies');
    }

    private static function nonEmpty(mixed $value, string $fallback): string
    {
        return (is_string($value) && $value !== '') ? $value : $fallback;
    }
}
