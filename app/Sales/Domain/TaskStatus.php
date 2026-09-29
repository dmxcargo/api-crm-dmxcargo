<?php

namespace App\Sales\Domain;

enum TaskStatus: string
{
    case BELUM_DIMULAI = 'BELUM_DIMULAI';
    case BERJALAN = 'BERJALAN';
    case SELESAI = 'SELESAI';
    case BATAL = 'BATAL';
}
