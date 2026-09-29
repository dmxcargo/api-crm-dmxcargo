<?php

namespace App\Sales\Domain;

enum ImportJobStatus: string
{
    case DRAFT = 'DRAFT';
    case VALIDATING = 'VALIDATING';
    case READY = 'READY';
    case COMMITTING = 'COMMITTING';
    case DONE = 'DONE';
    case CANCELLED = 'CANCELLED';
}
