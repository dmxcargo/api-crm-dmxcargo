<?php

namespace App\Sales\Domain;

enum ImportDecision: string
{
    case SKIP = 'SKIP';
    case CREATE_NEW = 'CREATE_NEW';
    case UPDATE_EXISTING = 'UPDATE_EXISTING';
    case MERGE_SELECTED_FIELDS = 'MERGE_SELECTED_FIELDS';
}
