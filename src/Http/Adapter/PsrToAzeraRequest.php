<?php

declare(strict_types=1);

namespace Azera\RR\Http\Adapter;

use Azera\Http\Request as AzeraRequest;
use Azera\Http\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Converts a PSR-7 server request (as produced by RoadRunner's HTTP worker)
 * into an Azera {@see AzeraRequest}.
 *
 * RoadRunner hands us a PSR-7 {@see ServerRequestInterface}. Azera's own
 * Request abstracts PHP superglobals, so to reuse the Azera Router/Dispatcher
 * pipeline unchanged we translate the PSR-7 message into the equivalent
 * server/GET/POST/files arrays that Azera's Request understands.
 */
final class PsrToAzeraRequest
{
    /**
     * Build an Azera Request from a PSR-7 server request.
     */
    public static function convert(ServerRequestInterface $psr): AzeraRequest
    {
        $server = self::buildServerArray($psr);
        $get    = self::buildGetArray($psr);
        $post   = self::buildPostArray($psr);
        $files  = self::buildFilesArray($psr);

        // RoadRunner typically sits behind a reverse proxy (nginx). Enable the
        // framework's built-in proxy-header handling so scheme()/host()/port()/
        // clientIp() respect X-Forwarded-* exactly as they do under PHP-FPM.
        $request = new AzeraRequest($server, $get, $post, $files, true);

        // php://input is not available inside a RoadRunner worker, so inject
        // the PSR-7 body into Azera's raw-body cache via reflection. This
        // makes body() and jsonBody() behave as they do under PHP-FPM.
        $body = (string) $psr->getBody();
        if ($body !== '') {
            self::setRawBody($request, $body);
        }

        return $request;
    }

    /**
     * Reconstruct the $_SERVER-style array used by Azera's Request.
     */
    private static function buildServerArray(ServerRequestInterface $psr): array
    {
        $server = [
            'REQUEST_METHOD' => $psr->getMethod(),
            'REQUEST_URI'    => $psr->getUri()->getPath()
                . ($psr->getUri()->getQuery() !== '' ? '?' . $psr->getUri()->getQuery() : ''),
            'QUERY_STRING'       => $psr->getUri()->getQuery(),
            'REQUEST_TIME_FLOAT' => microtime(true),
            'SERVER_PROTOCOL'    => 'HTTP/' . $psr->getProtocolVersion(),
            'HTTP_HOST'          => $psr->getUri()->getHost(),
            // REMOTE_ADDR is the immediate peer (the proxy). The real client
            // IP is resolved from X-Forwarded-For by Request::clientIp() when
            // trustProxyHeaders is enabled. Only fall back to XFF if the peer
            // address is unavailable.
            'REMOTE_ADDR' => $psr->getServerParams()['REMOTE_ADDR']
                ?? self::firstHeader($psr, 'x-forwarded-for')
                    ?? '',
        ];

        // Map PSR-7 headers into the HTTP_* convention Azera uses.
        foreach ($psr->getHeaders() as $name => $values) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $server[$key] = implode(', ', $values);
        }

        // Mirror nginx's HTTPS=on when TLS is terminated upstream, so code
        // that checks $_SERVER['HTTPS'] directly behaves as under PHP-FPM.
        $proto = $server['HTTP_X_FORWARDED_PROTO'] ?? null;
        if ($proto && strtolower(explode(',', $proto)[0]) === 'https') {
            $server['HTTPS'] = 'on';
        }

        return $server;
    }

    private static function buildGetArray(ServerRequestInterface $psr): array
    {
        $query = $psr->getQueryParams();
        return is_array($query) ? $query : [];
    }

    private static function buildPostArray(ServerRequestInterface $psr): array
    {
        $parsed = $psr->getParsedBody();
        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Reconstruct the $_FILES-style array used by Azera's Request.
     *
     * @return array<string, UploadedFile>
     */
    private static function buildFilesArray(ServerRequestInterface $psr): array
    {
        $result = [];

        foreach ($psr->getUploadedFiles() as $field => $file) {
            $result[$field] = new UploadedFile(
                $file->getClientFilename() ?? '',
                $file->getClientMediaType() ?? '',
                self::materializeUpload($file),
                $file->getError(),
                $file->getSize() ?? 0
            );
        }

        return $result;
    }

    /**
     * Persist a PSR-7 uploaded-file stream to a temp file and return its
     * path, so Azera's UploadedFile can move/copy it as under FPM.
     */
    private static function materializeUpload(\Psr\Http\Message\UploadedFileInterface $file): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'azera_rr_');
        if ($tmp === false) {
            return '';
        }

        $stream = $file->getStream();
        $handle = fopen($tmp, 'wb');
        if ($handle !== false) {
            while (!$stream->eof()) {
                fwrite($handle, $stream->read(8192));
            }
            fclose($handle);
        }

        return $tmp;
    }

    /**
     * Inject the request body into Azera's rawBody cache.
     */
    private static function setRawBody(AzeraRequest $request, string $body): void
    {
        $ref  = new \ReflectionObject($request);
        $prop = $ref->getProperty('rawBody');
        $prop->setAccessible(true);
        $prop->setValue($request, $body);
    }

    private static function firstHeader(ServerRequestInterface $psr, string $name): ?string
    {
        $values = $psr->getHeader($name);
        return $values[0] ?? null;
    }
}