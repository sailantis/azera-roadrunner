<?php

declare(strict_types=1);

namespace Azera\RR\Tests\Http\Adapter;

use Azera\Http\Response as AzeraResponse;
use Azera\RR\Http\Adapter\PsrToAzeraRequest;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;

final class PsrToAzeraRequestTest extends TestCase
{
    public function testConvertsPsrRequestToAzeraRequest(): void
    {
        $psr = new ServerRequest('POST', 'https://example.com/users/42?active=1', [
            'Content-Type' => 'application/json',
            'X-Custom'     => 'yes',
        ], Stream::create('{"name":"Ada"}'), '1.1');
        $psr = $psr->withParsedBody(['name' => 'Ada']);

        $azera = PsrToAzeraRequest::convert($psr);

        $this->assertSame('POST', $azera->method());
        $this->assertSame('/users/42', $azera->path());
        $this->assertSame(['active' => '1'], $azera->query());
        $this->assertSame('{"name":"Ada"}', $azera->body());
        $this->assertSame(['name' => 'Ada'], $azera->post());
    }

    public function testMapsHeadersToHttpConvention(): void
    {
        $psr = new ServerRequest('GET', 'https://example.com/', [
            'Content-Type' => 'text/html',
        ]);

        $azera = PsrToAzeraRequest::convert($psr);

        $this->assertSame('text/html', $azera->header('Content-Type'));
    }

    public function testTrustsProxyHeadersForSchemeHostAndClientIp(): void
    {
        $psr = new ServerRequest('GET', 'http://internal:8001/', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host'  => 'sailantis.dev',
            'X-Forwarded-For'   => '203.0.113.7, 10.0.0.1',
        ], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1']);

        $azera = PsrToAzeraRequest::convert($psr);

        $this->assertTrue($azera->isSecure());
        $this->assertSame('https', $azera->scheme());
        $this->assertSame('sailantis.dev', $azera->host());
        $this->assertSame('203.0.113.7', $azera->clientIp(true));
        // REMOTE_ADDR stays the immediate peer (the proxy), not the client.
        $this->assertSame('10.0.0.1', $azera->server('REMOTE_ADDR'));
    }

    public function testSetsHttpsServerVarWhenTlsTerminatedUpstream(): void
    {
        $psr = new ServerRequest('GET', 'http://internal:8001/', [
            'X-Forwarded-Proto' => 'https',
        ]);

        $azera = PsrToAzeraRequest::convert($psr);

        $this->assertSame('on', $azera->server('HTTPS'));
    }

    public function testDoesNotTrustProxyHeadersWhenAbsent(): void
    {
        $psr = new ServerRequest('GET', 'http://internal:8001/', [], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1']);

        $azera = PsrToAzeraRequest::convert($psr);

        $this->assertFalse($azera->isSecure());
        $this->assertSame('http', $azera->scheme());
        $this->assertSame('10.0.0.1', $azera->clientIp(true));
    }
}

final class AzeraToPsrResponseTest extends TestCase
{
    public function testConvertsAzeraResponseToPsr(): void
    {
        $azera = (new AzeraResponse())
            ->setStatus(201)
            ->setHeader('Content-Type', 'application/json')
            ->write('{"ok":true}');

        $psr = new Psr7Response(
            $azera->getStatus(),
            $azera->getHeaders(),
            $azera->getBody()
        );

        $this->assertSame(201, $psr->getStatusCode());
        $this->assertSame('application/json', $psr->getHeaderLine('Content-Type'));
        $this->assertSame('{"ok":true}', (string) $psr->getBody());
    }
}