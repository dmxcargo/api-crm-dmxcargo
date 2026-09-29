<?php

use App\Audit\Application\AuditWriter;
use App\Identity\Domain\Account;
use App\Identity\Domain\AccountRepository;
use App\Identity\Domain\Role;
use App\Sales\Infrastructure\OutboxDispatcher;
use App\Shared\Domain\IdempotencyStore;
use App\Shared\Domain\UnitOfWork;
use App\Shared\Infrastructure\HeartbeatJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('dmx:ready', function () {
    try {
        DB::select('SELECT 1');

        return 0;
    } catch (Throwable $e) {
        $this->error('Database belum tersedia.');

        return 1;
    }
});
Artisan::command('dmx:bootstrap-admin', function () {
    $name = $this->ask('Nama Admin');
    $username = strtolower(trim($this->ask('Username')));
    $email = strtolower(trim($this->ask('Email')));
    $password = $this->secret('Kata sandi (minimal 12 karakter)');
    $confirm = $this->secret('Ulangi kata sandi');
    if (! is_string($password) || $password !== $confirm || strlen($password) < 12 || strlen($password) > 72 || ! preg_match('/^[a-z0-9_.-]{3,50}$/D', $username) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $name || strlen($name) > 150 || strlen($email) > 254) {
        $this->error('Data Admin tidak valid atau kata sandi tidak cocok.');

        return 1;
    }

    return app(UnitOfWork::class)->run(function () use ($name, $username, $email, $password) {
        $repo = app(AccountRepository::class);
        $repo->lockAdministration();
        if ($repo->activeAdmins() > 0) {
            $this->error('Admin aktif sudah tersedia. Gunakan API administrasi user.');

            return 1;
        }
        $user = new Account($repo->nextId(), $name, $username, $email, Hash::make($password), Role::ADMIN);
        $repo->save($user);
        app(AuditWriter::class)->record('user.bootstrapped', $user->id, $user->id, [], $user->auditData());
        $this->info('Admin awal berhasil dibuat. Kata sandi tidak ditampilkan atau dicatat.');

        return 0;
    });
});
Artisan::command('dmx:heartbeat', function () {
    Cache::put('scheduler-heartbeat', now()->toISOString(), 300);
    HeartbeatJob::dispatch();
    $this->info('Heartbeat dijadwalkan.');
});
Artisan::command('dmx:heartbeats', function () {
    $this->line(json_encode(['scheduler' => Cache::get('scheduler-heartbeat'), 'worker' => Cache::get('worker-heartbeat')]));
});
Artisan::command('dmx:idempotency-prune', function () {
    $this->info('Kunci kedaluwarsa dihapus: '.app(IdempotencyStore::class)->prune());
});
Artisan::command('dmx:outbox-dispatch', function () {
    $this->info('Event terkirim: '.app(OutboxDispatcher::class)->run());
});
Schedule::command('dmx:outbox-dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('dmx:idempotency-prune')->daily();
Schedule::command('dmx:heartbeat')->everyMinute()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
