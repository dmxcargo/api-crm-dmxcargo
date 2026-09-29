<?php

namespace App\Shared\Presentation;

use App\Shared\Domain\BusinessRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiErrors
{
    public static function render(Throwable $e, $request)
    {
        $status = 500;
        $code = 'INTERNAL_ERROR';
        $message = 'Terjadi gangguan pada server. Coba lagi atau hubungi Admin dengan kode pelacakan.';
        $fields = [];
        $headers = [];
        if ($e instanceof BusinessRule) {
            $status = $e->status;
            $code = $e->errorCode;
            $message = $e->getMessage();
        } elseif ($e instanceof ValidationException) {
            $status = 422;
            $code = 'VALIDATION_ERROR';
            $message = 'Data belum valid. Periksa kolom yang ditandai.';
            $fields = $e->errors();
        } elseif ($e instanceof AuthenticationException) {
            $status = 401;
            $code = 'SESSION_EXPIRED';
            $message = 'Sesi tidak berlaku atau telah berakhir. Silakan masuk kembali.';
        } elseif ($e instanceof AuthorizationException) {
            $status = $e->status() ?? 403;
            $code = $status === 404 ? 'NOT_FOUND' : 'FORBIDDEN';
            $message = $status === 404 ? 'Data tidak ditemukan.' : 'Anda tidak memiliki izin untuk tindakan ini.';
        } elseif ($e instanceof ModelNotFoundException) {
            $status = 404;
            $code = 'NOT_FOUND';
            $message = 'Data tidak ditemukan.';
        } elseif ($e instanceof QueryException && ($e->errorInfo[0] ?? '') === '23505') {
            $status = 409;
            $code = 'DUPLICATE_DATA';
            $message = 'Username atau email sudah digunakan. Gunakan nilai lain.';
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();
            [$code,$message] = match ($status) {
                400 => ['BAD_REQUEST', 'Permintaan tidak dapat diproses. Periksa format data.'],
                403 => ['FORBIDDEN', 'Anda tidak memiliki izin untuk tindakan ini.'],
                404 => ['NOT_FOUND', 'Data tidak ditemukan.'],
                405 => ['METHOD_NOT_ALLOWED', 'Metode permintaan tidak tersedia.'],
                413 => ['PAYLOAD_TOO_LARGE', 'Ukuran data melebihi batas yang diizinkan.'],
                429 => ['RATE_LIMITED', 'Terlalu banyak percobaan. Tunggu sebentar lalu coba kembali.'],
                503 => ['SERVICE_UNAVAILABLE', 'Layanan sedang tidak tersedia. Coba kembali beberapa saat lagi.'],
                default => ['HTTP_ERROR', 'Permintaan tidak dapat diproses.'],
            };
        }
        $trace = $request->attributes->get('traceId') ?? (string) Str::uuid();

        return response()->json(['code' => $code, 'message' => $message, 'traceId' => $trace, 'fieldErrors' => (object) $fields], $status, $headers)->header('X-Trace-Id', $trace)->header('Cache-Control', 'no-store');
    }
}
