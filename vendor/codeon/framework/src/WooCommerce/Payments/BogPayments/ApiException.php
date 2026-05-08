<?php

declare(strict_types=1);

namespace CodeOn\Framework\WooCommerce\Payments\BogPayments;

use Exception;

/**
 * Thrown by {@see Client} when BOG's Payments API returns a non-2xx
 * response or the underlying HTTP transport fails.
 *
 * Carries the response status, decoded body (when available), and an
 * `errorCode()` slot that consumers fill with bank-side error labels
 * (`'transport_error'`, `'token_invalid'`, etc.) for log routing.
 */
final class ApiException extends Exception
{
    /** @param array<string,mixed> $body */
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly array $body = [],
        private readonly string $errorCode = '',
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string,mixed> */
    public function responseBody(): array
    {
        return $this->body;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
