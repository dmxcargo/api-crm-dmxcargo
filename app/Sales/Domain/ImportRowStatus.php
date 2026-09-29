<?php

namespace App\Sales\Domain;

enum ImportRowStatus: string
{
    case VALID = 'VALID';
    case WARNING = 'WARNING';
    case INVALID = 'INVALID';
    case DUPLICATE = 'DUPLICATE';
    case IMPORTED = 'IMPORTED';
    case SKIPPED = 'SKIPPED';
}
