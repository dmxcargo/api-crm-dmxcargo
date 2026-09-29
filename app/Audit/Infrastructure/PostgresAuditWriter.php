<?php

namespace App\Audit\Infrastructure;

use App\Audit\Application\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PostgresAuditWriter implements AuditWriter
{
    public function record(string $action, ?string $actorId, ?string $entityId, array $before = [], array $after = [], string $entityType = 'user'): void
    {
        $allow = array_flip(['role', 'isActive', 'version', 'changedFields', 'stage', 'ownerUserId', 'customerType', 'archived',
            'type', 'followUpId', 'scheduledAt', 'status', 'quotationValue', 'quotationNumber', 'potentialValue',
            'paymentStatus', 'closingValue', 'customerId', 'lostReasonCode', 'accountName', 'contactId', 'prospectId',
            'closingOwnerId', 'active', 'repeat']);
        $sanitize = function (array $data) use ($allow): array {
            $data = array_intersect_key($data, $allow);
            if (isset($data['changedFields'])) {
                $data['changedFields'] = array_values(array_intersect($data['changedFields'], ['name', 'username', 'email', 'role', 'isActive', 'password',
                    'accountName', 'city', 'province', 'picName', 'picPosition', 'phone', 'industryCode', 'sourceCode',
                    'priority', 'paymentStatus', 'quotationValue', 'quotationNumber', 'potentialValue', 'customerType']));
            }

            return $data;
        };
        $request = app()->bound('request') ? request() : null;
        $version = $request?->header('X-Client-Version');
        // Simpan versi klien hanya bila format x.y.z; selainnya buang.
        $version = is_string($version) && preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}$/D', $version) ? $version : null;
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'actor_user_id' => $actorId, 'action' => $action,
            'entity_type' => $entityType, 'entity_id' => $entityId, 'before_data' => json_encode($sanitize($before)),
            'after_data' => json_encode($sanitize($after)), 'ip_address' => $request?->ip(), 'client_version' => $version,
            'trace_id' => $request?->attributes->get('traceId'), 'created_at' => now()]);
    }
}
