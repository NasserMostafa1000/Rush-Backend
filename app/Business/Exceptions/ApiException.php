<?php

namespace App\Business\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 400,
        public readonly ?array $errors = null,
        public readonly ?array $meta = null,
    ) {
        parent::__construct($message);
    }

    public static function make(
        string $code,
        string $message,
        int $status,
        ?array $meta = null,
        ?array $errors = null,
    ): self {
        return new self($message, $code, $status, $errors, $meta);
    }

    public function payload(): array
    {
        return ApiError::payload($this->getMessage(), $this->errorCode, $this->errors, $this->meta);
    }
}
