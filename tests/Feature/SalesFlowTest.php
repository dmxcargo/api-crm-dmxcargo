<?php

namespace Tests\Feature;

use App\Identity\Domain\Account;
use App\Identity\Domain\AccountRepository;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\UserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SalesFlowTest extends TestCase
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

    private function prospect(array $h, array $over = []): string
    {
        return $this->postJson('/api/v1/prospects', ['accountName' => 'PT Contoh Logistik', 'phone' => '081234567890',
            'sourceCode' => 'GOOGLE_ADS', 'priority' => 'WARM', 'customerType' => 'B2B', ...$over], $h)->assertCreated()->json('data.id');
    }

    public function test_call_log_updates_prospect_and_schedules_follow_up(): void
    {
        $s = $this->user();
        $h = $this->bearer($s);
        $id = $this->prospect($h);
        $this->postJson('/api/v1/prospects/'.$id.'/activities', ['type' => 'CALL', 'answered' => true,
            'durationMinutes' => 5, 'notes' => 'Tertarik', 'lastProgress' => 'Minta katalog',
            'nextFollowUpAt' => now()->addDay()->toISOString(), 'nextAction' => 'Kirim katalog'], $h)
            ->assertCreated()->assertJsonPath('data.ownerUserId', $s->id);
        $this->getJson('/api/v1/prospects/'.$id.'/activities', $h)->assertOk()->assertJsonPath('totalItems', 1);
        $this->getJson('/api/v1/prospects/'.$id, $h)->assertOk()->assertJsonPath('data.lastProgress', 'Minta katalog');
        $this->getJson('/api/v1/follow-ups?mode=all', $h)->assertOk()->assertJsonPath('totalItems', 1);
    }

    public function test_invalid_activity_rolls_back_everything(): void
    {
        $s = $this->user();
        $h = $this->bearer($s);
        $id = $this->prospect($h);
        $this->postJson('/api/v1/prospects/'.$id.'/activities', ['type' => 'CALL', 'durationMinutes' => -3,
            'nextFollowUpAt' => now()->addDay()->toISOString()], $h)->assertUnprocessable();
        $this->assertDatabaseMissing('activities', ['prospect_id' => $id]);
        $this->assertDatabaseMissing('follow_ups', ['prospect_id' => $id]);
    }

    public function test_visit_log_uses_canonical_enums(): void
    {
        $s = $this->user();
        $h = $this->bearer($s);
        $id = $this->prospect($h);
        $this->postJson('/api/v1/prospects/'.$id.'/activities', ['type' => 'VISIT',
            'attendance' => 'HADIR', 'completion' => 'SELESAI', 'notes' => 'Survey gudang'], $h)
            ->assertCreated()->assertJsonPath('data.attendance', 'HADIR');
        $this->postJson('/api/v1/prospects/'.$id.'/activities', ['type' => 'VISIT', 'attendance' => 'DATANG'], $h)
            ->assertUnprocessable();
    }

    public function test_follow_up_complete_and_reschedule(): void
    {
        $s = $this->user();
        $h = $this->bearer($s);
        $id = $this->prospect($h);
        $f = $this->postJson('/api/v1/prospects/'.$id.'/follow-ups', ['scheduledAt' => now()->subDay()->toISOString(),
            'description' => 'Telepon ulang', 'priority' => 'PENTING'], $h)->assertCreated()->json('data.id');
        $this->getJson('/api/v1/follow-ups?mode=overdue', $h)->assertOk()->assertJsonPath('totalItems', 1);
        $this->postJson('/api/v1/follow-ups/'.$f.'/reschedule', ['scheduledAt' => now()->addDay()->toISOString(), 'version' => 1], $h)
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson('/api/v1/follow-ups/'.$f.'/complete', [], $h)->assertOk()->assertJsonPath('data.status', 'SELESAI');
        $this->postJson('/api/v1/follow-ups/'.$f.'/complete', [], $h)->assertConflict()->assertJsonPath('code', 'ALREADY_COMPLETED');
    }

    public function test_pipeline_guards_transitions_and_records_history(): void
    {
        $s = $this->user();
        $h = $this->bearer($s);
        $id = $this->prospect($h);
        $this->postJson('/api/v1/prospects/'.$id.'/stage', ['version' => 1, 'stage' => 'WON'], $h)
            ->assertUnprocessable()->assertJsonPath('code', 'ILLEGAL_TRANSITION');
        $this->postJson('/api/v1/prospects/'.$id.'/stage', ['version' => 1, 'stage' => 'NEW'], $h)
            ->assertUnprocessable()->assertJsonPath('code', 'ILLEGAL_TRANSITION');
        $this->postJson('/api/v1/prospects/'.$id.'/stage', ['version' => 1, 'stage' => 'FOLLOW_UP'], $h)->assertOk();
        $this->getJson('/api/v1/prospects/'.$id.'/stage-history', $h)->assertOk()->assertJsonPath('data.0.toStage', 'FOLLOW_UP');
        $this->getJson('/api/v1/pipeline', $h)->assertOk()->assertJsonPath('data.0.stage', 'FOLLOW_UP');
    }

    public function test_reopen_needs_privileged_role(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $s = $this->user();
        $id = $this->prospect($this->bearer($s));
        $deal = $this->postJson('/api/v1/prospects/'.$id.'/deals', [], $this->bearer($s))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/deals/'.$deal.'/won', ['closingDate' => now()->toDateString(), 'closingValue' => 10000000,
            'customerMode' => 'new'], $this->bearer($s))->assertOk();
        $this->postJson('/api/v1/prospects/'.$id.'/stage', ['version' => 2, 'stage' => 'FOLLOW_UP'], $this->bearer($s))
            ->assertUnprocessable()->assertJsonPath('code', 'ILLEGAL_TRANSITION');
        $this->postJson('/api/v1/prospects/'.$id.'/stage', ['version' => 2, 'stage' => 'FOLLOW_UP'], $this->bearer($admin))->assertOk();
    }

    public function test_deal_won_needs_fields_and_explicit_customer_choice(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $h = $this->bearer($admin);
        $id = $this->prospect($h, ['phone' => '081234567890', 'email' => 'sama@example.test']);
        $other = $this->prospect($h, ['accountName' => 'PT Mirip', 'phone' => '081234567890']);
        $deal = $this->postJson('/api/v1/prospects/'.$id.'/deals', ['quotationValue' => 12000000], $h)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/deals/'.$deal.'/won', ['closingValue' => 10000000], $h)->assertUnprocessable();
        $cid = $this->postJson('/api/v1/customers', ['accountName' => 'PT Mirip', 'phone' => '081234567890'], $h)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/deals/'.$deal.'/won', ['closingDate' => now()->toDateString(), 'closingValue' => 10000000], $h)
            ->assertConflict()->assertJsonPath('code', 'CUSTOMER_CHOICE_REQUIRED');
        $this->postJson('/api/v1/deals/'.$deal.'/won', ['closingDate' => now()->toDateString(), 'closingValue' => 10000000, 'customerId' => $cid], $h)
            ->assertOk()->assertJsonPath('data.status', 'WON')->assertJsonPath('data.customerId', $cid);
        $this->getJson('/api/v1/prospects/'.$id, $h)->assertOk()->assertJsonPath('data.stage', 'WON');
        $retry = $this->postJson('/api/v1/deals/'.$deal.'/won', ['closingDate' => now()->toDateString(), 'closingValue' => 10000000, 'customerId' => $cid], $h)->assertOk();
        $this->assertSame(1, DB::table('customers')->where('phone_normalized', '+6281234567890')->count());
        $this->assertSame($deal, $retry->json('data.id'));
    }

    public function test_deal_lost_needs_reason_and_second_open_is_rejected(): void
    {
        $s = $this->user();
        $h = $this->bearer($s);
        $id = $this->prospect($h);
        $deal = $this->postJson('/api/v1/prospects/'.$id.'/deals', [], $h)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/prospects/'.$id.'/deals', [], $h)->assertConflict()->assertJsonPath('code', 'DEAL_EXISTS');
        $this->postJson('/api/v1/deals/'.$deal.'/lost', [], $h)->assertUnprocessable();
        $this->postJson('/api/v1/deals/'.$deal.'/lost', ['lostReasonCode' => 'HARGA'], $h)
            ->assertOk()->assertJsonPath('data.status', 'LOST');
        $this->getJson('/api/v1/prospects/'.$id, $h)->assertOk()->assertJsonPath('data.stage', 'LOST');
        $this->postJson('/api/v1/deals/'.$deal.'/lost', ['lostReasonCode' => 'HARGA'], $h)->assertOk();
    }

    public function test_repeat_order_reuses_customer(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $h = $this->bearer($admin);
        $id = $this->prospect($h);
        $deal = $this->postJson('/api/v1/prospects/'.$id.'/deals', [], $h)->assertCreated()->json('data.id');
        $won = $this->postJson('/api/v1/deals/'.$deal.'/won', ['closingDate' => now()->toDateString(),
            'closingValue' => 5000000, 'customerMode' => 'new'], $h)->assertOk();
        $customerId = $won->json('data.customerId');
        $this->assertNotNull($customerId);
        $second = $this->prospect($h, ['accountName' => 'PT Contoh RO', 'phone' => '083333333333']);
        $repeat = $this->postJson('/api/v1/customers/'.$customerId.'/deals', ['prospectId' => $second], $h)
            ->assertCreated()->assertJsonPath('data.customerId', $customerId);
        $this->assertNotSame($deal, $repeat->json('data.id'));
        $this->getJson('/api/v1/customers/'.$customerId.'/deals', $h)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_payment_status_is_billing_only_and_audited(): void
    {
        $billing = $this->user(Role::BILLING, 'billing.one');
        $s = $this->user();
        $id = $this->prospect($this->bearer($billing));
        $deal = $this->postJson('/api/v1/prospects/'.$id.'/deals', [], $this->bearer($billing))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/deals/'.$deal.'/payment-status', ['paymentStatus' => 'INVOICE'], $this->bearer($s))->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->postJson('/api/v1/deals/'.$deal.'/payment-status', ['paymentStatus' => 'INVOICE'], $this->bearer($billing))
            ->assertOk()->assertJsonPath('data.paymentStatus', 'INVOICE');
        $this->getJson('/api/v1/deals/'.$deal.'/payment-history', $this->bearer($billing))->assertOk()->assertJsonPath('data.0.toStatus', 'INVOICE');
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'sales.payment_status.changed', 'entity_id' => $deal]);
    }

    public function test_outbox_dispatch_marks_events_sent(): void
    {
        $s = $this->user();
        $h = $this->bearer($s);
        $this->prospect($h);
        $this->assertTrue(DB::table('outbox_events')->whereNull('dispatched_at')->exists());
        $this->artisan('dmx:outbox-dispatch')->assertOk();
        $this->assertFalse(DB::table('outbox_events')->whereNull('dispatched_at')->exists());
    }

    public function test_audit_keeps_business_payload_and_history_has_duration(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $h = $this->bearer($admin);
        $id = $this->prospect($h);
        $deal = $this->postJson('/api/v1/prospects/'.$id.'/deals', [], $h)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/deals/'.$deal.'/won', ['closingDate' => now()->toDateString(),
            'closingValue' => 7000000, 'customerMode' => 'new'], $h)->assertOk();
        $row = DB::table('audit_logs')->where('action', 'deal.won')->first();
        $this->assertSame(7000000.0, (float) json_decode($row->after_data, true)['closingValue']);
        $this->getJson('/api/v1/prospects/'.$id.'/stage-history', $h)->assertOk()
            ->assertJsonPath('data.0.toStage', 'WON')->assertJsonStructure(['data' => [['durationSeconds']]]);
        $this->getJson('/api/v1/prospects/'.$id.'/deals', $h)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_closed_prospect_rejects_new_work_and_bad_assignee_is_422(): void
    {
        $a = $this->user(Role::ADMIN, 'admin.one');
        $h = $this->bearer($a);
        $id = $this->prospect($h);
        $this->deleteJson('/api/v1/prospects/'.$id, [], $h)->assertOk();
        $this->postJson('/api/v1/prospects/'.$id.'/activities', ['type' => 'NOTE'], $h)->assertNotFound();
        $this->postJson('/api/v1/prospects/'.$id.'/follow-ups', ['scheduledAt' => now()->addDay()->toISOString(), 'description' => 'x'], $h)->assertNotFound();
        $this->postJson('/api/v1/prospects/'.$id.'/deals', [], $h)->assertNotFound();
        $live = $this->prospect($h, ['accountName' => 'PT Hidup', 'phone' => '084444444444']);
        $this->postJson('/api/v1/prospects/'.$live.'/follow-ups', ['scheduledAt' => now()->addDay()->toISOString(),
            'description' => 'x', 'assigneeUserId' => (string) Str::uuid()], $h)
            ->assertUnprocessable()->assertJsonPath('code', 'OWNER_NOT_FOUND');
    }

    public function test_sales_cannot_touch_other_sales_flow(): void
    {
        $one = $this->user(Role::SALES, 'sales.one');
        $two = $this->user(Role::SALES, 'sales.two');
        $id = $this->prospect($this->bearer($one));
        $this->postJson('/api/v1/prospects/'.$id.'/activities', ['type' => 'NOTE'], $this->bearer($two))->assertNotFound();
        $deal = $this->postJson('/api/v1/prospects/'.$id.'/deals', [], $this->bearer($one))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/deals/'.$deal.'/lost', ['lostReasonCode' => 'HARGA'], $this->bearer($two))->assertNotFound();
        $this->getJson('/api/v1/pipeline', $this->bearer($two))->assertOk()->assertJsonCount(0, 'data');
    }
}
