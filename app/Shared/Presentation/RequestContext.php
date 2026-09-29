<?php

namespace App\Shared\Presentation;

use App\Shared\Domain\BusinessRule;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class RequestContext
{
    public function handle(Request $request, Closure $next)
    {
        $trace = (string) Str::uuid();
        $request->attributes->set('traceId', $trace);
        if ($request->getContent() !== '' && in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            if (! $request->isJson()) {
                throw new BusinessRule('UNSUPPORTED_MEDIA_TYPE', 'Gunakan Content-Type application/json.', 415);
            }
            try {
                $decoded = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new BusinessRule('INVALID_JSON', 'Format JSON tidak valid. Periksa isi permintaan.', 400);
            }
            if (! is_object($decoded) && $decoded !== []) {
                throw new BusinessRule('INVALID_JSON', 'Isi permintaan harus berupa objek JSON.', 400);
            }
        }
        // Paksa JSON walau desktop tak kirim header Accept.
        $request->headers->set('Accept', 'application/json');
        $response = $next($request);
        $response->headers->set('X-Trace-Id', $trace);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
