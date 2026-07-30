<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\RequirePermission;
use App\Support\ApiErrorResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->append(AssignRequestId::class);
        $middleware->alias(['permission' => RequirePermission::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $isApi = fn (Request $request) => $request->is('api/*')
            || $request->expectsJson();
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception) => $isApi($request),
        );
        $exceptions->render(function (
            ValidationException $exception,
            Request $request,
        ) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return ApiErrorResponse::make(
                $request,
                $exception->getMessage(),
                'VALIDATION_FAILED',
                422,
                $exception->errors(),
            );
        });
        $exceptions->render(function (
            AuthenticationException $exception,
            Request $request,
        ) use ($isApi) {
            return $isApi($request)
                ? ApiErrorResponse::make(
                    $request,
                    'Authentication is required.',
                    'UNAUTHENTICATED',
                    401,
                )
                : null;
        });
        $exceptions->render(function (
            AuthorizationException $exception,
            Request $request,
        ) use ($isApi) {
            return $isApi($request)
                ? ApiErrorResponse::make(
                    $request,
                    'Permission denied.',
                    'FORBIDDEN',
                    403,
                )
                : null;
        });
        $exceptions->render(function (
            ModelNotFoundException|NotFoundHttpException $exception,
            Request $request,
        ) use ($isApi) {
            return $isApi($request)
                ? ApiErrorResponse::make(
                    $request,
                    'The requested record was not found.',
                    'NOT_FOUND',
                    404,
                )
                : null;
        });
        $exceptions->render(function (
            HttpExceptionInterface $exception,
            Request $request,
        ) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            $status = $exception->getStatusCode();
            $message = $exception->getMessage() ?: match ($status) {
                401 => 'Authentication is required.',
                403 => 'Permission denied.',
                404 => 'The requested record was not found.',
                409 => 'The requested state transition is not allowed.',
                default => 'The request could not be completed.',
            };

            return ApiErrorResponse::make(
                $request,
                $message,
                ApiErrorResponse::codeForStatus($status),
                $status,
            );
        });
        $exceptions->render(function (
            Throwable $exception,
            Request $request,
        ) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            Log::error('Unexpected API failure.', [
                'request_id' => $request->attributes->get('request_id'),
                'exception' => $exception::class,
                'path' => $request->path(),
                'user_id' => $request->user()?->id,
            ]);

            return ApiErrorResponse::make(
                $request,
                'An unexpected error occurred.',
                'INTERNAL_ERROR',
                500,
            );
        });
    })
    ->withCommands()
    ->create();
