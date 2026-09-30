<?php

namespace App\Support\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * One JSON error shape for every API response (SECURITY.md §8):
 * { "error": { "code", "message", "fields"?, "requestId" } } — never a stack trace.
 * Unexpected errors are logged server-side with the request id and a code.
 */
final class ApiErrorRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->dontReport([ApiException::class]);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
        $exceptions->render(fn (Throwable $e, Request $request) => self::render($e, $request));
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        [$code, $replace, $extra] = self::classify($e);

        if ($code === ErrorCode::SERVER_ERROR) {
            Log::error('SERVER_ERROR', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'path' => $request->path(),
            ]);
        }

        $error = ['code' => $code->value, 'message' => $code->message($replace)];
        if (isset($extra['fields'])) {
            $error['fields'] = $extra['fields'];
        }
        $error['requestId'] = $request->attributes->get('request_id');

        $headers = [];
        if ($e instanceof HttpExceptionInterface) {
            $headers = array_intersect_key($e->getHeaders(), array_flip(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining']));
        }

        return new JsonResponse(['error' => $error], $code->status(), $headers);
    }

    /** @return array{0: ErrorCode, 1: array, 2: array} */
    private static function classify(Throwable $e): array
    {
        return match (true) {
            $e instanceof ApiException => [$e->errorCode, $e->replace, $e->extra],
            $e instanceof ValidationException => [ErrorCode::VALIDATION_FAILED, [], ['fields' => $e->errors()]],
            $e instanceof AuthenticationException => [ErrorCode::UNAUTHENTICATED, [], []],
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => [ErrorCode::FORBIDDEN, [], []],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [ErrorCode::NOT_FOUND, [], []],
            $e instanceof MethodNotAllowedHttpException => [ErrorCode::METHOD_NOT_ALLOWED, [], []],
            $e instanceof ThrottleRequestsException => [ErrorCode::RATE_LIMITED, [], []],
            $e instanceof TokenMismatchException => [ErrorCode::UNAUTHENTICATED, [], []],
            $e instanceof OriginMismatchException => [ErrorCode::FORBIDDEN, [], []],
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 419 => [ErrorCode::UNAUTHENTICATED, [], []],
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 404 => [ErrorCode::NOT_FOUND, [], []],
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 403 => [ErrorCode::FORBIDDEN, [], []],
            default => [ErrorCode::SERVER_ERROR, [], []],
        };
    }
}
