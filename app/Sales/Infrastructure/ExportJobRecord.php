<?php

namespace App\Sales\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ExportJobRecord extends Model
{
    use HasUuids;

    protected $table = 'export_jobs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'columns' => 'array', 'expires_at' => 'datetime', 'version' => 'integer'];
    }
}
