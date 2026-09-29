<?php

namespace Tests\Feature;

use App\Audit\Application\AuditWriter;
use App\Identity\Domain\Account;
use App\Identity\Domain\AccountRepository;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\ActorResolver;
use App\Identity\Infrastructure\UserRecord;
use App\Shared\Domain\BusinessRule;
use App\Shared\Infrastructure\OwnedRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IdentityApiTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Synthetic-Pass-123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(Role $role = Role::SALES, string $username = 'sales.one'): UserRecord
    {
        $repo = app(AccountRepository::class);
        $a = new Account((string) Str::uuid(), $username, $username, $username.'@example.test', Hash::make(self::PASSWORD), $role);
        $repo->save($a);

        return UserRecord::findOrFail($a->id);
    }

    private function bearer(UserRecord $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test', ['*'], now()->addHour())->plainTextToken];
    }

    private function payload(): array
    {
        return ['name' => 'Sales Dua', 'username' => 'sales.two', 'email' => 'sales.two@example.test', 'password' => self::PASSWORD, 'role' => 'SALES'];
    }

    public function test_login_by_username_and_email_returns_expiring_bearer_and_no_hash(): void
    {
        $u = $this->user();
        foreach (['SALES.ONE', 'SALES.ONE@EXAMPLE.TEST'] as $login) {
            $r = $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => self::PASSWORD, 'deviceName' => 'Desktop test'])->assertOk()->assertJsonPath('data.user.access', 'OWN');
            $r->assertJsonStructure(['data' => ['accessToken', 'expiresAt', 'tokenType', 'user']])->assertJsonMissingPath('data.user.password');
            $this->withHeaders(['Authorization' => 'Bearer '.$r->json('data.accessToken')])->getJson('/api/v1/auth/me')->assertOk();
        }
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'auth.login_succeeded')->count());
    }

    public function test_invalid_and_inactive_login_are_generic_and_audited(): void
    {
        $u = $this->user();
        $u->is_active = false;
        $u->save();
        foreach (['sales.one', 'unknown'] as $login) {
            $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => self::PASSWORD, 'deviceName' => 'Test'])->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
        }
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'auth.login_failed')->count());
    }

    public function test_orphan_user_row_fails_login_generically(): void
    {
        DB::table('users')->insert(['id' => (string) Str::uuid(), 'name' => 'Yatim', 'normalized_username' => 'yatim',
            'normalized_email' => 'yatim@example.test', 'password' => Hash::make(self::PASSWORD), 'is_active' => true, 'version' => 1]);
        $this->postJson('/api/v1/auth/login', ['login' => 'yatim', 'password' => self::PASSWORD, 'deviceName' => 'Test'])
            ->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    public function test_logout_revokes_only_current_token_and_revoke_all_revokes_every_session(): void
    {
        $u = $this->user();
        $a = $this->bearer($u);
        $b = $this->bearer($u);
        $this->postJson('/api/v1/auth/logout', [], $a)->assertOk();
        $this->getJson('/api/v1/auth/me', $a)->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', $b)->assertOk();
        $this->postJson('/api/v1/auth/revoke-all', [], $b)->assertOk();
        $this->getJson('/api/v1/auth/me', $b)->assertUnauthorized();
    }

    public function test_expired_token_and_inactive_user_are_rejected(): void
    {
        $u = $this->user();
        $expired = $u->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$expired])->assertUnauthorized();
        $h = $this->bearer($u);
        $u->update(['is_active' => false]);
        $this->getJson('/api/v1/auth/me', $h)->assertUnauthorized();
        $this->assertSame(0, $u->tokens()->count());
    }

    public function test_sales_scope_and_admin_billing_all_access(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $b = $this->user(Role::BILLING, 'billing.one');
        $s = $this->user();
        $s2 = $this->user(Role::SALES, 'sales.two');
        foreach ([$a, $b] as $actor) {
            $h = $this->bearer($actor);
            $this->getJson('/api/v1/users', $h)->assertOk()->assertJsonPath('totalItems', 4);
            $this->getJson('/api/v1/users/'.$s2->id, $h)->assertOk();
            $this->getJson('/api/v1/roles', $h)->assertOk()->assertJsonCount(3, 'data');
            $this->getJson('/api/v1/audit-logs', $h)->assertOk();
        }
        $h = $this->bearer($s);
        $this->getJson('/api/v1/users/'.$s->id, $h)->assertOk();
        $this->getJson('/api/v1/users/'.$s2->id, $h)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        foreach (['users', 'roles', 'audit-logs'] as $path) {
            $this->getJson('/api/v1/'.$path, $h)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        }
        $this->postJson('/api/v1/users', $this->payload(), $h)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_users_index_searches_by_name_username_and_email(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $this->user(Role::SALES, 'sales.two');
        $h = $this->bearer($a);
        $this->getJson('/api/v1/users?q=sales.two', $h)->assertOk()
            ->assertJsonPath('totalItems', 1)
            ->assertJsonPath('data.0.username', 'sales.two');
        $this->getJson('/api/v1/users?q=SALES.TWO@EXAMPLE', $h)->assertOk()->assertJsonPath('totalItems', 1);
        $this->getJson('/api/v1/users?q=admin', $h)->assertOk()->assertJsonPath('totalItems', 1);
        $this->getJson('/api/v1/users?q=tidak-ada-xyz', $h)->assertOk()
            ->assertJsonPath('totalItems', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_billing_creates_updates_deactivates_and_revokes_user(): void
    {
        $b = $this->user(Role::BILLING, 'billing.one');
        $h = $this->bearer($b);
        $r = $this->postJson('/api/v1/users', $this->payload(), $h)->assertCreated()->assertJsonPath('data.role', 'SALES');
        $id = $r->json('data.id');
        $u = UserRecord::findOrFail($id);
        $session = $this->bearer($u);
        $this->putJson('/api/v1/users/'.$id, ['version' => 1, 'role' => 'BILLING'], $h)->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson('/api/v1/auth/me', $session)->assertUnauthorized();
        $session = $this->bearer($u);
        $this->postJson('/api/v1/users/'.$id.'/revoke-all', [], $h)->assertOk();
        $this->getJson('/api/v1/auth/me', $session)->assertUnauthorized();
        $session = $this->bearer($u);
        $this->putJson('/api/v1/users/'.$id, ['version' => 2, 'isActive' => false], $h)->assertOk();
        $this->getJson('/api/v1/auth/me', $session)->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.updated', 'entity_id' => $id]);
    }

    public function test_last_admin_protection_and_optimistic_locking(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $h = $this->bearer($a);
        $this->putJson('/api/v1/users/'.$a->id, ['version' => 1, 'isActive' => false], $h)->assertConflict()->assertJsonPath('code', 'LAST_ADMIN_REQUIRED');
        $this->putJson('/api/v1/users/'.$a->id, ['version' => 1, 'role' => 'BILLING'], $h)->assertConflict();
        $this->putJson('/api/v1/users/'.$a->id, ['version' => 1, 'name' => 'Admin Baru'], $h)->assertOk();
        $this->putJson('/api/v1/users/'.$a->id, ['version' => 1, 'name' => 'Tertimpa'], $h)->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->assertDatabaseHas('users', ['id' => $a->id, 'name' => 'Admin Baru']);
    }

    public function test_idempotent_create_replays_same_resource(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $h = [...$this->bearer($a), 'Idempotency-Key' => 'buat-sales-001'];
        $first = $this->postJson('/api/v1/users', $this->payload(), $h)->assertCreated()->assertHeader('Idempotent-Replayed', 'false');
        $second = $this->postJson('/api/v1/users', $this->payload(), $h)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, DB::table('users')->where('normalized_username', 'sales.two')->count());
    }

    public function test_idempotency_key_with_different_payload_is_conflict(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $h = [...$this->bearer($a), 'Idempotency-Key' => 'buat-sales-002'];
        $this->postJson('/api/v1/users', $this->payload(), $h)->assertCreated();
        $this->postJson('/api/v1/users', [...$this->payload(), 'name' => 'Sales Lain'], $h)
            ->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $this->postJson('/api/v1/users', $this->payload(), [...$this->bearer($a), 'Idempotency-Key' => 'salah!'])
            ->assertUnprocessable()->assertJsonPath('code', 'INVALID_IDEMPOTENCY_KEY');
    }

    public function test_input_errors_are_indonesian_and_reserved_fields_cannot_be_assigned(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $h = $this->bearer($a);
        $this->postJson('/api/v1/users', [], $h)->assertUnprocessable()->assertJsonPath('fieldErrors.name.0', 'Nama wajib diisi.');
        $this->postJson('/api/v1/users', [...$this->payload(), 'companyId' => 'x', 'permissions' => ['targets.manage'], 'role' => 'OTHER'], $h)->assertUnprocessable();
        $this->getJson('/api/v1/users?pageSize=101', $h)->assertUnprocessable();
        $this->get('/api/v1/missing')->assertNotFound()->assertJsonStructure(['code', 'message', 'traceId', 'fieldErrors']);
        $this->getJson('/api/v1/users/not-a-uuid', $h)->assertNotFound();
    }

    public function test_duplicate_normalized_identity_is_conflict_without_database_details(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $h = $this->bearer($a);
        $this->user();
        $r = $this->postJson('/api/v1/users', [...$this->payload(), 'email' => 'SALES.ONE@EXAMPLE.TEST'], $h)->assertConflict()->assertJsonPath('code', 'DUPLICATE_DATA');
        $this->assertStringNotContainsString('SQLSTATE', $r->getContent());
    }

    public function test_login_rate_limit_returns_retry_after_and_indonesian_error(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['login' => 'absent', 'password' => 'wrong', 'deviceName' => 'Test'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['login' => 'absent', 'password' => 'wrong', 'deviceName' => 'Test'])->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 'RATE_LIMITED');
    }

    public function test_audit_redacts_secret_payloads_and_is_append_only(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        app(AuditWriter::class)->record('test.audit', $a->id, $a->id, ['password' => 'TOP_SECRET', 'token' => 'TOP_SECRET'], ['role' => 'ADMIN', 'email' => 'TOP_SECRET', 'nested' => ['password' => 'TOP_SECRET']]);
        $row = DB::table('audit_logs')->where('action', 'test.audit')->first();
        $this->assertStringNotContainsString('TOP_SECRET', json_encode($row));
        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $row->id)->update(['action' => 'tampered']);
    }

    public function test_failed_audit_rolls_back_user_creation(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $h = $this->bearer($a);
        $this->mock(AuditWriter::class, fn ($mock) => $mock->shouldReceive('record')->andThrow(new \RuntimeException('SECRET_UNSAFE_MESSAGE')));
        $r = $this->postJson('/api/v1/users', $this->payload(), $h)->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
        $this->assertStringNotContainsString('SECRET_UNSAFE_MESSAGE', $r->getContent());
        $this->assertDatabaseMissing('users', ['normalized_username' => 'sales.two']);
    }

    public function test_queue_actor_rechecks_current_permissions_and_active_status(): void
    {
        $b = $this->user(Role::BILLING, 'billing.one');
        $this->assertSame($b->id, app(ActorResolver::class)->resolve($b->id, 'users.manage')->id);
        $b->update(['is_active' => false]);
        $this->expectException(BusinessRule::class);
        app(ActorResolver::class)->resolve($b->id, 'users.manage');
    }

    public function test_target_gate_denies_billing_and_sales(): void
    {
        foreach ([Role::ADMIN, Role::BILLING, Role::SALES] as $role) {
            $u = $this->user($role, strtolower($role->value).'.one');
            $this->assertSame($role === Role::ADMIN, Gate::forUser($u)->allows('targets.manage'));
        }
    }

    public function test_trace_id_matches_response_header_and_no_store(): void
    {
        $r = $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($r->headers->get('X-Trace-Id'), $r->json('traceId'));
        $this->assertTrue(Str::isUuid($r->json('traceId')));
    }

    public function test_owned_query_scopes_lists_and_nested_lookup(): void
    {
        Schema::create('owned_scope_fixtures', function ($t) {
            $t->uuid('id')->primary();
            $t->uuid('owner_user_id');
        });
        $one = $this->user();
        $two = $this->user(Role::SALES, 'sales.two');
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $billing = $this->user(Role::BILLING, 'billing.one');
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();
        DB::table('owned_scope_fixtures')->insert([['id' => $a, 'owner_user_id' => $one->id], ['id' => $b, 'owner_user_id' => $two->id]]);
        $scope = app(OwnedRecords::class);
        $this->assertSame(1, $scope->scope(OwnedFixture::query(), $one)->count());
        $this->assertNull($scope->scope(OwnedFixture::query(), $one)->find($b));
        $this->assertSame(2, $scope->scope(OwnedFixture::query(), $admin)->count());
        $this->assertSame(2, $scope->scope(OwnedFixture::query(), $billing)->count());
    }

    public function test_malformed_json_and_wrong_media_type_are_localized(): void
    {
        $this->call('POST', '/api/v1/auth/login', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{bad')->assertStatus(400)->assertJsonPath('code', 'INVALID_JSON');
        $this->call('POST', '/api/v1/auth/login', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'secret')->assertStatus(415)->assertJsonPath('code', 'UNSUPPORTED_MEDIA_TYPE');
    }

    public function test_password_reset_revokes_sessions_and_keeps_audit_safe(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $u = $this->user();
        $h = $this->bearer($a);
        $old = $this->bearer($u);
        $password = 'New-Synthetic-Password';
        $this->putJson('/api/v1/users/'.$u->id, ['version' => 1, 'password' => $password], $h)->assertOk();
        $this->getJson('/api/v1/auth/me', $old)->assertUnauthorized();
        $this->assertTrue(Hash::check($password, $u->fresh()->password));
        $this->assertStringNotContainsString($password, DB::table('audit_logs')->get()->toJson());
    }

    public function test_schema_is_single_company_and_roles_are_fixed(): void
    {
        $this->assertFalse(Schema::hasTable('companies'));
        $this->assertFalse(Schema::hasColumn('users', 'company_id'));
        $this->assertSame(['ADMIN', 'BILLING', 'SALES'], DB::table('roles')->orderBy('code')->pluck('code')->all());
        $this->expectException(QueryException::class);
        DB::table('roles')->insert(['code' => 'OTHER']);
    }
}

final class OwnedFixture extends Model
{
    protected $table = 'owned_scope_fixtures';

    public $incrementing = false;

    protected $keyType = 'string';
}
