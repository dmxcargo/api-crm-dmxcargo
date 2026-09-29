<?php

namespace Database\Seeders;

use App\Identity\Domain\Account;
use App\Identity\Domain\AccountRepository;
use App\Identity\Domain\Role;
use App\Sales\Application\ManageActivities;
use App\Sales\Application\ManageProspects;
use App\Sales\Infrastructure\ProspectRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class UatSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->isLocal(), 403, 'Seeder UAT hanya untuk environment local.');
        $this->call(DatabaseSeeder::class);
        $password = Hash::make(env('UAT_SEED_PASSWORD', 'Uat-Pass-123456'));
        $actors = [];
        foreach (['uat.admin' => Role::ADMIN, 'uat.billing' => Role::BILLING,
            'uat.sales1' => Role::SALES, 'uat.sales2' => Role::SALES,
            'ambar' => Role::SALES, 'santi' => Role::SALES, 'eva' => Role::SALES] as $username => $role) {
            $actors[$username] = $this->user($username, $role, $password);
        }
        $prospects = app(ManageProspects::class);
        $this->prospect($prospects, $actors['uat.sales1'], 'UAT-01', 'PT Maju Jaya Logistik', '081234567890', 'HOT', '15000000');
        $this->prospect($prospects, $actors['uat.sales2'], 'UAT-02', 'PT Berkah Cargo', '082222222222', 'WARM', '25000000');
        $this->prospect($prospects, $actors['uat.sales1'], 'UAT-03', 'PT Sinar Abadi', '083333333333', 'COLD', '8000000');
        $first = ProspectRecord::where('legacy_id', 'UAT-01')->first();
        if ($first) {
            app(ManageActivities::class)->log($actors['uat.sales1'], $first->id,
                ['type' => 'CALL', 'answered' => true, 'durationMinutes' => 5, 'notes' => 'Data contoh UAT']);
        }
    }

    private function user(string $username, Role $role, string $password): string
    {
        $repo = app(AccountRepository::class);
        $existing = $repo->byLogin($username);
        $account = new Account($existing?->id ?? $repo->nextId(), $username, $username,
            $username.'@example.test', $password, $role);
        $repo->save($account);

        return $account->id;
    }

    private function prospect(ManageProspects $prospects, string $actorId, string $legacyId,
        string $account, string $phone, string $priority, string $potentialValue): void
    {
        if (ProspectRecord::where('legacy_id', $legacyId)->exists()) {
            return;
        }
        $prospects->create($actorId, ['accountName' => $account, 'phone' => $phone, 'sourceCode' => 'GOOGLE_ADS',
            'priority' => $priority, 'customerType' => 'B2B', 'legacyId' => $legacyId, 'city' => 'Jakarta',
            'potentialValue' => $potentialValue]);
    }
}
