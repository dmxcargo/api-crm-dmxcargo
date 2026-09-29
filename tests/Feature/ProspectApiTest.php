<?php

namespace Tests\Feature;

use App\Identity\Domain\Account;
use App\Identity\Domain\AccountRepository;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\UserRecord;
use App\Sales\Infrastructure\ProspectRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProspectApiTest extends TestCase
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

    private function payload(array $over = []): array
    {
        return ['accountName' => 'PT Contoh Logistik', 'phone' => '081234567890', 'sourceCode' => 'GOOGLE_ADS',
            'priority' => 'WARM', 'customerType' => 'B2B', ...$over];
    }

    public function test_create_sets_owner_phone_and_outbox(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $r = $this->postJson('/api/v1/prospects', $this->payload(), $this->bearer($a))
            ->assertCreated()->assertJsonPath('data.ownerUserId', $a->id)
            ->assertJsonPath('data.phoneNormalized', '+6281234567890')
            ->assertJsonPath('data.stage', 'NEW')->assertJsonPath('data.version', 1);
        $id = $r->json('data.id');
        $this->assertDatabaseHas('audit_logs', ['action' => 'prospect.created', 'entity_id' => $id]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'sales.prospect.created', 'entity_id' => $id]);
    }

    public function test_entry_requires_account_phone_source_priority_customer_type(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $this->postJson('/api/v1/prospects', [], $this->bearer($a))->assertUnprocessable()
            ->assertJsonPath('fieldErrors.accountName.0', 'Nama account wajib diisi.');
        $this->postJson('/api/v1/prospects', [...$this->payload(), 'ownerUserId' => $a->id, 'phone' => 'abc'],
            $this->bearer($a))->assertUnprocessable();
    }

    public function test_sales_scope_is_enforced_on_prospects(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $one = $this->user(Role::SALES, 'sales.one');
        $two = $this->user(Role::SALES, 'sales.two');
        $id = $this->postJson('/api/v1/prospects', $this->payload(), $this->bearer($one))->assertCreated()->json('data.id');
        $this->getJson('/api/v1/prospects/'.$id, $this->bearer($two))->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->getJson('/api/v1/prospects', $this->bearer($two))->assertOk()->assertJsonPath('totalItems', 0);
        $this->getJson('/api/v1/prospects/'.$id, $this->bearer($admin))->assertOk();
        $this->putJson('/api/v1/prospects/'.$id, ['version' => 1, 'city' => 'Jakarta'], $this->bearer($two))->assertNotFound();
    }

    public function test_customer_type_is_immutable_and_version_is_checked(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $h = $this->bearer($a);
        $id = $this->postJson('/api/v1/prospects', $this->payload(), $h)->assertCreated()->json('data.id');
        $this->putJson('/api/v1/prospects/'.$id, ['version' => 1, 'customerType' => 'B2C'], $h)
            ->assertUnprocessable()->assertJsonPath('code', 'CUSTOMER_TYPE_IMMUTABLE');
        $this->putJson('/api/v1/prospects/'.$id, ['version' => 9, 'city' => 'Bandung'], $h)
            ->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
        $this->putJson('/api/v1/prospects/'.$id, ['version' => 1, 'city' => 'Bandung'], $h)
            ->assertOk()->assertJsonPath('data.city', 'Bandung')->assertJsonPath('data.version', 2);
    }

    public function test_assign_owner_needs_permission_and_active_owner(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $sales = $this->user(Role::SALES, 'sales.one');
        $other = $this->user(Role::SALES, 'sales.two');
        $id = $this->postJson('/api/v1/prospects', $this->payload(), $this->bearer($sales))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/prospects/'.$id.'/assign', ['ownerUserId' => $other->id], $this->bearer($sales))->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->postJson('/api/v1/prospects/'.$id.'/assign', ['ownerUserId' => $other->id], $this->bearer($admin))
            ->assertOk()->assertJsonPath('data.ownerUserId', $other->id);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'sales.owner.changed', 'entity_id' => $id]);
        $this->postJson('/api/v1/prospects/'.$id.'/assign', ['ownerUserId' => (string) Str::uuid()], $this->bearer($admin))
            ->assertUnprocessable()->assertJsonPath('code', 'OWNER_NOT_FOUND');
    }

    public function test_archive_lifecycle_and_soft_delete(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $h = $this->bearer($a);
        $id = $this->postJson('/api/v1/prospects', $this->payload(), $h)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/prospects/'.$id.'/archive', ['archived' => true], $h)->assertOk()->assertJsonPath('data.archived', true);
        $this->getJson('/api/v1/prospects', $h)->assertJsonPath('totalItems', 0);
        $this->getJson('/api/v1/prospects?includeArchived=true', $h)->assertJsonPath('totalItems', 1);
        $this->postJson('/api/v1/prospects/'.$id.'/archive', ['archived' => false], $h)->assertOk()->assertJsonPath('data.archived', false);
        $this->deleteJson('/api/v1/prospects/'.$id, [], $h)->assertOk();
        $this->getJson('/api/v1/prospects/'.$id, $h)->assertNotFound();
        $this->assertDatabaseHas('prospects', ['id' => $id]);
    }

    public function test_duplicates_are_detected_by_phone_and_email(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $h = $this->bearer($a);
        $this->postJson('/api/v1/prospects', $this->payload(['email' => 'sama@example.test']), $h)->assertCreated();
        $this->getJson('/api/v1/prospects/duplicates?phone=081234567890', $h)->assertOk()
            ->assertJsonPath('data.0.reason', 'PHONE');
        $this->getJson('/api/v1/prospects/duplicates?email=SAMA@EXAMPLE.TEST', $h)->assertOk()
            ->assertJsonPath('data.0.reason', 'EMAIL');
        $this->getJson('/api/v1/prospects/duplicates?phone=089999999999', $h)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_deleted_prospects_are_excluded_from_duplicates(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $h = $this->bearer($a);
        $id = $this->postJson('/api/v1/prospects', $this->payload(), $h)->assertCreated()->json('data.id');
        $this->getJson('/api/v1/prospects/duplicates?phone=081234567890', $h)->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson('/api/v1/prospects/'.$id, [], $h)->assertOk();
        $this->getJson('/api/v1/prospects/duplicates?phone=081234567890', $h)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_idempotent_create_replays_prospect(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $h = [...$this->bearer($a), 'Idempotency-Key' => 'prospek-001'];
        $first = $this->postJson('/api/v1/prospects', $this->payload(), $h)->assertCreated();
        $second = $this->postJson('/api/v1/prospects', $this->payload(), $h)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, ProspectRecord::count());
    }

    public function test_masters_are_seeded_and_readable_by_sales(): void
    {
        $s = $this->user();
        $h = $this->bearer($s);
        $this->getJson('/api/v1/prospect-sources', $h)->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/v1/industries', $h)->assertOk()->assertJsonCount(13, 'data');
        $this->getJson('/api/v1/lost-reasons', $h)->assertOk()->assertJsonCount(9, 'data');
    }

    public function test_masters_managed_by_admin_and_deactivation_flows_to_validation(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $h = $this->bearer($admin);
        $sales = $this->bearer($this->user(Role::SALES, 'sales.two'));
        $this->postJson('/api/v1/industries', ['code' => 'MAINAN', 'label' => 'x'], $sales)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->postJson('/api/v1/industries', ['code' => 'MAINAN', 'label' => 'Mainan & Hobi'], $h)
            ->assertCreated()->assertJsonPath('data.code', 'MAINAN');
        $this->postJson('/api/v1/industries', ['code' => 'MAINAN', 'label' => 'Duplikat'], $h)
            ->assertConflict()->assertJsonPath('code', 'DUPLICATE_DATA');
        $this->postJson('/api/v1/prospects', $this->payload(['industryCode' => 'MAINAN']), $h)->assertCreated();
        $this->putJson('/api/v1/industries/MAINAN', ['isActive' => false], $h)->assertOk()->assertJsonPath('data.active', false);
        $this->postJson('/api/v1/prospects', $this->payload(['accountName' => 'PT Baru', 'phone' => '085555555555', 'industryCode' => 'MAINAN']), $h)
            ->assertUnprocessable()->assertJsonPath('code', 'UNKNOWN_MASTER_VALUE');
        $this->getJson('/api/v1/industries', $h)->assertOk()->assertJsonCount(13, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'master.updated']);
    }

    public function test_contacts_are_scoped_to_prospect(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $h = $this->bearer($a);
        $id = $this->postJson('/api/v1/prospects', $this->payload(), $h)->assertCreated()->json('data.id');
        $c = $this->postJson('/api/v1/prospects/'.$id.'/contacts', ['name' => 'Budi', 'phone' => '081111111111'], $h)
            ->assertCreated()->assertJsonPath('data.phoneNormalized', '+6281111111111');
        $this->getJson('/api/v1/prospects/'.$id, $h)->assertOk()->assertJsonCount(1, 'data.contacts');
        $this->deleteJson('/api/v1/prospects/'.$id.'/contacts/'.$c->json('data.id'), [], $h)->assertOk();
        $this->getJson('/api/v1/prospects/'.$id, $h)->assertOk()->assertJsonCount(0, 'data.contacts');
    }

    public function test_stage_move_is_versioned_and_audited(): void
    {
        $s = $this->user();
        $h = $this->bearer($s);
        $id = $this->postJson('/api/v1/prospects', $this->payload(), $h)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/prospects/'.$id.'/stage', ['version' => 1, 'stage' => 'FOLLOW_UP'], $h)
            ->assertOk()->assertJsonPath('data.stage', 'FOLLOW_UP')->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'prospect.stage_changed', 'entity_id' => $id]);
    }

    public function test_search_filter_sort_pagination(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $h = $this->bearer($a);
        $this->postJson('/api/v1/prospects', $this->payload(['accountName' => 'PT Alpha', 'priority' => 'HOT']), $h)->assertCreated();
        $this->postJson('/api/v1/prospects', $this->payload(['accountName' => 'PT Beta', 'phone' => '082222222222']), $h)->assertCreated();
        $this->getJson('/api/v1/prospects?q=alpha', $h)->assertOk()->assertJsonPath('totalItems', 1);
        $this->getJson('/api/v1/prospects?priority=HOT', $h)->assertOk()->assertJsonPath('totalItems', 1);
        $this->getJson('/api/v1/prospects?pageSize=1&sort=accountName:asc', $h)->assertOk()
            ->assertJsonPath('totalItems', 2)->assertJsonPath('data.0.accountName', 'PT Alpha');
        $this->getJson('/api/v1/prospects?pageSize=101', $h)->assertUnprocessable();
    }
}
