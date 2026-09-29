<?php

use App\Identity\Presentation\ActiveUser;
use App\Shared\Domain\BusinessRule;
use App\Shared\Presentation\ApiErrors;
use App\Shared\Presentation\RequestContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php')
    ->withMiddleware(function (Middleware $middleware): void {
        // Render terminate TLS di depan; percayai proxy agar skema https benar.
        $middleware->trustProxies(at: '*');
        $middleware->prepend(RequestContext::class);
        $middleware->alias(['active' => ActiveUser::class]);
        $middleware->trimStrings(except: ['password', 'password_confirmation']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->render(fn (Throwable $e, $request) => ApiErrors::render($e, $request));
        // Exception messages/SQL bindings can contain credentials: log only safe diagnostics.
        $exceptions->report(function (Throwable $e) {
            if ($e instanceof BusinessRule) {
                return false;
            }
            Log::error('Gangguan aplikasi', [
                'exceptionType' => get_class($e), 'file' => basename($e->getFile()), 'line' => $e->getLine(),
                'traceId' => request()->attributes->get('traceId'),
            ]);

            return false;
        });
    })->create();
