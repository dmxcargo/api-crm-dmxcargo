<?php

namespace App\Sales\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ProspectRecord extends Model
{
    use HasUuids;

    protected $table = 'prospects';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entry_date' => 'date:Y-m-d', 'next_follow_up_at' => 'datetime',
            'archived_at' => 'datetime', 'deleted_at' => 'datetime', 'version' => 'integer'];
    }

    public function contacts()
    {
        return $this->hasMany(ContactRecord::class, 'prospect_id');
    }
}
