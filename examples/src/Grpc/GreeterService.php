<?php

namespace App\Grpc;

use Azera\RR\Grpc\Service;
use GRPC\Greeter\GreeterInterface;
use GRPC\Greeter\HelloReply;
use GRPC\Greeter\HelloRequest;
use Spiral\RoadRunner\GRPC\ContextInterface;

/**
 * Example gRPC service handler.
 *
 * Extends Azera's {@see Service} base for convenient access to the context
 * (db, cache, logger, events), and implements the generated service
 * interface. Methods follow the standard gRPC signature:
 *
 *   public function <RpcName>(ContextInterface $ctx, <Request> $in): <Response>
 *
 * Throw {@see \Azera\RR\Grpc\Exception\GrpcStatusException} from a handler to
 * control the status code returned to the client.
 */
class GreeterService extends Service implements GreeterInterface
{
    public function SayHello(ContextInterface $ctx, HelloRequest $in): HelloReply
    {
        $name = $in->getName();

        // Handlers have access to the persistent Azera services:
        // $this->db(), $this->cache(), $this->logger(), $this->events().

        return new HelloReply([
            'message' => 'Hello ' . $name . '!',
        ]);
    }
}