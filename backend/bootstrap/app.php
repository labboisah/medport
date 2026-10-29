<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\AuthenticateIntegration;
use App\Http\Middleware\EnsureAccount;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [AssignCorrelationId::class]);
        // Global, so error responses for unmatched routes carry the headers too.
        $middleware->append(SecurityHeaders::class);
        $middleware->throttleApi('120,1');
        $middleware->alias(['account' => EnsureAccount::class, 'integration' => AuthenticateIntegration::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A database exception message embeds the SQL and its bound values, which can hold patient data. Log the class and state only.
        $exceptions->report(function (QueryException $e) {
            logger()->error('database error', ['sql_state' => $e->getCode(), 'exception' => $e::class]);
        })->stop();

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Consistent error envelope; never include exception messages or traces for 5xx.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = match (true) {
                $e instanceof ValidationException => 422,
                $e instanceof AuthenticationException => 401,
                $e instanceof ModelNotFoundException => 404,
                $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };
            $code = match ($status) {
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                422 => 'validation_failed',
                429 => 'rate_limited',
                default => $status >= 500 ? 'server_error' : 'request_failed',
            };

            $body = [
                'error' => [
                    'code' => $code,
                    'message' => $status >= 500 ? 'An unexpected error occurred.' : ($e instanceof ValidationException ? 'The given data was invalid.' : (Response::$statusTexts[$status] ?? 'Request failed.')),
                    'correlation_id' => $request->attributes->get('correlation_id'),
                ],
            ];
            if ($e instanceof ValidationException) {
                $body['error']['fields'] = $e->errors();
            }

            return response()->json($body, $status, $e instanceof HttpExceptionInterface ? $e->getHeaders() : []);
        });
    })->create();
