<?php

namespace App\Providers;

use App\Audit\Application\AuditWriter;
use App\Audit\Infrastructure\PostgresAuditWriter;
use App\Identity\Application\PasswordHasher;
use App\Identity\Application\Tokens;
use App\Identity\Domain\AccountRepository;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\EloquentAccounts;
use App\Identity\Infrastructure\LaravelPasswords;
use App\Identity\Infrastructure\SanctumTokens;
use App\Identity\Infrastructure\UserRecord;
use App\Identity\Presentation\UserPolicy;
use App\Sales\Domain\ActivityRepository;
use App\Sales\Domain\ArchiveRepository;
use App\Sales\Domain\CustomerRepository;
use App\Sales\Domain\DealRepository;
use App\Sales\Domain\EventOutbox;
use App\Sales\Domain\ExportRepository;
use App\Sales\Domain\FollowUpRepository;
use App\Sales\Domain\ImportRepository;
use App\Sales\Domain\ProspectRepository;
use App\Sales\Domain\TargetRepository;
use App\Sales\Infrastructure\EloquentActivities;
use App\Sales\Infrastructure\EloquentArchives;
use App\Sales\Infrastructure\EloquentCustomers;
use App\Sales\Infrastructure\EloquentDeals;
use App\Sales\Infrastructure\EloquentExports;
use App\Sales\Infrastructure\EloquentFollowUps;
use App\Sales\Infrastructure\EloquentImports;
use App\Sales\Infrastructure\EloquentProspects;
use App\Sales\Infrastructure\EloquentTargets;
use App\Sales\Infrastructure\PostgresEventOutbox;
use App\Sales\Infrastructure\ProspectRecord;
use App\Sales\Presentation\ProspectPolicy;
use App\Shared\Domain\IdempotencyStore;
use App\Shared\Domain\UnitOfWork;
use App\Shared\Infrastructure\PostgresIdempotencyStore;
use App\Shared\Infrastructure\PostgresUnitOfWork;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->when(App\Sales\Application\ImportNormalizer::class)->needs('$minYear')
            ->giveConfig('dmx.import_min_year', 2000);
        foreach ([AccountRepository::class => EloquentAccounts::class, PasswordHasher::class => LaravelPasswords::class,
            Tokens::class => SanctumTokens::class, UnitOfWork::class => PostgresUnitOfWork::class, AuditWriter::class => PostgresAuditWriter::class,
            IdempotencyStore::class => PostgresIdempotencyStore::class,
            ProspectRepository::class => EloquentProspects::class, EventOutbox::class => PostgresEventOutbox::class,
            ActivityRepository::class => EloquentActivities::class, FollowUpRepository::class => EloquentFollowUps::class,
            DealRepository::class => EloquentDeals::class, CustomerRepository::class => EloquentCustomers::class,
            ImportRepository::class => EloquentImports::class,
            TargetRepository::class => EloquentTargets::class, ExportRepository::class => EloquentExports::class,
            ArchiveRepository::class => EloquentArchives::class] as $port => $adapter) {
            $this->app->bind($port, $adapter);
        }
    }

    public function boot(): void
    {
        Gate::policy(UserRecord::class, UserPolicy::class);
        Gate::policy(ProspectRecord::class, ProspectPolicy::class);
        foreach (Role::ADMIN->permissions() as $permission) {
            Gate::define($permission, fn (UserRecord $user) => $user->permits($permission));
        }
        RateLimiter::for('login', fn ($r) => [
            Limit::perMinute(30)->by('ip:'.$r->ip()),
            Limit::perMinute(5)->by('login:'.hash_hmac('sha256', strtolower(trim(is_string($r->input('login')) ? $r->input('login') : '')), config('app.key')).':'.$r->ip()),
        ]);
    }
}
