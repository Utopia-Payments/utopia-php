<?php

declare(strict_types=1);

namespace Utopia\Exception;

/**
 * Base class for every error the library throws. getErrorCode() returns the
 * API's machine-readable code, such as NOT_FOUND or CURRENCY_NOT_SUPPORTED.
 */
class UtopiaException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'UNKNOWN',
        private readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /** @param array<string, mixed> $body */
    public static function fromResponse(int $status, array $body): self
    {
        $message = (string) ($body['message'] ?? "Request failed with status {$status}");
        $code = (string) ($body['code'] ?? "HTTP_{$status}");
        return match (true) {
            $status === 401 => new AuthenticationException($message, $code, $status),
            $status === 403 => new PermissionDeniedException($message, $code, $status),
            $status === 404 => new NotFoundException($message, $code, $status),
            $status === 409 => new ConflictException($message, $code, $status),
            $status === 429 => new RateLimitException($message, $code, $status),
            $status >= 500 => new ApiException($message, $code, $status),
            default => new InvalidRequestException($message, $code, $status),
        };
    }
}
