<?php

namespace App\Sales\Domain;

enum ExportType: string
{
    case PROSPECT = 'PROSPECT';
    case PERFORMANCE = 'PERFORMANCE';
}
