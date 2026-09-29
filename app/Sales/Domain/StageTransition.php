<?php

namespace App\Sales\Domain;

final class StageTransition
{
    private const ORDER = ['NEW', 'FOLLOW_UP', 'OPPORTUNITY', 'QUOTATION', 'NEGOTIATION', 'CLOSING'];

    private const TERMINAL = ['WON', 'LOST', 'MAINTENANCE'];

    public static function can(string $from, string $to, bool $privileged): bool
    {
        if ($from === $to) {
            return false;
        }
        if (in_array($to, ['WON', 'LOST'], true)) {
            return false;
        }
        if ($to === 'MAINTENANCE') {
            return $from === 'WON';
        }
        if (in_array($from, self::TERMINAL, true)) {
            return $privileged && $to === 'FOLLOW_UP';
        }

        return array_search($to, self::ORDER, true) > array_search($from, self::ORDER, true);
    }
}
