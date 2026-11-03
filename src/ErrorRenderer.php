<?php

namespace Ignited\RestBase;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Every error under the API's prefix in one shape, {"error": {status, code, reason, message, details}}: the
 * API's own refusals, and the app's too (a 401 from its auth middleware, a 403, a 429 from its throttle with
 * Retry-After, a 404 for an unknown path). Anything unexpected is a 500 without internals.
 */
final class ErrorRenderer
{
    public static function applies(Request $request): bool
    {
        $prefix = trim((string) config('rest-base.prefix', 'api/v2'), '/');

        return config('rest-base.enabled') && ($request->is($prefix) || $request->is($prefix.'/*'));
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        // an HttpResponseException carries its own response (abort(response(...)), a FormRequest): Laravel sends it
        if (! self::applies($request) || $e instanceof HttpResponseException) {
            return null;
        }
        $error = self::toApi($e);
        if ($error->status >= 500) {
            report($e);
        }

        return new JsonResponse($error->toArray(), $error->status, $error->headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function toApi(Throwable $e): ApiException
    {
        return match (true) {
            $e instanceof ApiException => $e,
            $e instanceof AuthenticationException => new ApiException(401, null, 'Send a valid token: Authorization: Bearer <token>.'),
            $e instanceof AuthorizationException => new ApiException(403, null, $e->getMessage() ?: 'This caller may not do this.'),
            // Laravel wraps these in an HttpException before the callbacks run; the wrapped message names the model class
            $e instanceof ModelNotFoundException, $e->getPrevious() instanceof ModelNotFoundException => new ApiException(404, null, 'No such record.'),
            $e instanceof ValidationException => new ApiException(422, 'invalid_attributes', $e->getMessage(), $e->errors()),
            $e instanceof HttpExceptionInterface => new ApiException(
                $e->getStatusCode(),
                null,
                $e->getMessage() !== '' ? $e->getMessage() : match ($e->getStatusCode()) {
                    404 => 'No such endpoint.',
                    405 => 'This endpoint does not take that method.',
                    429 => 'Too many requests; wait and try again.',
                    default => 'The request was refused.',
                },
                headers: $e->getHeaders(),
            ),
            default => new ApiException(500, null, config('app.debug') ? $e->getMessage() : 'Something went wrong on our side.'),
        };
    }
}
