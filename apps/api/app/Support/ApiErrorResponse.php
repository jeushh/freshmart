<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiErrorResponse
{
    public static function make(
        Request $request,
        string $message,
        string $code,
        int $status,
        array $errors = [],
    ): JsonResponse {
        return response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => (object) $errors,
            'request_id' => (string) $request->attributes->get('request_id', ''),
        ], $status);
    }

    public static function codeForStatus(int $status): string
    {
        return match ($status) {
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'STATE_CONFLICT',
            422 => 'UNPROCESSABLE_ENTITY',
            429 => 'RATE_LIMITED',
            default => $status >= 500 ? 'INTERNAL_ERROR' : 'REQUEST_FAILED',
        };
    }
}
