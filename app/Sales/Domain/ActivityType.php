<?php

namespace App\Sales\Domain;

enum ActivityType: string
{
    case CALL = 'CALL';
    case WHATSAPP = 'WHATSAPP';
    case EMAIL = 'EMAIL';
    case MEETING = 'MEETING';
    case VISIT = 'VISIT';
    case NOTE = 'NOTE';
}
