<?php

namespace App\Sales\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class DealRecord extends Model
{
    use HasUuids;

    protected $table = 'deals';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['closing_date' => 'date:Y-m-d', 'version' => 'integer'];
    }
}
