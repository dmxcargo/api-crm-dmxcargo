<?php

return ['token_expiration_minutes' => max(1, (int) env('TOKEN_EXPIRATION_MINUTES', 480)),
    'business_timezone' => env('BUSINESS_TIMEZONE', 'Asia/Jakarta'),
    'idempotency_ttl_hours' => max(1, (int) env('IDEMPOTENCY_TTL_HOURS', 24)),
    'import_max_mb' => max(1, (int) env('IMPORT_MAX_MB', 20)),
    'import_max_rows' => max(100, (int) env('IMPORT_MAX_ROWS', 100000)),
    'import_batch' => max(10, (int) env('IMPORT_BATCH', 250)),
    'import_schema_version' => 1,
    'import_min_year' => 2000,
    'export_ttl_hours' => max(1, (int) env('EXPORT_TTL_HOURS', 24)),
    'archive_purge_enabled' => (bool) env('ARCHIVE_PURGE_ENABLED', false)];
