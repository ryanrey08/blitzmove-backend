<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Raised when a request is valid but conflicts with the current state of the
 * data (e.g. a driver already booked, an order that can no longer be edited).
 */
class BusinessRuleException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(string $message, protected array $errors = [], protected int $status = 422)
    {
        parent::__construct($message);
    }

    public static function withField(string $field, string $message, int $status = 422): self
    {
        return new self($message, [$field => [$message]], $status);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'errors' => $this->errors ?: null,
        ]), $this->status);
    }
}
