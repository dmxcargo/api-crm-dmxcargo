<?php

namespace App\Sales\Domain;

enum DealStatus: string
{
    case OPEN = 'OPEN';
    case WON = 'WON';
    case LOST = 'LOST';
}
