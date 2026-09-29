<?php

namespace App\Sales\Domain;

enum ExportStatus: string
{
    case QUEUED = 'QUEUED';
    case PROCESSING = 'PROCESSING';
    case DONE = 'DONE';
    case FAILED = 'FAILED';
}
