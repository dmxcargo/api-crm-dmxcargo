<?php

namespace App\Sales\Domain;

enum TargetPeriod: string
{
    case MONTH = 'MONTH';
    case YEAR = 'YEAR';
}
