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

final class ArchiveTest extends TestCase
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

    private function work(): void
    {
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0, '--timeout' => 600]);
    }

    private function wonProspect(array $h, string $account, string $phone): string
    {
        $id = $this->postJson('/api/v1/prospects', ['accountName' => $account, 'phone' => $phone,
            'sourceCode' => 'GOOGLE_ADS', 'priority' => 'WARM', 'customerType' => 'B2B'], $h)->assertCreated()->json('data.id');
        $deal = $this->postJson('/api/v1/prospects/'.$id.'/deals', [], $h)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/deals/'.$deal.'/won',
            ['closingDate' => '2026-09-01', 'closingValue' => 10000000, 'customerMode' => 'new'], $h)->assertOk();

        return $id;
    }

    public function test_preview_counts_only_closed_and_sales_forbidden(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $sales = $this->user();
        $ah = $this->bearer($admin);
        $this->postJson('/api/v1/prospects', ['accountName' => 'PT Aktif', 'phone' => '081200000001',
            'sourceCode' => 'GOOGLE_ADS', 'priority' => 'WARM', 'customerType' => 'B2B'], $this->bearer($sales))->assertCreated();
        $this->wonProspect($this->bearer($sales), 'PT Tutup', '081200000002');

        $this->postJson('/api/v1/archives/preview', [], $this->bearer($sales))->assertForbidden();
        $this->postJson('/api/v1/archives/preview', [], $ah)->assertOk()->assertJsonPath('data.eligible', 1);
    }

    public function test_build_verify_copy_restore_flow(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $sales = $this->user();
        $ah = $this->bearer($admin);
        $this->wonProspect($this->bearer($sales), 'PT Arsip A', '081211111111');
        $this->wonProspect($this->bearer($sales), 'PT Arsip B', '081222222222');

        $id = $this->postJson('/api/v1/archives', [], $ah)->assertAccepted()->json('data.id');
        $this->work();
        $job = $this->getJson('/api/v1/archives/'.$id, $ah)->assertOk()->json('data');
        $this->assertSame('READY', $job['status']);
        $this->assertSame(2, $job['manifest']['recordCount']);
        $this->assertSame(64, strlen($job['sha256']));

        $this->postJson('/api/v1/archives/'.$id.'/verify', [], $ah)->assertOk()
            ->assertJsonPath('data.valid', true)->assertJsonPath('data.countMatch', true);
        $this->postJson('/api/v1/archives/'.$id.'/copy', [], $ah)->assertOk()
            ->assertJsonPath('data.secondCopyVerified', true);

        $restore = $this->postJson('/api/v1/archives/'.$id.'/restore', [], $ah)->assertOk()->json('data');
        $this->assertSame(2, $restore['staged']);
        $this->assertSame(2, $restore['conflicts']);
        $this->getJson('/api/v1/archives/'.$id.'/staging?status=CONFLICT', $ah)->assertOk()->assertJsonPath('totalItems', 2);
    }

    public function test_purge_is_locked_behind_config_copy_and_retention(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $sales = $this->user();
        $ah = $this->bearer($admin);
        $pid = $this->wonProspect($this->bearer($sales), 'PT Purge', '081233333333');
        $dealId = DB::table('deals')->where('prospect_id', $pid)->value('id');

        $id = $this->postJson('/api/v1/archives', ['dateTo' => '2026-12-31'], $ah)->assertAccepted()->json('data.id');
        $this->work();
        $this->postJson('/api/v1/archives/'.$id.'/purge', ['approved' => true], $ah)
            ->assertForbidden()->assertJsonPath('code', 'ARCHIVE_LOCKED');

        config(['dmx.archive_purge_enabled' => true]);
        try {
            $id2 = $this->postJson('/api/v1/archives', [], $ah)->assertAccepted()->json('data.id');
            $this->work();
            $this->postJson('/api/v1/archives/'.$id2.'/copy', [], $ah)->assertOk();
            $this->postJson('/api/v1/archives/'.$id2.'/purge', ['approved' => true], $ah)
                ->assertUnprocessable()->assertJsonPath('code', 'RETENTION_REQUIRED');

            $this->postJson('/api/v1/archives/'.$id.'/copy', [], $ah)->assertOk();
            $this->postJson('/api/v1/archives/'.$id.'/purge', ['approved' => true], $ah)->assertOk()
                ->assertJsonPath('data.purged', true);
            $this->assertSoftDeleted('prospects', ['id' => $pid]);
            $this->assertDatabaseHas('deals', ['id' => $dealId]);
            $this->assertDatabaseHas('customers', []);
            $this->postJson('/api/v1/archives/'.$id.'/purge', ['approved' => true], $ah)->assertOk();
        } finally {
            config(['dmx.archive_purge_enabled' => false]);
        }
    }
}
