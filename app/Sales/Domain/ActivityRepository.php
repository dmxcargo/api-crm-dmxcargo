<?php

namespace App\Sales\Domain;

interface ActivityRepository
{
    public function save(Activity $activity): void;
}
