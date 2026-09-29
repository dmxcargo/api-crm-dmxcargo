<?php

namespace App\Sales\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ActivityRecord extends Model
{
    use HasUuids;

    protected $table = 'activities';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activity_at' => 'datetime', 'answered' => 'boolean', 'duration_minutes' => 'integer'];
    }
}
