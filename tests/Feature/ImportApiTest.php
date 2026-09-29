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

final class ImportApiTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Synthetic-Pass-123!';

    private const HEADER = ['external_id', 'entry_date', 'account_name', 'pic_name', 'pic_position', 'phone',
        'email', 'city', 'province', 'industry', 'prospect_source', 'owner_username', 'stage', 'priority',
        'last_progress', 'next_follow_up_at', 'next_action', 'potential_value', 'quotation_value',
        'closing_date', 'closing_value', 'lost_reason', 'payment_status', 'customer_type', 'notes',
        'legacy_source_row', 'Status'];

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

    private function csv(array $rows): string
    {
        $lines = [implode(',', self::HEADER)];
        foreach ($rows as $row) {
            $cells = [];
            foreach (self::HEADER as $h) {
                $v = (string) ($row[$h] ?? '');
                $cells[] = str_contains($v, ',') || str_contains($v, '"') || str_contains($v, "\n")
                    ? '"'.str_replace('"', '""', $v).'"' : $v;
            }
            $lines[] = implode(',', $cells);
        }

        return implode("\n", $lines)."\n";
    }

    private function base(array $over = []): array
    {
        return ['entry_date' => '2026-08-01', 'account_name' => 'PT Contoh Logistik', 'phone' => '081234567890',
            'city' => 'Jakarta', 'prospect_source' => 'Google Ads', 'owner_username' => 'sales.one',
            'stage' => 'Follow Up', 'priority' => 'Warm', 'customer_type' => 'B2B', ...$over];
    }

    private function upload(array $h, array $rows): string
    {
        return $this->postJson('/api/v1/imports',
            ['fileName' => 'legacy.csv', 'content' => $this->csv($rows)], $h)->assertCreated()->json('data.id');
    }

    private function counts(string $id, array $h): array
    {
        return $this->getJson('/api/v1/imports/'.$id, $h)->assertOk()->json('data.reconciliation');
    }

    private function validate(string $id, array $h): void
    {
        $this->postJson('/api/v1/imports/'.$id.'/validate', [], $h)->assertAccepted();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0, '--timeout' => 600]);
    }

    private function commit(string $id, array $h): void
    {
        $this->postJson('/api/v1/imports/'.$id.'/commit', [], $h)->assertAccepted();
        $this->artisan('queue:work', ['--once' => true, '--sleep' => 0, '--timeout' => 600]);
    }

    public function test_sales_cannot_import(): void
    {
        $h = $this->bearer($this->user());
        $this->postJson('/api/v1/imports', ['fileName' => 'x.csv', 'content' => "a,b\n1,2\n"], $h)->assertForbidden();
    }

    public function test_empty_phone_create_fails_cleanly(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $this->user();
        $ah = $this->bearer($admin);
        $id = $this->upload($ah, [$this->base(['external_id' => 'EXT-EP', 'phone' => ''])]);
        $this->validate($id, $ah);
        $this->assertSame('WARNING', DB::table('import_rows')->where('job_id', $id)->value('status'));
        $row = DB::table('import_rows')->where('job_id', $id)->first();
        $this->postJson('/api/v1/imports/'.$id.'/review',
            ['reviews' => [['rowNumber' => 1, 'decision' => 'CREATE_NEW', 'version' => $row->version]]], $ah)->assertOk();
        $this->commit($id, $ah);
        $this->assertSame(1, $this->counts($id, $ah)['failed']);
        $result = DB::table('import_rows')->where('job_id', $id)->value('result');
        $this->assertStringStartsWith('INVALID_PHONE:', $result);
    }

    public function test_upload_validate_review_commit_reconciles(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $sales = $this->user();
        $ah = $this->bearer($admin);
        $existing = $this->postJson('/api/v1/prospects', ['accountName' => 'PT Sudah Ada', 'phone' => '081299988877',
            'sourceCode' => 'GOOGLE_ADS', 'priority' => 'WARM', 'customerType' => 'B2B'], $this->bearer($sales))->assertCreated()->json('data.id');

        $id = $this->upload($ah, [
            $this->base(['external_id' => 'EXT-1', 'account_name' => 'PT Baru Jaya', 'phone' => '081211122233']),
            $this->base(['external_id' => 'EXT-2', 'phone' => '081299988877']),
            $this->base(['external_id' => 'EXT-3', 'entry_date' => '2206-05-01', 'phone' => '081244455566']),
            $this->base(['external_id' => 'EXT-4', 'owner_username' => 'hantu.tak.ada', 'phone' => '081277788899']),
            $this->base(['external_id' => 'EXT-5', 'account_name' => 'PT Hangat', 'city' => 'Bandung', 'Status' => 'Hot', 'stage' => '', 'priority' => '', 'phone' => '081200011122']),
        ]);
        $this->validate($id, $ah);
        fwrite(STDERR, "\nDBG5: ".json_encode(DB::table('import_rows')->where('job_id', $id)->orderBy('row_number')->get(['row_number', 'status', 'codes', 'normalized'])->map(fn ($r) => [$r->row_number, $r->status, $r->codes, json_decode($r->normalized, true)['stage'] ?? null])->all())."\n");
        $c = $this->counts($id, $ah);
        $this->assertSame(1, $c['VALID']);
        $this->assertSame(1, $c['WARNING']);
        $this->assertSame(2, $c['INVALID']);
        $this->assertSame(1, $c['DUPLICATE']);

        $rows = $this->getJson('/api/v1/imports/'.$id.'/rows?status=INVALID', $ah)->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $this->getJson('/api/v1/imports/'.$id.'/errors.csv', $ah)->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $warn = DB::table('import_rows')->where('job_id', $id)->where('status', 'WARNING')->first();
        $dupe = DB::table('import_rows')->where('job_id', $id)->where('status', 'DUPLICATE')->first();
        $invalids = DB::table('import_rows')->where('job_id', $id)->where('status', 'INVALID')->get();
        $reviews = [['rowNumber' => $warn->row_number, 'decision' => 'CREATE_NEW', 'version' => 1],
            ['rowNumber' => $dupe->row_number, 'decision' => 'UPDATE_EXISTING', 'version' => 1]];
        foreach ($invalids as $inv) {
            $reviews[] = ['rowNumber' => $inv->row_number, 'decision' => 'SKIP', 'version' => 1];
        }
        $this->postJson('/api/v1/imports/'.$id.'/review', ['reviews' => $reviews], $ah)->assertOk();
        $this->postJson('/api/v1/imports/'.$id.'/review',
            ['reviews' => [['rowNumber' => $warn->row_number, 'decision' => 'SKIP', 'version' => 1]]], $ah)
            ->assertStatus(409);

        $this->commit($id, $ah);
        $c = $this->counts($id, $ah);
        $this->assertSame(2, $c['inserted']);
        $this->assertSame(1, $c['updated']);
        $this->assertSame(2, $c['skipped']);
        $this->assertSame(0, $c['failed']);
        $this->assertSame(0, $c['pending']);
        $this->assertTrue($c['balanced']);
        $this->assertSame($existing, DB::table('import_rows')->where('job_id', $id)->where('status', 'IMPORTED')
            ->where('decision', 'UPDATE_EXISTING')->value('prospect_id'));
        $this->assertDatabaseHas('prospects', ['legacy_id' => 'EXT-1']);
    }

    public function test_invalid_rows_cannot_be_approved(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $this->user();
        $ah = $this->bearer($admin);
        $id = $this->upload($ah, [$this->base(['external_id' => 'EXT-X', 'account_name' => '', 'phone' => '081200000001'])]);
        $this->validate($id, $ah);
        $this->postJson('/api/v1/imports/'.$id.'/review',
            ['reviews' => [['rowNumber' => 1, 'decision' => 'CREATE_NEW', 'version' => 1]]], $ah)
            ->assertUnprocessable()->assertJsonPath('code', 'REVIEW_NOT_ALLOWED');
    }

    public function test_won_and_lost_imports_flow_through_deals(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $this->user();
        $ah = $this->bearer($admin);
        $id = $this->upload($ah, [
            $this->base(['external_id' => 'EXT-W', 'account_name' => 'PT Menang', 'phone' => '081233344455',
                'stage' => 'Won', 'closing_date' => '2026-08-20', 'closing_value' => '25000000', 'priority' => 'Hot']),
            $this->base(['external_id' => 'EXT-L', 'account_name' => 'PT Kalah', 'phone' => '081266677788',
                'stage' => 'Lost', 'lost_reason' => 'Harga tidak kompetitif']),
            $this->base(['external_id' => 'EXT-WB', 'account_name' => 'PT Menang Bodong', 'phone' => '081299900011', 'stage' => 'Won']),
        ]);
        $this->validate($id, $ah);
        $c = $this->counts($id, $ah);
        $this->assertSame(2, $c['VALID'] + $c['WARNING']);
        $this->assertSame(1, $c['INVALID']);
        $bad = DB::table('import_rows')->where('job_id', $id)->where('status', 'INVALID')->first();
        $this->postJson('/api/v1/imports/'.$id.'/review',
            ['reviews' => [['rowNumber' => $bad->row_number, 'decision' => 'SKIP', 'version' => 1]]], $ah)->assertOk();
        $this->commit($id, $ah);
        $c = $this->counts($id, $ah);
        $this->assertSame(2, $c['inserted']);
        $this->assertTrue($c['balanced']);
        $wonId = DB::table('prospects')->where('legacy_id', 'EXT-W')->value('id');
        $this->assertDatabaseHas('deals', ['prospect_id' => $wonId, 'status' => 'WON']);
        $this->assertDatabaseHas('prospects', ['legacy_id' => 'EXT-L', 'stage' => 'LOST']);
    }

    public function test_won_matching_existing_customer_needs_explicit_new(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $this->user();
        $ah = $this->bearer($admin);
        $this->postJson('/api/v1/customers', ['accountName' => 'PT Pelanggan Lama', 'phone' => '081277700011'], $ah)->assertCreated();
        $id = $this->upload($ah, [$this->base(['external_id' => 'EXT-WC', 'account_name' => 'PT Pelanggan Lama',
            'phone' => '081277700011', 'stage' => 'Won', 'priority' => 'Hot',
            'closing_date' => '2026-08-20', 'closing_value' => '5000000'])]);
        $this->validate($id, $ah);
        $this->assertSame(1, $this->counts($id, $ah)['DUPLICATE']);
        $row = DB::table('import_rows')->where('job_id', $id)->first();
        $this->assertContains('CUSTOMER_CHOICE_REQUIRED', json_decode($row->codes, true));
        $this->postJson('/api/v1/imports/'.$id.'/review',
            ['reviews' => [['rowNumber' => 1, 'decision' => 'CREATE_NEW', 'version' => (int) $row->version]]], $ah)->assertOk();
        $this->commit($id, $ah);
        $c = $this->counts($id, $ah);
        $this->assertSame(1, $c['inserted']);
        $this->assertTrue($c['balanced']);
        $this->assertSame(2, DB::table('customers')->where('phone_raw', '081277700011')->count());
    }

    public function test_reupload_and_recommit_do_not_duplicate(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $this->user();
        $ah = $this->bearer($admin);
        $rows = [$this->base(['external_id' => 'EXT-D', 'account_name' => 'PT Sekali', 'phone' => '081255566677'])];
        $id = $this->upload($ah, $rows);
        $this->assertFalse($this->getJson('/api/v1/imports/'.$id, $ah)->json('data.counters.duplicateFile'));
        $this->validate($id, $ah);
        $this->commit($id, $ah);
        $this->assertSame(1, $this->counts($id, $ah)['inserted']);
        $this->postJson('/api/v1/imports/'.$id.'/commit', [], $ah)->assertStatus(409);
        $this->postJson('/api/v1/imports/'.$id.'/cancel', [], $ah)->assertStatus(409);

        $id2 = $this->upload($ah, $rows);
        $this->assertTrue($this->getJson('/api/v1/imports/'.$id2, $ah)->json('data.counters.duplicateFile'));
        $this->validate($id2, $ah);
        $this->assertSame(1, $this->counts($id2, $ah)['DUPLICATE']);
        $this->commit($id2, $ah);
        $c2 = $this->counts($id2, $ah);
        $this->assertSame(0, $c2['inserted']);
        $this->assertSame(1, $c2['skipped']);
        $this->assertSame(1, DB::table('prospects')->where('legacy_id', 'EXT-D')->count());
    }

    public function test_cancel_stops_pending_batches(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $this->user();
        $ah = $this->bearer($admin);
        $id = $this->upload($ah, [$this->base(['external_id' => 'EXT-C', 'phone' => '081200000099'])]);
        $this->postJson('/api/v1/imports/'.$id.'/cancel', [], $ah)->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->postJson('/api/v1/imports/'.$id.'/validate', [], $ah)->assertStatus(409);
        $this->assertSame('CANCELLED', $this->getJson('/api/v1/imports/'.$id, $ah)->json('data.status'));
        $this->assertDatabaseMissing('prospects', ['legacy_id' => 'EXT-C']);
    }

    public function test_merge_selected_fields_updates_only_approved(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $sales = $this->user();
        $ah = $this->bearer($admin);
        $existing = $this->postJson('/api/v1/prospects', ['accountName' => 'PT Lama', 'phone' => '081288899900',
            'sourceCode' => 'GOOGLE_ADS', 'priority' => 'WARM', 'customerType' => 'B2B'], $this->bearer($sales))->assertCreated()->json('data.id');
        $id = $this->upload($ah, [$this->base(['account_name' => 'PT Lama Diubah', 'phone' => '081288899900',
            'notes' => 'Catatan import', 'city' => 'Bandung'])]);
        $this->validate($id, $ah);
        $this->assertSame(1, $this->counts($id, $ah)['DUPLICATE']);
        $this->postJson('/api/v1/imports/'.$id.'/review', ['reviews' => [
            ['rowNumber' => 1, 'decision' => 'MERGE_SELECTED_FIELDS', 'approvedFields' => ['notes'], 'version' => 1],
        ]], $ah)->assertOk();
        $this->commit($id, $ah);
        $c = $this->counts($id, $ah);
        $this->assertSame(1, $c['updated']);
        $this->assertTrue($c['balanced']);
        $this->getJson('/api/v1/prospects/'.$existing, $ah)->assertOk()
            ->assertJsonPath('data.accountName', 'PT Lama')
            ->assertJsonPath('data.notes', 'Catatan import');
    }

    public function test_fixture_4409_rows_completes(): void
    {
        $admin = $this->user(Role::ADMIN, 'admin.one');
        $this->user();
        $this->user(Role::SALES, 'sales.two');
        $ah = $this->bearer($admin);
        mt_srand(4409);
        $rows = [];
        for ($i = 1; $i <= 4409; $i++) {
            $kind = $i % 20;
            $row = $this->base(['external_id' => 'FIX-'.$i, 'account_name' => 'PT Fixture '.$i,
                'phone' => '0813'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'owner_username' => $i % 2 ? 'sales.one' : 'sales.two',
                'prospect_source' => $i % 3 ? 'Google Ads' : 'REKOMENDASI']);
            if ($kind === 0) {
                $row['entry_date'] = '2206-01-01';
            } elseif ($kind === 1) {
                $row['owner_username'] = 'hantu.'.$i;
            } elseif ($kind === 2) {
                $row['phone'] = '0813'.str_pad((string) ($i - 2), 8, '0', STR_PAD_LEFT);
            } elseif ($kind === 3) {
                $row['Status'] = 'Cold';
                $row['priority'] = '';
            } elseif ($kind === 4) {
                $row['potential_value'] = 'Rp tidak-valid';
            }
            $rows[] = $row;
        }
        $id = $this->upload($ah, $rows);
        $this->validate($id, $ah);
        $before = $this->counts($id, $ah);
        $this->assertSame(4409, $before['totalRows']);
        $this->assertGreaterThan(0, $before['INVALID']);
        $decisions = [];
        foreach (DB::table('import_rows')->where('job_id', $id)->whereIn('status', ['WARNING', 'INVALID', 'DUPLICATE'])->get() as $r) {
            $decisions[] = ['rowNumber' => $r->row_number,
                'decision' => $r->status === 'WARNING' ? 'CREATE_NEW' : 'SKIP', 'version' => 1];
        }
        $rr = $this->postJson('/api/v1/imports/'.$id.'/review', ['reviews' => $decisions], $ah);
        if ($rr->status() !== 200) {
            fwrite(STDERR, "\nFLAKE-CTX job=".json_encode(DB::table('import_jobs')->where('id', $id)->first(['status', 'total_rows'])).' resp='.$rr->getContent()."\n");
        }
        $rr->assertOk();
        $this->commit($id, $ah);
        $c = $this->counts($id, $ah);
        $this->assertSame(0, $c['pending']);
        $this->assertTrue($c['balanced']);
        $this->assertSame(4409, $c['inserted'] + $c['updated'] + $c['skipped'] + $c['failed']);
    }
}
