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

final class ReportExportTest extends TestCase
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

    private function prospect(array $h, array $over = []): string
    {
        return $this->postJson('/api/v1/prospects', ['accountName' => 'PT Uji Metrik', 'phone' => '0812'.random_int(10000000, 99999999),
            'sourceCode' => 'GOOGLE_ADS', 'priority' => 'WARM', 'customerType' => 'B2B', 'entryDate' => '2026-10-05', ...$over], $h)
            ->assertCreated()->json('data.id');
    }

    private function won(array $h, string $prospectId, string $date, string $value): void
    {
        $deal = $this->postJson('/api/v1/prospects/'.$prospectId.'/deals', [], $h)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/deals/'.$deal.'/won',
            ['closingDate' => $date, 'closingValue' => $value, 'customerMode' => 'new'], $h)->assertOk();
    }

    private function fixture(array $h, array $ah): array
    {
        $s2 = $this->user(Role::SALES, 'sales.two');
        $h2 = $this->bearer($s2);
        $p1 = $this->prospect($h, ['accountName' => 'PT Menang A']);
        $this->won($h, $p1, '2026-10-10', '60000000');
        $p2 = $this->prospect($h, ['accountName' => 'PT Menang B']);
        $this->won($h, $p2, '2026-10-12', '40000000');
        $repeat = $this->postJson('/api/v1/customers/'.$this->customerOf($p1, $h).'/deals', ['prospectId' => $p1], $h)
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/deals/'.$repeat.'/won',
            ['closingDate' => '2026-10-15', 'closingValue' => '10000000', 'customerMode' => 'new'], $h)->assertOk();
        $p3 = $this->prospect($h, ['accountName' => 'PT Kalah']);
        $deal = $this->postJson('/api/v1/prospects/'.$p3.'/deals', [], $h)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/deals/'.$deal.'/lost', ['lostReasonCode' => 'HARGA'], $h)->assertOk();
        $p4 = $this->prospect($h, ['accountName' => 'PT Pindah']);
        $this->postJson('/api/v1/prospects/'.$p4.'/assign', ['ownerUserId' => $s2->id], $ah)->assertOk();
        $old = $this->prospect($h, ['accountName' => 'PT Tahun Lalu', 'entryDate' => '2025-11-05']);
        $this->won($h, $old, '2026-10-20', '50000000');

        return [$s2, $h2];
    }

    private function customerOf(string $prospectId, array $h): string
    {
        return DB::table('deals')->where('prospect_id', $prospectId)->where('status', 'WON')->value('customer_id');
    }

    public function test_actual_credits_owner_at_closing(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $sales = $this->user();
        $other = $this->user(Role::SALES, 'sales.two');
        $ah = $this->bearer($admin);
        $h = $this->bearer($sales);
        $id = $this->prospect($h, ['accountName' => 'PT Pindah Closing', 'entryDate' => '2026-10-05']);
        $this->won($h, $id, '2026-10-10', '50000000');
        $this->postJson('/api/v1/prospects/'.$id.'/assign', ['ownerUserId' => $other->id], $ah)->assertOk();

        $rows = $this->getJson('/api/v1/reports/performance?periodType=MONTH&period=2026-10', $ah)->assertOk()->json('data');
        $this->assertSame('50000000.00', collect($rows)->firstWhere('ownerUserId', $sales->id)['actualValue']);
        $this->assertSame('0', collect($rows)->firstWhere('ownerUserId', $other->id)['actualValue']);
    }

    public function test_performance_rejects_malformed_period(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $ah = $this->bearer($admin);
        $this->getJson('/api/v1/reports/performance?periodType=MONTH&period=2026-13', $ah)
            ->assertUnprocessable()->assertJsonPath('code', 'INVALID_PERIOD');
        $this->getJson('/api/v1/reports/performance?periodType=YEAR&period=26', $ah)
            ->assertUnprocessable()->assertJsonPath('code', 'INVALID_PERIOD');
    }

    public function test_targets_are_admin_only_and_unique(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $billing = $this->user(Role::BILLING, 'billing.one');
        $sales = $this->user();
        $ah = $this->bearer($admin);
        $this->postJson('/api/v1/targets', ['userId' => $sales->id, 'periodType' => 'MONTH',
            'period' => '2026-10', 'targetValue' => 100000000], $ah)->assertCreated()
            ->assertJsonPath('data.period', '2026-10');
        $this->postJson('/api/v1/targets', ['userId' => $sales->id, 'periodType' => 'MONTH',
            'period' => '2026-10', 'targetValue' => 1], $ah)->assertConflict()->assertJsonPath('code', 'TARGET_EXISTS');
        $this->postJson('/api/v1/targets', ['userId' => $sales->id, 'periodType' => 'MONTH',
            'period' => '2026-13', 'targetValue' => 1], $ah)->assertUnprocessable();
        $this->postJson('/api/v1/targets', ['userId' => $sales->id, 'periodType' => 'MONTH',
            'period' => '2026-10', 'targetValue' => 1], $this->bearer($billing))->assertForbidden();
        $this->postJson('/api/v1/targets', ['userId' => $sales->id, 'periodType' => 'MONTH',
            'period' => '2026-11', 'targetValue' => 1], $this->bearer($sales))->assertForbidden();
        $this->getJson('/api/v1/targets', $this->bearer($billing))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/targets?userId='.$sales->id, $ah)->assertOk()->assertJsonCount(1, 'data');
        $other = $this->user(Role::SALES, 'sales.two');
        $this->postJson('/api/v1/targets', ['userId' => $other->id, 'periodType' => 'YEAR',
            'period' => '2026', 'targetValue' => 0], $ah)->assertCreated();
        $this->getJson('/api/v1/targets', $this->bearer($sales))->assertOk()->assertJsonCount(1, 'data');
        $mine = $this->getJson('/api/v1/targets', $this->bearer($sales))->json('data.0');
        $this->putJson('/api/v1/targets/'.$mine['id'], ['version' => 1, 'targetValue' => 120000000], $ah)->assertOk()
            ->assertJsonPath('data.targetValue', '120000000.00');
        $this->putJson('/api/v1/targets/'.$mine['id'], ['version' => 1, 'targetValue' => 1], $ah)->assertConflict();
    }

    public function test_performance_matches_manual_reconciliation(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $sales = $this->user();
        $ah = $this->bearer($admin);
        $h = $this->bearer($sales);
        [$s2] = $this->fixture($h, $ah);
        $this->postJson('/api/v1/targets', ['userId' => $sales->id, 'periodType' => 'MONTH',
            'period' => '2026-10', 'targetValue' => 100000000], $ah)->assertCreated();

        $rows = $this->getJson('/api/v1/reports/performance?periodType=MONTH&period=2026-10', $ah)->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $one = collect($rows)->firstWhere('ownerUserId', $sales->id);
        $this->assertSame(3, $one['totalProspect']);
        $this->assertSame(2, $one['closingCount']);
        $this->assertSame('160000000.00', $one['actualValue']);
        $this->assertEquals(160.0, $one['achievement']);
        $this->assertEquals(66.67, $one['closingRate']);
        $two = collect($rows)->firstWhere('ownerUserId', $s2->id);
        $this->assertSame(1, $two['totalProspect']);
        $this->assertNull($two['targetValue']);
        $this->assertNull($two['achievement']);
        $this->assertEquals(0.0, $two['closingRate']);

        $denom = DB::table('prospects')->whereNull('deleted_at')->where('owner_user_id', $sales->id)
            ->whereBetween('entry_date', ['2026-10-01', '2026-10-31'])->distinct()->count('id');
        $this->assertSame($denom, $one['totalProspect']);
        $this->assertSame(3, $denom);
        $actual = DB::table('deals')->join('prospects', 'prospects.id', '=', 'deals.prospect_id')
            ->whereNull('prospects.deleted_at')->where('prospects.owner_user_id', $sales->id)
            ->where('deals.status', 'WON')->whereBetween('deals.closing_date', ['2026-10-01', '2026-10-31'])
            ->sum('deals.closing_value');
        $this->assertSame((float) $actual, (float) $one['actualValue']);

        $this->getJson('/api/v1/reports/performance?periodType=MONTH&period=2026-10', $h)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.ownerUserId', $sales->id);
        $this->getJson('/api/v1/reports/performance?periodType=MONTH&period=2026-10&owner='.$s2->id, $h)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.ownerUserId', $sales->id);
    }

    public function test_dashboard_rejects_bad_dates(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $ah = $this->bearer($admin);
        $this->getJson('/api/v1/dashboard?from=ngawur', $ah)->assertUnprocessable();
        $this->getJson('/api/v1/dashboard?from=2026-10-01&to=2026-10-31', $ah)->assertOk();
    }

    public function test_annual_report_and_dashboard(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $sales = $this->user();
        $ah = $this->bearer($admin);
        $h = $this->bearer($sales);
        $this->fixture($h, $ah);
        $p = $this->prospect($h, ['accountName' => 'PT Aktif Call', 'priority' => 'HOT']);
        $this->postJson('/api/v1/prospects/'.$p.'/activities', ['type' => 'CALL', 'answered' => true,
            'durationMinutes' => 3, 'activityAt' => '2026-10-06 10:00:00'], $h)->assertCreated();
        $this->postJson('/api/v1/prospects/'.$p.'/activities', ['type' => 'CALL', 'answered' => false,
            'activityAt' => '2026-10-07 10:00:00'], $h)->assertCreated();
        $this->postJson('/api/v1/prospects/'.$p.'/activities', ['type' => 'VISIT',
            'attendance' => 'HADIR', 'completion' => 'SELESAI', 'activityAt' => '2026-10-08 10:00:00'], $h)->assertCreated();
        $this->postJson('/api/v1/prospects/'.$p.'/follow-ups',
            ['scheduledAt' => now()->subDay()->toISOString(), 'description' => 'Kejar'], $h)->assertCreated();
        $this->postJson('/api/v1/prospects/'.$p.'/follow-ups',
            ['scheduledAt' => now()->startOfDay()->addHours(12)->toISOString(), 'description' => 'Hari ini'], $h)->assertCreated();

        $annual = $this->getJson('/api/v1/reports/annual?year=2026', $ah)->assertOk()->json('data');
        $this->assertSame(5, $annual['totals']['totalProspect']);
        $this->assertSame(2, $annual['totals']['closingCount']);
        $this->assertEquals(40.0, $annual['totals']['closingRate']);
        $this->assertSame(2, $annual['totals']['calls']);
        $this->assertSame(1, $annual['totals']['callsAnswered']);
        $this->assertSame(1, $annual['totals']['visits']);
        $this->assertCount(2, $annual['perSales']);

        $matrix = $this->getJson('/api/v1/reports/annual-matrix?fromYear=2025&toYear=2030', $ah)->assertOk()->json('data');
        $this->assertCount(6, $matrix['summaryYears']);
        $this->assertNotEmpty($matrix['managementNotes']);

        $dash = $this->getJson('/api/v1/dashboard', $h)->assertOk()->json('data');
        $this->assertSame(5, $dash['activeProspect']);
        $this->assertSame(1, $dash['followUpsOverdue']);
        $this->assertSame(1, $dash['followUpsToday']);
        $stages = collect($dash['byStage'])->pluck('count', 'stage');
        $this->assertSame(3, $stages['WON']);
        $this->assertSame(160000000.0, (float) $dash['closingValue']);
    }

    public function test_export_prospects_follows_filter_and_sanitizes(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $sales = $this->user();
        $ah = $this->bearer($admin);
        $h = $this->bearer($sales);
        $this->prospect($h, ['accountName' => '=CMD(1)+2', 'city' => 'Jakarta']);
        $this->prospect($h, ['accountName' => 'PT Bersih', 'city' => 'Bandung']);

        $id = $this->postJson('/api/v1/exports/prospects', ['city' => 'Jakarta'], $ah)->assertAccepted()->json('data.id');
        $this->work();
        $csv = $this->getJson('/api/v1/exports/'.$id.'/download', $ah)->assertOk()->streamedContent();
        $this->assertStringContainsString("'=CMD(1)+2", $csv);
        $this->assertStringNotContainsString('PT Bersih', $csv);
        $list = $this->getJson('/api/v1/prospects?city=Jakarta&pageSize=100', $ah)->json('totalItems');
        $this->assertSame($list, substr_count(trim($csv), "\n"));
        $this->assertSame('=CMD(1)+2', DB::table('prospects')->where('city', 'Jakarta')->value('account_name'));

        $this->postJson('/api/v1/exports/prospects', ['columns' => ['ngawur']], $ah)->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_COLUMNS');
    }

    public function test_export_isolation_expiry_and_revoke(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $one = $this->user(Role::SALES, 'sales.one');
        $two = $this->user(Role::SALES, 'sales.two');
        $h1 = $this->bearer($one);
        $h2 = $this->bearer($two);
        $this->prospect($h2, ['accountName' => 'PT Milik Dua']);

        $id = $this->postJson('/api/v1/exports/prospects', [], $h2)->assertAccepted()->json('data.id');
        $this->work();
        $this->getJson('/api/v1/exports/'.$id.'/download', $h2)->assertOk();
        $this->getJson('/api/v1/exports/'.$id, $h1)->assertForbidden();
        $this->getJson('/api/v1/exports/'.$id.'/download', $h1)->assertForbidden();

        DB::table('export_jobs')->where('id', $id)->update(['expires_at' => now()->subHour()]);
        $this->getJson('/api/v1/exports/'.$id.'/download', $h2)->assertStatus(410);

        DB::table('users')->where('id', $two->id)->update(['is_active' => false]);
        $this->getJson('/api/v1/exports/'.$id.'/download', $h2)->assertUnauthorized();
    }

    public function test_export_performance_roundtrip(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $sales = $this->user();
        $ah = $this->bearer($admin);
        $this->fixture($this->bearer($sales), $ah);
        $this->postJson('/api/v1/targets', ['userId' => $sales->id, 'periodType' => 'MONTH',
            'period' => '2026-10', 'targetValue' => 100000000], $ah)->assertCreated();

        $id = $this->postJson('/api/v1/exports/performance',
            ['periodType' => 'MONTH', 'period' => '2026-10'], $ah)->assertAccepted()->json('data.id');
        $this->work();
        $csv = $this->getJson('/api/v1/exports/'.$id.'/download', $ah)->assertOk()->streamedContent();
        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('closing_rate', $lines[0]);
        $api = $this->getJson('/api/v1/reports/performance?periodType=MONTH&period=2026-10', $ah)->json('data');
        $this->assertStringContainsString((string) collect($api)->firstWhere('ownerUserId', $sales->id)['closingRate'], $csv);
    }
}
