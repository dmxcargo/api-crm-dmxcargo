<?php

namespace App\Sales\Domain;

enum ProspectStage: string
{
    case NEW = 'NEW';
    case FOLLOW_UP = 'FOLLOW_UP';
    case OPPORTUNITY = 'OPPORTUNITY';
    case QUOTATION = 'QUOTATION';
    case NEGOTIATION = 'NEGOTIATION';
    case CLOSING = 'CLOSING';
    case WON = 'WON';
    case LOST = 'LOST';
    case MAINTENANCE = 'MAINTENANCE';
}
