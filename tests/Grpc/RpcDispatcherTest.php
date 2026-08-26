<?php

declare(strict_types=1);

namespace Azera\RR\Tests\Grpc;

use Azera\AppContext;
use Azera\RR\Grpc\RpcDispatcher;
use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\ServiceInterface;

// Minimal generated-style interface for testing (mirrors what protoc emits).
interface GreeterInterface extends ServiceInterface
{
    public const NAME = 'helloworld.Greeter';

    public function SayHello(ContextInterface $ctx, \Google\Protobuf\Internal\Message $in): \Google\Protobuf\Internal\Message;
}

class GreeterService implements GreeterInterface
{
    public function SayHello(ContextInterface $ctx, \Google\Protobuf\Internal\Message $in): \Google\Protobuf\Internal\Message
    {
        return $in;
    }
}

final class RpcDispatcherTest extends TestCase
{
    public function testAcceptsInstanceHandler(): void
    {
        $dispatcher = new RpcDispatcher(AppContext::instance());

        // Should not throw.
        $dispatcher->service(GreeterInterface::class, new GreeterService());

        $server = $dispatcher->getServer();
        $this->assertNotNull($server);
    }

    public function testAcceptsClassNameHandler(): void
    {
        $dispatcher = new RpcDispatcher(AppContext::instance());

        $dispatcher->service(GreeterInterface::class, GreeterService::class);

        $this->assertNotNull($dispatcher->getServer());
    }

    public function testRejectsIncompatibleHandler(): void
    {
        $dispatcher = new RpcDispatcher(AppContext::instance());

        $this->expectException(\InvalidArgumentException::class);
        $dispatcher->service(GreeterInterface::class, new class implements ServiceInterface
            {
            });
    }
}