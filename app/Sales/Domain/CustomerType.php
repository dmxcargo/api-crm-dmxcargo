<?php

namespace App\Sales\Domain;

enum CustomerType: string
{
    case B2B = 'B2B';
    case B2C = 'B2C';
}
