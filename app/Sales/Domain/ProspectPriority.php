<?php

namespace App\Sales\Domain;

enum ProspectPriority: string
{
    case HOT = 'HOT';
    case WARM = 'WARM';
    case COLD = 'COLD';
}
