<?php

namespace App\Sales\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ImportJobRecord extends Model
{
    use HasUuids;

    protected $table = 'import_jobs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['counters' => 'array', 'version' => 'integer'];
    }
}
