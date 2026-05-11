<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum stateful domains (not needed for pure API — we use token-only auth)
        // $middleware->statefulApi();

        // Alias custom middleware
        $middleware->alias([
            'auth.admin' => \App\Http\Middleware\EnsureAdminToken::class,
            'auth.user'  => \App\Http\Middleware\EnsureUserToken::class,
        ]);

        // Security headers on every response
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Render API validation errors as JSON
        $middleware->validateCsrfTokens(except: ['api/*', 'webhook/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Return JSON for all API errors
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error'  => 'Validation failed',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => 'Not found'], 404);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => 'Method not allowed'], 405);
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            // HttpResponseException carries its own response (e.g., from rate limiters).
            // Return null so Laravel's default handler unwraps $e->getResponse().
            if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return null;
            }

            if ($request->is('api/*')) {
                $debug = config('app.debug');
                return response()->json([
                    'error'   => $debug ? $e->getMessage() : 'Server error',
                    'message' => $debug ? $e->getMessage() : 'An unexpected error occurred.',
                ], 500);
            }
        });
    })->create();
