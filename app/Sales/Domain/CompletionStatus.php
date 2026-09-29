<?php

namespace App\Sales\Domain;

enum CompletionStatus: string
{
    case SELESAI = 'SELESAI';
    case BATAL = 'BATAL';
    case TERJADWAL = 'TERJADWAL';
}
