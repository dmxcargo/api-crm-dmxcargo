<?php

namespace App\Sales\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ArchiveJobRecord extends Model
{
    use HasUuids;

    protected $table = 'archive_jobs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'manifest' => 'array', 'second_copy_verified' => 'boolean',
            'purged' => 'boolean', 'version' => 'integer'];
    }
}
