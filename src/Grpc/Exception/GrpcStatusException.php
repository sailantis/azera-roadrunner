<?php

declare(strict_types=1);

namespace Azera\RR\Grpc\Exception;

use Spiral\RoadRunner\GRPC\StatusCode;

/**
 * Maps a thrown exception to a gRPC status code.
 *
 * Throw this from a gRPC service handler to control the status code the
 * client receives. Handlers may also throw any other \Throwable; the worker
 * maps unknown exceptions to {@see StatusCode::INTERNAL}.
 */
final class GrpcStatusException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $statusCode = StatusCode::INTERNAL,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function notFound(string $message = 'Not Found'): self
    {
        return new self($message, StatusCode::NOT_FOUND);
    }

    public static function invalidArgument(string $message = 'Invalid Argument'): self
    {
        return new self($message, StatusCode::INVALID_ARGUMENT);
    }

    public static function alreadyExists(string $message = 'Already Exists'): self
    {
        return new self($message, StatusCode::ALREADY_EXISTS);
    }

    public static function permissionDenied(string $message = 'Permission Denied'): self
    {
        return new self($message, StatusCode::PERMISSION_DENIED);
    }

    public static function unauthenticated(string $message = 'Unauthenticated'): self
    {
        return new self($message, StatusCode::UNAUTHENTICATED);
    }

    public static function failedPrecondition(string $message = 'Failed Precondition'): self
    {
        return new self($message, StatusCode::FAILED_PRECONDITION);
    }

    public static function resourceExhausted(string $message = 'Resource Exhausted'): self
    {
        return new self($message, StatusCode::RESOURCE_EXHAUSTED);
    }

    public static function internal(string $message = 'Internal'): self
    {
        return new self($message, StatusCode::INTERNAL);
    }

    /**
     * The gRPC status code for this exception.
     */
    public function getGrpcStatusCode(): int
    {
        return $this->statusCode;
    }
}