<?php

namespace App\Business\Exceptions;

use Illuminate\Http\JsonResponse;

class ApiError
{
    public static function payload(string $message, string $code, ?array $errors = null, ?array $meta = null): array
    {
        $body = ['message' => $message, 'code' => $code];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }
        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return $body;
    }

    public static function response(
        string $message,
        string $code,
        int $status,
        ?array $errors = null,
        ?array $meta = null,
        array $headers = [],
    ): JsonResponse {
        return response()->json(self::payload($message, $code, $errors, $meta), $status, $headers);
    }
}
