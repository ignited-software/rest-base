<?php

namespace Ignited\RestBase;

use RuntimeException;

/**
 * Every refusal the API makes, rendered as {"error": {status, code, reason, message, details}}. `code` follows the
 * HTTP status; `reason` is the specific, stable subcode a client can branch on (null for a generic error).
 */
class ApiException extends RuntimeException
{
    public const CODES = [
        400 => 'bad_request',
        401 => 'unauthorized',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        415 => 'unsupported_media_type',
        422 => 'unprocessable_entity',
        429 => 'too_many_requests',
        500 => 'server_error',
        503 => 'service_unavailable',
    ];

    /** @param  array<string, list<string>>  $details  field (or `index.field`) => messages */
    public function __construct(
        public readonly int $status,
        public readonly ?string $reason,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $reason, string $message, array $details = []): self
    {
        return new self(422, $reason, $message, $details);
    }

    public function code(): string
    {
        return self::CODES[$this->status] ?? ($this->status >= 500 ? 'server_error' : 'bad_request');
    }

    /** @return array{error: array<string, mixed>} */
    public function toArray(): array
    {
        return ['error' => [
            'status' => $this->status,
            'code' => $this->code(),
            'reason' => $this->reason,
            'message' => $this->getMessage(),
            'details' => (object) $this->details,
        ]];
    }
}
