<?php

declare(strict_types=1);

namespace RossAddison\BitPayClient\Exception;

/**
 * Thrown when BitPay responds with a non-2xx status, or with a 2xx that
 * doesn't carry the `data` envelope every successful BitPay response uses.
 * Carries the raw response body so a caller can surface BitPay's own error
 * detail (its error responses carry a human-readable `error`/`message`
 * field) rather than just an HTTP status code.
 */
final class BitPayApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $responseBody,
    ) {
        parent::__construct("BitPay API responded {$statusCode}: {$responseBody}");
    }
}
