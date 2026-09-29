<?php

namespace App\Sales\Domain;

enum AttendanceStatus: string
{
    case HADIR = 'HADIR';
    case TIDAK_HADIR = 'TIDAK_HADIR';
    case TERJADWAL = 'TERJADWAL';
}
