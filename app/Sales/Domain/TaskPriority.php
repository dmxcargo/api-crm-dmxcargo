<?php

namespace App\Sales\Domain;

enum TaskPriority: string
{
    case PENTING = 'PENTING';
    case SEDANG = 'SEDANG';
    case RENDAH = 'RENDAH';
}
